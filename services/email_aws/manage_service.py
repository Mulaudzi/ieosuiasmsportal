"""Pack, validate and deploy only the isolated DISABLED email service in the owner account."""
from pathlib import Path
import argparse
import datetime
import email.utils
import hashlib
import importlib.util
import io
import json
import secrets
import shutil
import ssl
import subprocess
import sys
import time
import urllib.request
import zipfile
import copy

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
OUT = ROOT / '.sms-audit.local/email-aws-release-20261112'
VENDOR = ROOT / '.sms-audit.local/email-aws-tools/vendor'
sys.path.insert(0, str(VENDOR))
import boto3
import botocore.auth
from botocore.config import Config
from botocore.exceptions import ClientError
from infrastructure import PARAMETERS, template

REGION = 'eu-north-1'
STACK = 'ieosuia-email-service-v1'
TAGS = [{'Key': 'Application', 'Value': 'ieosuia-email-service'}, {'Key': 'Owner', 'Value': 'IEOSUIA'}]


def save(name, value):
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / name).write_text(json.dumps(value, indent=2) + '\n', encoding='utf-8')


def session():
    with urllib.request.urlopen(urllib.request.Request('https://sts.eu-north-1.amazonaws.com/', method='HEAD'), timeout=20, context=ssl.create_default_context()) as response:
        server_date = email.utils.parsedate_to_datetime(response.headers['Date'])
    offset = (server_date - datetime.datetime.now(datetime.timezone.utc)).total_seconds()
    if abs(offset) > 900:
        raise RuntimeError('Local clock needs diagnosis before AWS calls')
    def now(remove_tzinfo=True):
        value = datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(seconds=offset)
        return value.replace(tzinfo=None) if remove_tzinfo else value
    botocore.auth.get_current_datetime = now
    s = boto3.Session(region_name=REGION)
    identity = s.client('sts').get_caller_identity()
    if not identity['Account'].endswith('0326'):
        raise RuntimeError('Authenticated AWS account differs from owner-confirmed account')
    return s, identity['Account']


def sources():
    paths = list(HERE.glob('*.py')) + list((HERE / 'tests').glob('*.py')) + [HERE / 'requirements.txt']
    paths += [ROOT / p for p in ['api/domain/EmailAwsAccounting.php', 'api/domain/EmailAwsPolicy.php', 'api/domain/EmailAwsSignature.php', 'api/migrations/029_email_aws_bridge.php', 'api/migrations/030_email_reply_to_account_binding.php', 'api/domain/EmailReplyToBridge.php', 'api/services/EmailService.php', 'api/tests/run-email-reply-to.php', 'api/services/EmailAwsClient.php', 'api/email-aws.php', 'api/email-aws-accounting.php', 'api/tests/run-email-aws-accounting.php', 'api/tests/run-email-aws-mysql.php', 'scripts/test-email-aws-mysql.ps1', 'src/lib/email-campaigns.ts']]
    return {p.relative_to(ROOT).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest() for p in paths}


def package():
    data = io.BytesIO()
    with zipfile.ZipFile(data, 'w', zipfile.ZIP_DEFLATED) as archive:
        for name in ['store.py', 'protocol.py', 'engine.py', 'runtime.py', 'handler.py', 'presentation.py', 'moderation.py', 'assets.py', 'reply_to.py', 'telemetry.py', 'operations.py']:
            info = zipfile.ZipInfo(name, date_time=(2026, 11, 12, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, (HERE / name).read_bytes())
        for path in sorted(VENDOR.rglob('*')):
            if not path.is_file() or '__pycache__' in path.parts or path.suffix in ['.pyc', '.exe'] or '.dist-info' in str(path) or path.parts[len(VENDOR.parts)] == 'bin':
                continue
            info = zipfile.ZipInfo(path.relative_to(VENDOR).as_posix(), date_time=(2026, 11, 12, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, path.read_bytes())
        extra = ROOT / '.sms-audit.local/email-aws-tools/runtime-extra'
        for path in sorted(extra.rglob('*')):
            if not path.is_file() or '__pycache__' in path.parts or '.dist-info' in str(path) or path.suffix in ['.pyc', '.pyd', '.exe']:
                continue
            info = zipfile.ZipInfo(path.relative_to(extra).as_posix(), date_time=(2026, 11, 13, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, path.read_bytes())
    return data.getvalue()


def plan():
    OUT.mkdir(parents=True, exist_ok=True)
    before = sources()
    s, account = session()
    result = s.client('cloudformation').validate_template(TemplateBody=json.dumps(template()))
    code = package()
    if sources() != before:
        raise RuntimeError('Source changed during packaging; generate a new coherent plan')
    (OUT / 'service.zip').write_bytes(code)
    save('cloudformation.json', template())
    record = {'source_sha256': sources(), 'template_sha256': hashlib.sha256(json.dumps(template(), sort_keys=True).encode()).hexdigest(), 'code_sha256': hashlib.sha256(code).hexdigest(), 'archive_bytes': len(code), 'account': '********' + account[-4:], 'region': REGION, 'target_daily_recipients': 10000, 'initial_attempt_limit': 100, 'dispatch_enabled': False, 'worker_schedule_enabled': False, 'template_validated_by_aws': True, 'resources_created': False}
    save('plan.json', record)
    print(json.dumps({k: v for k, v in record.items() if k != 'source_sha256'}, indent=2))


def tests():
    OUT.mkdir(parents=True, exist_ok=True)
    tested_sources = sources()
    mysql = json.loads((OUT / 'mysql-acceptance.json').read_text(encoding='utf-8-sig'))
    if not mysql['passed'] or any(hashlib.sha256((ROOT / p).read_bytes()).hexdigest() != value for p, value in mysql['source_sha256'].items()):
        raise RuntimeError('Run scripts/test-email-aws-mysql.ps1 directly for the current bridge source')
    commands = [[sys.executable, str(HERE / 'verify.py')], ['C:/php8/php.exe', str(ROOT / 'api/tests/run-email-aws-accounting.php')], ['C:/php8/php.exe', str(ROOT / 'api/tests/run-email-reply-to.php')], ['node', str(ROOT / 'node_modules/typescript/bin/tsc'), '-p', 'tsconfig.app.json', '--noEmit']]
    results = []
    save('acceptance.json', {'passed': False, 'status': 'running', 'source_sha256': sources()})
    for index, command in enumerate(commands):
        p = subprocess.run(command, cwd=ROOT, capture_output=True)
        (OUT / ('acceptance-' + str(index) + '.log')).write_bytes(p.stdout + p.stderr)
        results.append({'index': index, 'exit_code': p.returncode})
        if p.returncode:
            save('acceptance.json', {'passed': False, 'checks': results, 'source_sha256': sources()})
            raise RuntimeError('Acceptance failed: ' + str(index) + '; inspect the saved log')
    if sources() != tested_sources:
        save('acceptance.json', {'passed': False, 'checks': results, 'source_changed_during_run': True})
        raise RuntimeError('Source changed during acceptance; run a coherent check')
    save('acceptance.json', {'passed': True, 'checks': results, 'source_sha256': tested_sources})
    print('Passed: AWS service tests, PHP accounting fixtures and TypeScript.')


def preflight(s):
    ses = s.client('sesv2')
    config = ses.get_configuration_set(ConfigurationSetName='ieosuia-portal-campaigns')
    if config.get('SendingOptions', {}).get('SendingEnabled') is not False:
        raise RuntimeError('Existing campaign configuration is not paused; inspect live workload')
    expected = {'Application': 'ieosuia-sms-portal', 'Purpose': 'consent-based-campaigns', 'Setup': 'ses-20261029'}
    account = s.client('sts').get_caller_identity()['Account']
    arn = f'arn:aws:ses:{REGION}:{account}:configuration-set/ieosuia-portal-campaigns'
    tags = {v['Key']: v['Value'] for v in ses.list_tags_for_resource(ResourceArn=arn).get('Tags', [])}
    if any(tags.get(k) != v for k, v in expected.items()):
        raise RuntimeError('SES configuration lacks the original ownership markers')
    events = ses.get_configuration_set_event_destinations(ConfigurationSetName='ieosuia-portal-campaigns').get('EventDestinations', [])
    if events:
        raise RuntimeError('Existing event destinations require explicit inspection before deployment')


def wait(cf, stack, token):
    previous = None
    for _ in range(100):
        state = cf.describe_stacks(StackName=stack)['Stacks'][0]['StackStatus']
        if state != previous:
            print('Email stack:', state, flush=True)
            previous = state
        if state in ['CREATE_COMPLETE', 'UPDATE_COMPLETE']:
            events = cf.describe_stack_events(StackName=stack)['StackEvents']
            completed = any(e.get('ClientRequestToken') == token and e['ResourceType'] == 'AWS::CloudFormation::Stack' and e['ResourceStatus'] == state for e in events)
            if completed:
                return cf.describe_stacks(StackName=stack)['Stacks'][0]
        if state.endswith('_FAILED') or 'ROLLBACK' in state:
            events = cf.describe_stack_events(StackName=stack)['StackEvents']
            save('stack-failure.json', [{'type': e['ResourceType'], 'logical_id': e['LogicalResourceId'], 'status': e['ResourceStatus'], 'reason': e.get('ResourceStatusReason', '')} for e in events if 'FAILED' in e['ResourceStatus']])
            raise RuntimeError('Email stack needs inspection; failure details saved')
        time.sleep(5)
    raise RuntimeError('Email stack is still running; inspect existing stack before another mutation')


def deploy():
    acceptance = json.loads((OUT / 'acceptance.json').read_text())
    record = json.loads((OUT / 'plan.json').read_text())
    code = (OUT / 'service.zip').read_bytes()
    if not acceptance['passed'] or acceptance['source_sha256'] != sources() or record['source_sha256'] != sources() or record['code_sha256'] != hashlib.sha256(code).hexdigest() or record['template_sha256'] != hashlib.sha256(json.dumps(template(), sort_keys=True).encode()).hexdigest():
        raise RuntimeError('Source/package differs from its accepted plan')
    if (OUT / 'deploy-started.json').exists():
        raise RuntimeError('A deployment attempt already exists; inspect and resume its exact state')
    s, account = session()
    cf = s.client('cloudformation')
    try:
        cf.describe_stacks(StackName=STACK)
        raise RuntimeError('Existing named stack requires a scoped update plan')
    except ClientError as error:
        if error.response['Error']['Code'] != 'ValidationError' or 'does not exist' not in error.response['Error'].get('Message', ''):
            raise
    preflight(s)
    save('deploy-started.json', {'account': '********' + account[-4:], 'region': REGION, 'stack': STACK, 'code_sha256': record['code_sha256'], 'dispatch_enabled': False, 'emails_sent': False})
    bucket = 'ieosuia-email-code-' + account + '-' + REGION
    s3 = s.client('s3')
    try:
        s3.head_bucket(Bucket=bucket)
        tags = s3.get_bucket_tagging(Bucket=bucket)['TagSet']
        if {v['Key']: v['Value'] for v in tags} != {v['Key']: v['Value'] for v in TAGS}:
            raise RuntimeError('Existing code bucket lacks expected ownership')
    except ClientError as error:
        if error.response['Error']['Code'] not in ['404', 'NoSuchBucket']:
            raise
        s3.create_bucket(Bucket=bucket, CreateBucketConfiguration={'LocationConstraint': REGION})
        s3.put_bucket_tagging(Bucket=bucket, Tagging={'TagSet': TAGS})
    s3.put_public_access_block(Bucket=bucket, PublicAccessBlockConfiguration={k: True for k in ['BlockPublicAcls', 'IgnorePublicAcls', 'BlockPublicPolicy', 'RestrictPublicBuckets']})
    s3.put_bucket_encryption(Bucket=bucket, ServerSideEncryptionConfiguration={'Rules': [{'ApplyServerSideEncryptionByDefault': {'SSEAlgorithm': 'AES256'}}]})
    key = 'releases/' + record['code_sha256'] + '.zip'
    s3.put_object(Bucket=bucket, Key=key, Body=code, ContentType='application/zip', ServerSideEncryption='AES256')
    remote = s3.get_object(Bucket=bucket, Key=key)['Body'].read()
    if hashlib.sha256(remote).hexdigest() != record['code_sha256']:
        raise RuntimeError('Code archive readback failed')
    ssm = s.client('ssm')
    for name, parameter in PARAMETERS.items():
        try:
            ssm.get_parameter(Name=parameter)
            raise RuntimeError('Existing service key must be inspected instead of replaced: ' + name)
        except ClientError as error:
            if error.response['Error']['Code'] != 'ParameterNotFound':
                raise
        value = {'v1' if name == 'portal' else 'key': secrets.token_hex(32)}
        ssm.put_parameter(Name=parameter, Type='SecureString', Tier='Standard', Value=json.dumps(value), Overwrite=False, Tags=TAGS)
    params = [{'ParameterKey': 'CodeBucket', 'ParameterValue': bucket}, {'ParameterKey': 'CodeKey', 'ParameterValue': key}]
    result = cf.create_stack(StackName=STACK, TemplateBody=json.dumps(template()), Parameters=params, Capabilities=['CAPABILITY_IAM'], Tags=TAGS, ClientRequestToken='ieosuia-email-' + record['code_sha256'][:32], OnFailure='DO_NOTHING')
    save('stack-created.json', {'stack_id': result['StackId'], 'code_sha256': record['code_sha256']})
    stack = wait(cf, result['StackId'], 'ieosuia-email-' + record['code_sha256'][:32])
    outputs = {v['OutputKey']: v['OutputValue'] for v in stack.get('Outputs', [])}
    public_url = outputs['PublicUrl'].rstrip('/')
    params.append({'ParameterKey': 'PublicUrl', 'ParameterValue': public_url})
    cf.update_stack(StackName=STACK, UsePreviousTemplate=True, Parameters=params, Capabilities=['CAPABILITY_IAM'], ClientRequestToken='ieosuia-email-url-' + record['code_sha256'][:32])
    stack = wait(cf, result['StackId'], 'ieosuia-email-url-' + record['code_sha256'][:32])
    save('deployed.json', {'stack_id': result['StackId'], 'public_url': public_url, 'outputs': outputs, 'dispatch_enabled': False, 'worker_schedule_enabled': False, 'portal_activated': False, 'production_access_requested': False, 'emails_sent': False})
    print('AWS email service deployed with dispatch disabled:', public_url, flush=True)


def update_disabled():
    acceptance = json.loads((OUT / 'acceptance.json').read_text())
    record = json.loads((OUT / 'plan.json').read_text())
    deployed = json.loads((OUT / 'deployed.json').read_text())
    code = (OUT / 'service.zip').read_bytes()
    if not acceptance['passed'] or acceptance['source_sha256'] != sources() or record['source_sha256'] != sources() or record['code_sha256'] != hashlib.sha256(code).hexdigest():
        raise RuntimeError('Current accepted source/package required for the disabled update')
    s, account = session()
    cf = s.client('cloudformation')
    stack = cf.describe_stacks(StackName=STACK)['Stacks'][0]
    if stack['StackId'] != deployed['stack_id'] or stack['StackStatus'] not in ['CREATE_COMPLETE', 'UPDATE_COMPLETE']:
        raise RuntimeError('Inspect existing deployment state before an update')
    current_template = cf.get_template(StackName=STACK, TemplateStage='Original')['TemplateBody']
    if isinstance(current_template, str):
        current_template = json.loads(current_template)
    change_template = current_template != template()
    if change_template:
        # Permit only the diagnosed, table-scoped transactional item permissions repair.
        allowed = copy.deepcopy(current_template)
        for name in ['ApiRole', 'WorkerRole', 'FeedbackRole']:
            statements = allowed['Resources'][name]['Properties']['Policies'][0]['PolicyDocument']['Statement']
            expected = {'Effect': 'Allow', 'Action': ['dynamodb:GetItem', 'dynamodb:Query', 'dynamodb:TransactWriteItems'], 'Resource': {'Fn::GetAtt': ['State', 'Arn']}}
            if statements[0] != expected:
                raise RuntimeError('Unexpected prior DynamoDB policy; inspect infrastructure changes')
            statements[:1] = template()['Resources'][name]['Properties']['Policies'][0]['PolicyDocument']['Statement'][:2]
            if name == 'WorkerRole':
                send = [v for v in statements if v['Action'] == 'ses:SendEmail']
                expected_send = {'Effect': 'Allow', 'Action': 'ses:SendEmail', 'Resource': {'Fn::Sub': 'arn:${AWS::Partition}:ses:${AWS::Region}:${AWS::AccountId}:identity/campaigns.ieosuia.com'}, 'Condition': {'StringEquals': {'ses:FromAddress': 'messages@campaigns.ieosuia.com'}}}
                if send != [expected_send]:
                    raise RuntimeError('Unexpected prior SES sending policy')
                send[0]['Resource'] = template()['Resources']['WorkerRole']['Properties']['Policies'][0]['PolicyDocument']['Statement'][-1]['Resource']
        if allowed != template():
            raise RuntimeError('Infrastructure changes exceed the scoped transaction policy repair')
    for name in ['api', 'worker', 'feedback']:
        env = s.client('lambda').get_function_configuration(FunctionName='ieosuia-email-' + name + '-v1')['Environment']['Variables']
        if any(env[k] != 'false' for k in ['DISPATCH_ENABLED', 'FEEDBACK_VERIFIED', 'ISOLATION_VERIFIED']):
            raise RuntimeError('Runtime gates changed; inspect before updating')
    if s.client('events').describe_rule(Name='ieosuia-email-worker-v1')['State'] != 'DISABLED':
        raise RuntimeError('Worker schedule changed; inspect before updating')
    bucket = 'ieosuia-email-code-' + account + '-' + REGION
    key = 'releases/' + record['code_sha256'] + '.zip'
    s3 = s.client('s3')
    s3.put_object(Bucket=bucket, Key=key, Body=code, ContentType='application/zip', ServerSideEncryption='AES256')
    if hashlib.sha256(s3.get_object(Bucket=bucket, Key=key)['Body'].read()).hexdigest() != record['code_sha256']:
        raise RuntimeError('Update archive readback failed')
    parameters = [{'ParameterKey': p['ParameterKey'], **({'ParameterValue': key} if p['ParameterKey'] == 'CodeKey' else {'UsePreviousValue': True})} for p in stack['Parameters']]
    token = 'ieosuia-email-update-' + hashlib.sha256((record['code_sha256'] + record['template_sha256']).encode()).hexdigest()[:32]
    body = {'TemplateBody': json.dumps(template())} if change_template else {'UsePreviousTemplate': True}
    cf.update_stack(StackName=STACK, **body, Parameters=parameters, Capabilities=['CAPABILITY_IAM'], ClientRequestToken=token)
    wait(cf, stack['StackId'], token)
    save('updated-code-' + record['code_sha256'][:12] + '-' + record['template_sha256'][:8] + '.json', {'code_sha256': record['code_sha256'], 'template_sha256': record['template_sha256'], 'source_sha256': sources(), 'dispatch_enabled': False, 'emails_sent': False})
    print('AWS scoped update complete; dispatch and schedule remain disabled.', flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['test', 'plan', 'deploy', 'update-disabled'])
    args = parser.parse_args()
    try:
        {'test': tests, 'plan': plan, 'deploy': deploy, 'update-disabled': update_disabled}[args.mode]()
    except ClientError as error:
        print(json.dumps({'ok': False, 'aws_error': error.response['Error']['Code'], 'emails_sent': False}))
        raise SystemExit(1)
