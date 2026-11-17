"""Offline operator-artifact tests. No SDK calls, credentials, cloud resources or real mail."""
import importlib.util,json,unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('prepare_ses',Path(__file__).with_name('prepare-email-ses.py'));module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
class PreparationTests(unittest.TestCase):
    def test_dedicated_smtp_scope(self):
        p=module.artifacts('123456789012')['smtp-sender-policy.json']['Statement'][0]
        self.assertEqual(p['Action'],['ses:SendRawEmail']);self.assertTrue(p['Resource'].endswith('identity/campaigns.ieosuia.com'));self.assertEqual(p['Condition']['StringEquals']['ses:FromAddress'],'messages@campaigns.ieosuia.com')
    def test_read_role_cannot_send_or_modify(self):
        p=module.artifacts('123456789012')['quota-reader-policy.json']
        for statement in p['Statement']:
            for action in statement['Action']:self.assertTrue(action.startswith(('ses:Get','sns:Get','sns:List')))
    def test_feedback_plan_is_pinned(self):
        r=module.artifacts('123456789012')['feedback-infrastructure-REVIEW-ONLY.json']['Resources']
        self.assertEqual(r['CampaignFeedbackTopic']['Properties']['SignatureVersion'],'2')
        statement=r['SesPublishPolicy']['Properties']['PolicyDocument']['Statement'][0]
        self.assertEqual(statement['Principal'],{'Service':'ses.amazonaws.com'});self.assertEqual(statement['Condition']['StringEquals']['AWS:SourceAccount'],'123456789012')
        self.assertTrue(statement['Condition']['ArnEquals']['AWS:SourceArn'].endswith('configuration-set/ieosuia-portal-campaigns'))
        self.assertFalse(r['HttpsSubscription']['Properties']['RawMessageDelivery']);self.assertIn('RedrivePolicy',r['HttpsSubscription']['Properties'])
        self.assertTrue(r['FeedbackDeadLetters']['Properties']['SqsManagedSseEnabled']);self.assertEqual(r['FeedbackDeadLetters']['DeletionPolicy'],'Retain')
    def test_no_sending_switch_or_credentials_resources(self):
        text=json.dumps(module.artifacts('123456789012')['feedback-infrastructure-REVIEW-ONLY.json'])
        self.assertNotIn('AWS::IAM::',text);self.assertNotIn('SendingEnabled',text);self.assertNotIn('AWS::SES::EmailIdentity',text)
    def test_signed_snapshot_is_guarded_and_stable(self):
        raw=module.quota_envelope({'account_id':'123456789012','observed_at':1},b'x'*32)
        self.assertTrue(raw.startswith(b'<?php exit; ?>\n'));self.assertEqual(raw,module.quota_envelope({'account_id':'123456789012','observed_at':1},b'x'*32))
        self.assertNotEqual(raw,module.quota_envelope({'account_id':'123456789012','observed_at':2},b'x'*32))
    def test_invalid_account_and_weak_secret(self):
        with self.assertRaises(ValueError):module.artifacts('bad-account')
        with self.assertRaises(ValueError):module.quota_envelope({},b'short')
if __name__=='__main__':unittest.main()
