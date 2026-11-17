"""Separate API, worker and SNS-to-SQS entrypoints with narrowly scoped runtime roles."""
import base64
import html
import json
import os
import time
import logging
import traceback
from urllib.parse import parse_qs
import boto3
from engine import Engine
from protocol import Denied, verify
from runtime import AccountingClient, SesProvider
from store import Conflict, DynamoStore
from assets import Assets, S3Files
from botocore.config import Config
from telemetry import emit


SECRET_CACHE = {}
def secret(name):
    cached = SECRET_CACHE.get(name)
    if cached and time.time() - cached[0] < 60:
        return cached[1]
    result = boto3.client('ssm').get_parameter(Name=os.environ[name], WithDecryption=True)
    value = json.loads(result['Parameter']['Value'])
    SECRET_CACHE[name] = (time.time(), value)
    return value


def build(accounting=False, portal=False):
    session = boto3.Session()
    settings = {'dispatch_enabled': os.environ.get('DISPATCH_ENABLED') == 'true', 'feedback_verified': os.environ.get('FEEDBACK_VERIFIED') == 'true', 'isolation_verified': os.environ.get('ISOLATION_VERIFIED') == 'true', 'daily_limit': min(10000, int(os.environ.get('DAILY_ATTEMPT_LIMIT', '100'))), 'configuration_set': 'ieosuia-portal-campaigns', 'from': 'messages@campaigns.ieosuia.com', 'aws_account': os.environ['AWS_ACCOUNT_ID'], 'topic_arn': os.environ['TOPIC_ARN'], 'public_url': os.environ['PUBLIC_URL'], 'preference_key': secret('PREFERENCE_SECRET')['key']}
    store = DynamoStore(session.client('dynamodb'), os.environ['STATE_TABLE'])
    settings.update(uploads_enabled=os.environ.get('UPLOADS_ENABLED') == 'true', asset_bucket=os.environ.get('ASSET_BUCKET', ''))
    files = S3Files(session.client('s3', region_name='eu-north-1', endpoint_url='https://s3.eu-north-1.amazonaws.com', config=Config(signature_version='s3v4', s3={'addressing_style': 'virtual'}, connect_timeout=5, read_timeout=15, retries={'total_max_attempts': 2})), settings['asset_bucket']) if settings['asset_bucket'] else None
    assets = Assets(store, files, settings)
    client = AccountingClient(secret('ACCOUNTING_SECRET')['key']) if accounting else None
    return Engine(store, client, SesProvider(session, settings), settings, assets=assets), secret('PORTAL_SECRET') if portal else None


def reply(status, data, content_type='application/json'):
    return {'statusCode': status, 'headers': {'Content-Type': content_type, 'Cache-Control': 'no-store, private', 'Referrer-Policy': 'no-referrer', 'X-Content-Type-Options': 'nosniff', 'Content-Security-Policy': "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"}, 'body': json.dumps(data) if content_type == 'application/json' else data}


def api(event, context):
    try:
        method = event.get('requestContext', {}).get('http', {}).get('method')
        path = event.get('rawPath', '')
        if path == '/health' and method == 'GET':
            return reply(200, {'service': 'ieosuia-campaigns', 'dispatch_enabled': os.environ.get('DISPATCH_ENABLED') == 'true', 'target_daily_limit': 10000})
        if path not in ['/preferences', '/service']:
            raise Denied('Route unavailable', 404)
        if path == '/service' and not all((event.get('headers') or {}).get(k) for k in ['x-email-key', 'x-email-time', 'x-email-nonce', 'x-email-signature']):
            raise Denied('Service authentication required', 401)
        engine, keys = build(portal=path == '/service')
        if path == '/preferences' and method in ['GET', 'POST']:
            q = parse_qs(event.get('rawQueryString', ''), keep_blank_values=True)
            if any(len(v) != 1 for v in q.values()):
                raise Denied('Ambiguous preference link', 400)
            value = lambda k: q.get(k, [''])[0]
            engine.preference(value('campaign'), value('recipient'), value('token'), method == 'POST', value('report') == '1')
            heading = 'You are unsubscribed' if method == 'POST' else ('Report unwanted email' if value('report') == '1' else 'Unsubscribe from this sender')
            action = '<p>Your preference has been saved. Email already in transit may still arrive.</p>' if method == 'POST' else '<form method="post"><button type="submit">Confirm</button></form>'
            return reply(200, '<!doctype html><html><head><title>Email preferences | IEOSUIA</title><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="font-family:Arial;background:#f3f6fb;color:#13213b;padding:32px"><main style="max-width:620px;margin:auto;background:white;padding:28px"><h2>IEOSUIA Messaging</h2><h1>' + html.escape(heading) + '</h1><p>This affects email campaigns from this sender, not account emails or SMS preferences.</p>' + action + '<p>Help: sms@ieosuia.com</p></main></body></html>', 'text/html; charset=utf-8')
        if path != '/service' or method != 'POST' or event.get('rawQueryString'):
            raise Denied('Route unavailable', 404)
        raw = event.get('body') or ''
        raw = base64.b64decode(raw, validate=True) if event.get('isBase64Encoded') else raw.encode()
        if len(raw) > 400000:
            raise Denied('Request exceeds service limit', 413)
        verify(engine.store, keys, 'email-campaign-api', path, raw, event.get('headers', {}), time.time())
        body = json.loads(raw)
        if not isinstance(body, dict) or not isinstance(body.get('data'), dict):
            raise Denied('Invalid campaign request', 400)
        result = engine.api(body.get('actor'), body.get('operation'), body['data'])
        return reply(200, {'success': True, **result})
    except Denied as error:
        return reply(error.status, {'success': False, 'message': str(error)})
    except Conflict:
        return reply(409, {'success': False, 'message': 'State changed; refresh before retrying the same operation.'})
    except Exception as error:
        emit('api', {'OperationalFailures': 1})
        # Diagnostic types/locations only: never log request bodies, recipients or secret values.
        logging.getLogger('ieosuia.email').error(json.dumps({'kind': 'campaign_api_exception', 'exception': type(error).__name__, 'aws_code': getattr(error, 'response', {}).get('Error', {}).get('Code'), 'frames': [{'function': f.name, 'line': f.lineno, 'file': os.path.basename(f.filename)} for f in traceback.extract_tb(error.__traceback__)]}))
        return reply(503, {'success': False, 'message': 'Campaign operation was not confirmed. Refresh before retrying.'})


def worker(event, context):
    engine, _ = build(accounting=True)
    started, results, campaigns = time.monotonic(), {}, set()
    for _, _, ref in engine.store.query('ACTIVE', limit=50):
        try:
            engine.reconcile(ref['campaign'])
        except Exception:
            results['reconciliation_attention'] = results.get('reconciliation_attention', 0) + 1
    if not engine.readiness()['ready']:
        emit('worker', {'OperationalFailures': results.get('reconciliation_attention', 0)})
        return {'paused': True, **results}
    for _, _, job in engine.store.query('READY', limit=25):
        if time.monotonic() - started > 35 or context.get_remaining_time_in_millis() < 20000:
            break
        try:
            result = engine.dispatch_one(job['campaign'], job['recipient'])
            if result == 'paced':
                time.sleep(1.05)
                result = engine.dispatch_one(job['campaign'], job['recipient'])
            results[result] = results.get(result, 0) + 1
            campaigns.add(job['campaign'])
            if result in ['held', 'quota', 'paused']:
                break
        except Conflict:
            results['state_changed'] = results.get('state_changed', 0) + 1
        except Denied as error:
            if error.status == 429:
                results['quota'] = results.get('quota', 0) + 1
                break
            results['attention'] = results.get('attention', 0) + 1
    for cid in campaigns:
        try:
            engine.reconcile(cid)
        except Exception:
            results['accounting_pending'] = results.get('accounting_pending', 0) + 1
    emit('worker', {'OperationalFailures': sum(results.get(k, 0) for k in ['held', 'attention', 'reconciliation_attention', 'accounting_pending'])})
    return results


def feedback(event, context):
    engine, _ = build(accounting=True)
    failed = []
    counters = {'OperationalFailures': 0, **{k: 0 for k in ['FeedbackAcceptedEvents', 'FeedbackDeliveredEvents', 'FeedbackBounceEvents', 'FeedbackComplaintEvents', 'FeedbackRejectEvents']}}
    simulator_counters = dict(counters)
    for record in event.get('Records', []):
        try:
            # Only the dedicated SNS topic can publish to this queue (enforced by its resource policy).
            if record.get('eventSource') != 'aws:sqs' or record.get('eventSourceARN') != os.environ['FEEDBACK_QUEUE_ARN'] or len(record.get('body', '').encode()) > 262144:
                raise Denied('Unexpected feedback source', 403)
            envelope = json.loads(record['body'])
            if envelope.get('Type') != 'Notification' or envelope.get('TopicArn') != os.environ['TOPIC_ARN']:
                raise Denied('Unexpected feedback topic', 403)
            # SES publishes this documented non-JSON setup notification when validating the destination.
            # Accept it only after the dedicated queue and SNS topic checks; no message/accounting effect.
            if envelope.get('Message', '').strip() == 'Successfully validated SNS topic for Amazon SES event publishing.':
                continue
            message = json.loads(envelope['Message'])
            engine.feedback(message)
            metric = {'Send': 'FeedbackAcceptedEvents', 'Delivery': 'FeedbackDeliveredEvents', 'Bounce': 'FeedbackBounceEvents', 'Complaint': 'FeedbackComplaintEvents', 'Reject': 'FeedbackRejectEvents'}.get(message.get('eventType'))
            if metric:
                destinations = message.get('mail', {}).get('destination', [])
                target = simulator_counters if len(destinations) == 1 and isinstance(destinations[0], str) and destinations[0].lower().rsplit('@', 1)[-1] == 'simulator.amazonses.com' else counters
                target[metric] += 1
        except Exception:
            failed.append({'itemIdentifier': record['messageId']})
    counters['OperationalFailures'] = len(failed)
    emit('feedback', counters)
    if any(simulator_counters.values()):
        emit('feedback-simulator', simulator_counters)
    return {'batchItemFailures': failed}


def asset_scan(event, context):
    session = boto3.Session()
    settings = {'aws_account': os.environ['AWS_ACCOUNT_ID'], 'asset_bucket': os.environ['ASSET_BUCKET'], 'scan_plan_arn': os.environ['SCAN_PLAN_ARN']}
    assets = Assets(DynamoStore(session.client('dynamodb'), os.environ['STATE_TABLE']), S3Files(session.client('s3'), settings['asset_bucket']), settings)
    try:
        state = assets.scan(event)
    except Exception:
        emit('file-scan', {'OperationalFailures': 1})
        raise
    emit('file-scan', {'OperationalFailures': 0, 'FileBlockedEvents': int(state == 'blocked')})
    return {'processed': True}
