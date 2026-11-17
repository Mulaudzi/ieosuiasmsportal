import concurrent.futures
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import threading
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from engine import Engine
from protocol import Denied, encoded, sign, verify
from store import Conflict, SqliteStore


class Provider:
    def __init__(self):
        self.sends, self.fail = [], False
        self.lock = threading.Lock()
        self.reasons = []

    def health(self):
        return {'reasons': self.reasons, 'max_24h': 20000, 'max_rate': 1.0, 'sent_24h': 0}

    def send(self, mime, recipient, tags):
        with self.lock:
            self.sends.append((mime, recipient, tags))
        if self.fail:
            raise TimeoutError()
        return 'ses-reference-' + tags['recipient']


class Accounting:
    def __init__(self):
        self.calls, self.settled = [], set()
        self.fail = False
        self.charged = 0

    def call(self, action, data):
        self.calls.append((action, data))
        if self.fail:
            raise TimeoutError()
        if action == 'attempt':
            return {'allowed': True}
        if action == 'settle':
            if data['recipient_id'] not in self.settled:
                self.settled.add(data['recipient_id'])
                self.charged += 7
            return {'settled': True}
        if action == 'release':
            return {'released': True}
        return {'reserved_cents': 0, 'charged_cents': self.charged}


class EngineTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.store = SqliteStore(Path(self.temp.name) / 'state.db')
        self.accounting, self.provider = Accounting(), Provider()
        self.now = 1800000000
        self.settings = {'dispatch_enabled': True, 'daily_limit': 10000, 'preference_key': 'p' * 64, 'public_url': 'https://mail.example.org', 'from': 'messages@campaigns.ieosuia.com', 'aws_account': '123456780326', 'configuration_set': 'ieosuia-portal-campaigns'}
        self.engine = Engine(self.store, self.accounting, self.provider, self.settings, lambda: self.now)
        self.owner = {'id': 1, 'email': 'business@example.org', 'role': 'user', 'verified': True, 'unit_cents': 7, 'available_cents': 10000}
        self.admin = {**self.owner, 'id': 90, 'email': 'admin@example.org', 'role': 'admin'}
        self.other = {**self.owner, 'id': 2, 'email': 'other@example.org'}
        self.engine.api(self.owner, 'save-sender', {'business_name': 'Example business', 'postal_address': '123 Example Street, South Africa'})
        self.engine.api(self.admin, 'review-sender', {'user_id': 1, 'expected_version': 1, 'decision': 'approved', 'daily_limit': 10000, 'evidence': 'Verified organization authority fixture reference.'})

    def tearDown(self):
        self.temp.cleanup()

    def campaign(self, addresses=None, key='unique-campaign-fixture-00001'):
        data = {'name': 'Member update', 'subject': 'Requested update', 'body': 'A permitted update with https://example.org/details', 'recipients': {'manual': addresses or ['recipient@example.org'], 'group_ids': [], 'contact_ids': []}, 'consent_confirmed': True, 'consent_evidence': 'Membership consent fixture, reference 42.', 'idempotency_key': key}
        c = self.engine.api(self.owner, 'create', data)['campaign']
        c = self.engine.api(self.admin, 'review-campaign', {'id': c['id'], 'fingerprint': c['fingerprint'], 'decision': 'approved', 'evidence': 'Consent, identity, message and destination reviewed.'})['campaign']
        proof = {k: c[k] for k in ['id', 'fingerprint', 'recipient_count', 'unit_cents', 'user_id']}
        c = self.engine.api(self.owner, 'queue', {'id': c['id'], 'reservation': proof})['campaign']
        return c, data

    def test_one_send_and_exactly_once_accounting(self):
        c, _ = self.campaign()
        rid = c['recipients'][0]['id']
        self.assertEqual(self.engine.dispatch_one(c['id'], rid), 'accepted')
        self.assertEqual(self.engine.dispatch_one(c['id'], rid), 'skipped')
        self.engine.settle(c['id'], rid)
        self.assertEqual(len(self.provider.sends), 1)
        self.assertEqual(self.accounting.charged, 7)
        mime = self.provider.sends[0][0]
        self.assertIn(b'List-Unsubscribe-Post: List-Unsubscribe=One-Click', mime)
        self.assertIn(b'Reply-To: business@example.org', mime)
        self.assertNotIn(b'Bcc:', mime)

    def test_provider_timeout_is_held_never_resent(self):
        c, _ = self.campaign()
        rid = c['recipients'][0]['id']
        self.provider.fail = True
        self.assertEqual(self.engine.dispatch_one(c['id'], rid), 'held')
        self.now += 400
        self.engine.reconcile(c['id'])
        self.assertEqual(self.engine.dispatch_one(c['id'], rid), 'skipped')
        self.assertEqual(len(self.provider.sends), 1)
        self.assertEqual(self.accounting.charged, 0)

    def test_accounting_outage_after_acceptance_never_resends(self):
        c, _ = self.campaign()
        rid = c['recipients'][0]['id']
        original = self.accounting.call
        def fail_settle(action, data):
            if action == 'settle':
                raise TimeoutError()
            return original(action, data)
        self.accounting.call = fail_settle
        self.engine.dispatch_one(c['id'], rid)
        self.assertEqual(self.engine.detail(self.owner, c['id'])['recipients'][0]['state'], 'accepted_pending')
        self.accounting.call = original
        self.engine.reconcile(c['id'])
        self.assertEqual(len(self.provider.sends), 1)
        self.assertEqual(self.accounting.charged, 7)

    def test_concurrent_workers_cannot_submit_twice(self):
        c, _ = self.campaign()
        rid = c['recipients'][0]['id']
        barrier = threading.Barrier(2)
        def run():
            barrier.wait()
            try:
                return self.engine.dispatch_one(c['id'], rid)
            except Conflict:
                return 'conflict'
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(lambda _: run(), range(2)))
        self.assertEqual(len(self.provider.sends), 1, results)

    def test_unsubscribe_get_read_only_post_suppresses(self):
        c, _ = self.campaign()
        rid = c['recipients'][0]['id']
        token = self.engine.token(c['id'], rid)
        self.engine.preference(c['id'], rid, token)
        self.assertEqual(self.store.query('A#1', 'SUP#'), [])
        self.engine.preference(c['id'], rid, token, True)
        self.assertEqual(self.engine.dispatch_one(c['id'], rid), 'cancelled')
        self.assertEqual(len(self.provider.sends), 0)

    def test_bad_token_and_foreign_account_denied(self):
        c, _ = self.campaign()
        with self.assertRaises(Denied):
            self.engine.preference(c['id'], c['recipients'][0]['id'], 'x' * 64, True)
        with self.assertRaises(Denied):
            self.engine.detail(self.other, c['id'])
        with self.assertRaises(Denied):
            self.engine.api(self.owner, 'overview', {})

    def test_cancellation_prevents_unissued_send(self):
        c, _ = self.campaign()
        self.engine.api(self.owner, 'cancel', {'id': c['id']})
        self.assertEqual(self.engine.dispatch_one(c['id'], c['recipients'][0]['id']), 'cancelled')
        self.assertEqual(len(self.provider.sends), 0)

    def test_self_approval_and_stale_sender_denied(self):
        with self.assertRaises(Denied):
            self.engine.api({**self.owner, 'role': 'admin'}, 'review-sender', {'user_id': 1})
        with self.assertRaises(Denied):
            self.engine.api(self.admin, 'review-sender', {'user_id': 1, 'expected_version': 1, 'decision': 'approved', 'daily_limit': 100, 'evidence': 'A sufficiently detailed reference.'})

    def test_replay_body_tamper_and_stale_signature(self):
        body = encoded({'example': 'request'})
        headers = sign('s' * 64, 'email-campaign-api', '/service', body, self.now)
        verify(self.store, {'v1': 's' * 64}, 'email-campaign-api', '/service', body, headers, self.now)
        with self.assertRaises(Denied):
            verify(self.store, {'v1': 's' * 64}, 'email-campaign-api', '/service', body, headers, self.now)
        with self.assertRaises(Denied):
            verify(self.store, {'v1': 's' * 64}, 'email-campaign-api', '/service', body + b' ', headers, self.now)
        with self.assertRaises(Denied):
            verify(self.store, {'v1': 's' * 64}, 'email-campaign-api', '/service', body, headers, self.now + 121)

    def test_idempotency_conflict_and_no_send_during_review(self):
        c, data = self.campaign()
        self.assertEqual(len(self.provider.sends), 0)
        duplicate = self.engine.api(self.owner, 'create', data)['campaign']
        self.assertEqual(c['id'], duplicate['id'])
        with self.assertRaises(Denied):
            self.engine.api(self.owner, 'create', {**data, 'body': 'Changed content'})

    def test_late_feedback_does_not_erase_complaint(self):
        c, _ = self.campaign()
        r = c['recipients'][0]
        self.engine.dispatch_one(c['id'], r['id'])
        event = {'eventType': 'Complaint', 'mail': {'source': self.settings['from'], 'sendingAccountId': self.settings['aws_account'], 'destination': [r['email']], 'messageId': 'ses-reference-' + r['id'], 'headers': [{'name': 'Message-ID', 'value': '<' + r['id'] + '@campaigns.ieosuia.com>'}], 'tags': {'campaign': [c['id']], 'recipient': [r['id']], 'owner': ['1'], 'ses:configuration-set': [self.settings['configuration_set']]}}}
        self.engine.feedback(event)
        self.engine.feedback({**event, 'eventType': 'Delivery'})
        profile = self.engine.api(self.owner, 'sender', {})['sender']
        self.assertEqual(profile['state'], 'suspended')
        self.assertEqual(self.engine.detail(self.owner, c['id'])['recipients'][0]['delivery_state'], 'complaint')
        self.assertEqual(self.accounting.charged, 7)
        with self.assertRaises(Denied):
            bad = json.loads(json.dumps(event))
            bad['mail']['destination'] = ['foreign@example.org']
            self.engine.feedback(bad)

    def test_signature_domain_separation(self):
        body = encoded({})
        headers = sign('s' * 64, 'email-accounting', '/api/email-aws-accounting.php', body, self.now)
        with self.assertRaises(Denied):
            verify(self.store, {'v1': 's' * 64}, 'email-campaign-api', '/service', body, headers, self.now)

    def test_disabled_dispatch_and_sandbox_fail_closed(self):
        self.settings['dispatch_enabled'] = False
        self.assertFalse(self.engine.api(self.owner, 'limits', {})['readiness']['ready'])
        self.settings['dispatch_enabled'] = True
        self.provider.reasons = ['Amazon SES production access is pending.']
        self.assertFalse(self.engine.api(self.owner, 'limits', {})['readiness']['ready'])


if __name__ == '__main__':
    unittest.main()
