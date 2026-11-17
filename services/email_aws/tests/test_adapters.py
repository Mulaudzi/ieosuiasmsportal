import json
from pathlib import Path
import sys
import subprocess
import base64
import unittest
from unittest.mock import patch, Mock
import boto3
from botocore.stub import Stubber
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from infrastructure import PARAMETERS, template
from runtime import SesProvider
from store import Conflict, DynamoStore
from protocol import canonical
import handler


class AdapterTests(unittest.TestCase):
    def session(self):
        return boto3.Session(aws_access_key_id='fixture', aws_secret_access_key='fixture', region_name='eu-north-1')

    def test_ses_exact_recipient_and_no_sdk_submission_retry(self):
        p = SesProvider(self.session(), {'from': 'messages@campaigns.ieosuia.com', 'configuration_set': 'ieosuia-portal-campaigns'})
        self.assertEqual(p.sender.meta.config.retries['total_max_attempts'], 1)
        with Stubber(p.sender) as stub:
            stub.add_response('send_email', {'MessageId': 'fixture-provider-id'}, {'FromEmailAddress': 'messages@campaigns.ieosuia.com', 'Destination': {'ToAddresses': ['one@example.org']}, 'Content': {'Raw': {'Data': b'MIME fixture'}}, 'ConfigurationSetName': 'ieosuia-portal-campaigns', 'EmailTags': [{'Name': 'recipient', 'Value': 'opaque'}]})
            self.assertEqual(p.send(b'MIME fixture', 'one@example.org', {'recipient': 'opaque'}), 'fixture-provider-id')
            stub.assert_no_pending_responses()

    def test_dynamodb_transactions_pin_all_read_versions(self):
        client = self.session().client('dynamodb')
        with Stubber(client) as stub:
            stub.add_response('transact_write_items', {}, {'TransactItems': [
                {'Put': {'TableName': 'fixture-table', 'Item': {'p': {'S': 'C'}, 'k': {'S': 'R'}, 'rev': {'N': '4'}, 'data': {'S': '{"state":"submitting"}'}}, 'ConditionExpression': 'rev=:r', 'ExpressionAttributeValues': {':r': {'N': '3'}}}},
                {'ConditionCheck': {'TableName': 'fixture-table', 'Key': {'p': {'S': 'C'}, 'k': {'S': 'META'}}, 'ConditionExpression': 'rev=:r', 'ExpressionAttributeValues': {':r': {'N': '8'}}}}
            ][::-1]})
            DynamoStore(client, 'fixture-table').commit({('C', 'R'): (3, {'state': 'submitting'})}, {('C', 'META'): 8})

    def test_dynamodb_conditional_conflict_not_generic_success(self):
        client = self.session().client('dynamodb')
        with Stubber(client) as stub:
            stub.add_client_error('transact_write_items', service_error_code='TransactionCanceledException', modeled_fields={'CancellationReasons': [{'Code': 'ConditionalCheckFailed'}]})
            with self.assertRaises(Conflict):
                DynamoStore(client, 'fixture-table').commit({('nonce', 'id'): (0, {'_ttl': 123})})

    def test_dynamodb_partition_query_omits_empty_key_value(self):
        client = self.session().client('dynamodb')
        with Stubber(client) as stub:
            stub.add_response('query', {'Items': []}, {'TableName': 'fixture-table', 'KeyConditionExpression': 'p=:p', 'ExpressionAttributeValues': {':p': {'S': 'ACTIVE'}}, 'ConsistentRead': True, 'ScanIndexForward': False, 'Limit': 50})
            self.assertEqual(DynamoStore(client, 'fixture-table').query('ACTIVE', limit=50), [])
            stub.assert_no_pending_responses()

    def test_unsigned_api_never_reads_secrets_or_state(self):
        event = {'rawPath': '/service', 'requestContext': {'http': {'method': 'POST'}}, 'headers': {}, 'body': '{}'}
        with patch.object(handler, 'build', side_effect=AssertionError('must not construct service')):
            self.assertEqual(handler.api(event, None)['statusCode'], 401)

    def test_public_health_never_reads_secrets(self):
        with patch.object(handler, 'build', side_effect=AssertionError('must not construct service')):
            reply = handler.api({'rawPath': '/health', 'requestContext': {'http': {'method': 'GET'}}}, None)
            self.assertEqual(reply['statusCode'], 200)
            self.assertFalse(json.loads(reply['body'])['dispatch_enabled'])

    def test_trusted_ses_destination_validation_notification_has_no_campaign_effect(self):
        engine = Mock()
        record = {'eventSource': 'aws:sqs', 'eventSourceARN': 'fixture-queue', 'messageId': 'setup-message', 'body': json.dumps({'Type': 'Notification', 'TopicArn': 'fixture-topic', 'Message': 'Successfully validated SNS topic for Amazon SES event publishing.'})}
        with patch.dict(handler.os.environ, {'FEEDBACK_QUEUE_ARN': 'fixture-queue', 'TOPIC_ARN': 'fixture-topic'}), patch.object(handler, 'build', return_value=(engine, None)):
            self.assertEqual(handler.feedback({'Records': [record]}, None), {'batchItemFailures': []})
            engine.feedback.assert_not_called()

    def test_validation_text_cannot_bypass_topic_binding_or_hide_unknown_payload(self):
        engine = Mock()
        for topic, message in [('foreign-topic', 'Successfully validated SNS topic for Amazon SES event publishing.'), ('fixture-topic', 'Unknown non-JSON payload')]:
            record = {'eventSource': 'aws:sqs', 'eventSourceARN': 'fixture-queue', 'messageId': 'untrusted-message', 'body': json.dumps({'Type': 'Notification', 'TopicArn': topic, 'Message': message})}
            with patch.dict(handler.os.environ, {'FEEDBACK_QUEUE_ARN': 'fixture-queue', 'TOPIC_ARN': 'fixture-topic'}), patch.object(handler, 'build', return_value=(engine, None)):
                self.assertEqual(handler.feedback({'Records': [record]}, None), {'batchItemFailures': [{'itemIdentifier': 'untrusted-message'}]})
                engine.feedback.assert_not_called()

    def test_infrastructure_cannot_start_dispatch(self):
        t = template()
        resources = t['Resources']
        for name in ['ApiFunction', 'WorkerFunction', 'FeedbackFunction']:
            env = resources[name]['Properties']['Environment']['Variables']
            self.assertEqual(env['DISPATCH_ENABLED'], 'false')
            self.assertEqual(env['FEEDBACK_VERIFIED'], {'Ref': 'FeedbackAccepted'})
            self.assertEqual(t['Parameters']['FeedbackAccepted']['Default'], 'false')
            self.assertEqual(env['DAILY_ATTEMPT_LIMIT'], '100')
        self.assertEqual(resources['WorkerSchedule']['Properties']['State'], 'DISABLED')
        self.assertEqual(resources['State']['Properties']['BillingMode'], 'PAY_PER_REQUEST')
        self.assertEqual(resources['FeedbackMapping']['Properties']['FunctionResponseTypes'], ['ReportBatchItemFailures'])

    def test_only_worker_role_can_submit_email(self):
        resources = template()['Resources']
        for name in ['ApiRole', 'FeedbackRole']:
            text = json.dumps(resources[name])
            self.assertNotIn('ses:Send', text)
        text = json.dumps(resources['WorkerRole'])
        self.assertIn('ses:SendEmail', text)
        self.assertIn('messages@campaigns.ieosuia.com', text)
        self.assertIn('configuration-set/ieosuia-portal-campaigns', text)
        self.assertNotIn('ses:*', text)
        self.assertNotIn('iam:', text)

    def test_feedback_queue_only_accepts_dedicated_sns(self):
        r = template()['Resources']
        statements = r['QueuePolicy']['Properties']['PolicyDocument']['Statement']
        self.assertEqual(statements[0]['Principal'], {'Service': 'sns.amazonaws.com'})
        self.assertEqual(statements[1]['Effect'], 'Deny')
        self.assertEqual(r['Subscription']['Properties']['RawMessageDelivery'], False)
        self.assertEqual(r['State']['DeletionPolicy'], 'Retain')

    def test_dynamodb_roles_authorize_transaction_item_operations_only_on_own_table(self):
        r = template()['Resources']
        for name in ['ApiRole', 'WorkerRole', 'FeedbackRole']:
            statements = r[name]['Properties']['Policies'][0]['PolicyDocument']['Statement']
            writes = statements[1]
            self.assertEqual(set(writes['Action']), {'dynamodb:PutItem', 'dynamodb:DeleteItem', 'dynamodb:ConditionCheckItem'})
            self.assertEqual(writes['Resource'], {'Fn::GetAtt': ['State', 'Arn']})
            self.assertEqual(writes['Condition'], {'StringEquals': {'dynamodb:EnclosingOperation': 'TransactWriteItems'}})

    def test_python_php_canonical_bytes_match(self):
        root = Path(__file__).resolve().parents[3]
        signature = (root / 'api/domain/EmailAwsSignature.php').as_posix()
        body = b'{"action":"status","data":{"example":"UTF8 \\u00e9"}}'
        code = 'require ' + json.dumps(signature) + '; echo EmailAwsSignature::canonical("POST","/api/email-aws-accounting.php","email-accounting","1800000000",str_repeat("a",40),base64_decode("' + base64.b64encode(body).decode() + '"));'
        reply = subprocess.run(['C:/php8/php.exe', '-r', code], capture_output=True, check=True)
        self.assertEqual(reply.stdout, canonical('POST', '/api/email-aws-accounting.php', 'email-accounting', '1800000000', 'a' * 40, body))


if __name__ == '__main__':
    unittest.main()
