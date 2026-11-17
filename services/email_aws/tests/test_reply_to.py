import concurrent.futures
import tempfile
import threading
import unittest
from pathlib import Path
from unittest.mock import patch
from engine import Engine
from protocol import Denied
from store import SqliteStore, Conflict
from test_engine import Accounting, Provider


class ReplyVerification(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.store=SqliteStore(Path(self.temp.name)/'state.db');self.now=1800000000
        self.provider=Provider();self.engine=Engine(self.store,Accounting(),self.provider,{'preference_key':'p'*64,'dispatch_enabled':True,'daily_limit':100,'public_url':'https://example.org','from':'messages@campaigns.ieosuia.com'},lambda:self.now)
        self.owner={'id':1,'email':'account@example.org','role':'user','verified':True,'unit_cents':7,'available_cents':1000};self.admin={**self.owner,'id':90,'role':'admin'}
    def tearDown(self):self.temp.cleanup()
    def call(self,op,data=None,actor=None):return self.engine.api(actor or self.owner,op,data or{})
    def request(self,address='replies@example.org'):
        return self.call('request-reply-to',{'email':address})['verification_delivery']
    def verify(self,delivery):
        self.call('reply-to-delivery',{'id':delivery['id'],'delivered':True})
        return self.call('verify-reply-to',{'id':delivery['id'],'code':delivery['code']})
    def sender(self,address='replies@example.org'):
        profile=self.call('save-sender',{'business_name':'Fixture business','postal_address':'123 Test Street, South Africa','reply_to':address})['sender']
        return self.call('review-sender',{'user_id':1,'expected_version':profile['version'],'decision':'approved','daily_limit':100,'evidence':'Independent synthetic business authorization reference.'},self.admin)['sender']
    def test_only_mailbox_code_grants_address_and_code_is_never_read_back(self):
        delivery=self.request();status=self.call('reply-to-status');self.assertNotIn('verification_delivery',status)
        self.assertNotIn('code_hash',str(status));self.assertEqual(status['addresses'],[])
        _,challenge=self.store.get(('A#1','REPLY-CHALLENGE'));self.assertNotEqual(challenge['code_hash'],delivery['code'])
        with self.assertRaises(Denied):self.call('verify-reply-to',{'id':delivery['id'],'code':delivery['code']})
        result=self.verify(delivery);self.assertEqual(result['addresses'][0]['email'],'replies@example.org');self.assertIsNone(result['pending'])
        with self.assertRaises(Denied):self.call('verify-reply-to',{'id':delivery['id'],'code':delivery['code']})
        self.assertEqual(self.provider.sends,[])
    def test_failed_delivery_and_expired_codes_do_not_verify(self):
        delivery=self.request();self.call('reply-to-delivery',{'id':delivery['id'],'delivered':False})
        with self.assertRaises(Denied):self.call('verify-reply-to',{'id':delivery['id'],'code':delivery['code']})
        self.now+=61;delivery=self.request();self.call('reply-to-delivery',{'id':delivery['id'],'delivered':True});self.now+=601
        with self.assertRaises(Denied):self.call('verify-reply-to',{'id':delivery['id'],'code':delivery['code']})
    def test_wrong_codes_lock_after_five_attempts(self):
        delivery=self.request();self.call('reply-to-delivery',{'id':delivery['id'],'delivered':True})
        wrong='000000'if delivery['code']!='000000'else'000001'
        for _ in range(5):
            with self.assertRaises(Denied):self.call('verify-reply-to',{'id':delivery['id'],'code':wrong})
        with self.assertRaises(Denied):self.call('verify-reply-to',{'id':delivery['id'],'code':delivery['code']})
        self.assertEqual(self.call('reply-to-status')['pending']['attempts_remaining'],0)
    def test_cross_account_and_changed_destination_cannot_verify(self):
        delivery=self.request();other={**self.owner,'id':2}
        with self.assertRaises(Denied):self.call('reply-to-delivery',{'id':delivery['id'],'delivered':True},other)
        with self.assertRaises(Denied):self.call('save-sender',{'business_name':'Business','postal_address':'123 Test Street','reply_to':'unverified@example.org'})
        self.verify(delivery)
        with self.assertRaises(Denied):self.call('save-sender',{'business_name':'Business','postal_address':'123 Test Street','reply_to':delivery['email']},other)
    def test_resend_cooldown_hourly_caps_and_replaced_code(self):
        old=self.request()
        with self.assertRaises(Denied):self.request()
        self.now+=61;new=self.request();self.call('reply-to-delivery',{'id':new['id'],'delivered':True})
        with self.assertRaises(Denied):self.call('verify-reply-to',{'id':old['id'],'code':old['code']})
        self.now+=61;self.request('third@example.org');self.now+=61
        with self.assertRaises(Denied):self.request('fourth@example.org')
    def test_leading_zero_code_and_concurrent_use_are_single_use(self):
        with patch('reply_to.secrets.randbelow',return_value=123):delivery=self.request()
        self.assertEqual(delivery['code'],'000123');self.call('reply-to-delivery',{'id':delivery['id'],'delivered':True});barrier=threading.Barrier(2)
        def run(_):
            barrier.wait()
            try:self.call('verify-reply-to',{'id':delivery['id'],'code':delivery['code']});return True
            except(Denied,Conflict):return False
        with concurrent.futures.ThreadPoolExecutor(max_workers=2)as pool:results=list(pool.map(run,range(2)))
        self.assertEqual(sum(results),1);self.assertEqual(len(self.call('reply-to-status')['addresses']),1)
    def test_verified_reply_is_pinned_and_revocation_stops_an_approved_sender(self):
        delivery=self.request();self.verify(delivery);profile=self.sender();self.assertTrue(self.engine.valid_reply(profile))
        self.assertEqual(profile['account_email'],self.owner['email']);self.assertEqual(profile['reply_to'],delivery['email'])
        self.call('remove-reply-to',{'email':delivery['email']});self.assertFalse(self.engine.valid_reply(profile));self.assertEqual(self.call('sender')['sender']['state'],'pending')
        self.now+=61;self.verify(self.request());self.assertFalse(self.engine.valid_reply(profile))
    def test_selected_reply_is_in_actual_mime_and_login_email_change_requires_review(self):
        delivery=self.request();self.verify(delivery);self.sender();data={'name':'Fixture','subject':'Requested update','body':'Requested information.','recipients':{'manual':['recipient@example.org']},'consent_confirmed':True,'consent_evidence':'Synthetic consent reference for local test.','idempotency_key':'reply-fixture-0000000001'}
        c=self.call('create',data)['campaign'];meta=self.store.get(('C#'+c['id'],'META'))[1];r=self.store.query('C#'+c['id'],'R#',1)[0][2]
        self.assertIn(b'Reply-To: replies@example.org',self.engine.mime(meta,r))
        with self.assertRaises(Denied):self.call('create',{**data,'idempotency_key':'reply-fixture-0000000002'},{**self.owner,'email':'new-login@example.org'})
