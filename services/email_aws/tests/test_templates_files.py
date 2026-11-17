import copy
import hashlib
import io
import tempfile
import unittest
from pathlib import Path
from email import policy
from email.parser import BytesParser
from PIL import Image
from pypdf import PdfWriter
from pypdf.generic import NameObject, DictionaryObject, TextStringObject
from assets import Assets, LIMITS, S3Files, inspect_file
from engine import Engine
from moderation import inspect
from presentation import DEFAULT, CATALOG, design, render
from protocol import Denied
from store import SqliteStore
from test_engine import Accounting, Provider


def png():
    out = io.BytesIO(); Image.new('RGB', (24, 24), '#1763D1').save(out, 'PNG'); return out.getvalue()


class Files:
    def __init__(self): self.data = {}
    def ticket(self, record): return {'url': 'https://private.example.org', 'headers': {}}
    def raw(self, record, version): return self.data[record['id']]
    def clean(self, record, raw):
        self.data[record['id']] = raw
        return {'clean_key': 'clean/1/' + record['id'], 'clean_version': 'immutable-version', 'clean_sha256': hashlib.sha256(raw).hexdigest(), 'clean_bytes': len(raw)}
    def bytes(self, value): return self.data[value['id']]
    def download(self, value): return 'https://private.example.org/' + value['id']


class TemplatesFiles(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.store = SqliteStore(Path(self.temp.name) / 'files.db')
        self.now = 1800000000; self.files = Files()
        self.settings = {'uploads_enabled': True, 'asset_bucket': 'private-files', 'aws_account': '123456780326', 'scan_plan_arn': 'arn:plan', 'dispatch_enabled': True, 'daily_limit': 100, 'preference_key': 'p' * 64, 'public_url': 'https://mail.example.org', 'from': 'messages@campaigns.ieosuia.com'}
        self.assets = Assets(self.store, self.files, self.settings, lambda: self.now)
        self.engine = Engine(self.store, Accounting(), Provider(), self.settings, lambda: self.now, self.assets)
        self.owner = {'id': 1, 'email': 'owner@example.org', 'role': 'user', 'verified': True, 'unit_cents': 7, 'available_cents': 10000}
        self.admin = {**self.owner, 'id': 90, 'role': 'admin'}
        self.engine.api(self.owner, 'save-sender', {'business_name': 'Example business', 'postal_address': '123 Example Street, South Africa'})
        self.engine.api(self.admin, 'review-sender', {'user_id': 1, 'expected_version': 1, 'decision': 'approved', 'daily_limit': 100, 'evidence': 'Verified organization authority fixture reference.'})

    def tearDown(self): self.temp.cleanup()

    def upload(self, purpose='attachment', ready=True):
        raw = png()
        value = self.assets.create(1, {'purpose': purpose, 'mime': 'image/png', 'name': 'example.png', 'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()})['file']
        self.files.data[value['id']] = raw
        if ready: self.assets.scan(self.event(value['id']))
        return value['id']

    def event(self, id, status='NO_THREATS_FOUND'):
        return {'source': 'aws.guardduty', 'detail-type': 'GuardDuty Malware Protection Object Scan Result', 'account': self.settings['aws_account'], 'region': 'eu-north-1', 'resources': ['arn:plan'], 'detail': {'scanStatus': 'COMPLETED', 's3ObjectDetails': {'bucketName': 'private-files', 'objectKey': 'quarantine/1/' + id, 'versionId': 'raw-version'}, 'scanResultDetails': {'scanResultStatus': status}}}

    def data(self, ids=None, style=None):
        return {'name': 'Member update', 'subject': 'Requested update', 'body': 'Your requested membership update.', 'design': style or dict(DEFAULT), 'attachment_ids': ids or [], 'recipients': {'manual': ['recipient@example.org']}, 'consent_confirmed': True, 'consent_evidence': 'Membership consent register reference 42.', 'idempotency_key': 'templates-fixture-00000001'}

    def test_layouts_escape_customer_values_preserve_footer_and_contrast(self):
        sender = {'business_name': '<script>business</script>', 'postal_address': '1 Street', 'reply_to': 'owner@example.org'}
        outputs = []
        for layout in CATALOG:
            value = render('<img src=x>', '<script>alert(1)</script>', sender, {**DEFAULT, 'layout': layout['id']}, 'https://mail.example.org/preferences?t=one')
            self.assertNotIn('<script>', value); self.assertIn('&lt;script&gt;', value)
            self.assertIn('Unsubscribe', value); self.assertIn('Report unwanted email', value); outputs.append(value)
        self.assertEqual(len(set(outputs)), 3)  # Showcase without a hero intentionally shares the simple layout.
        for bad in [{'text': '#ffffff'}, {'primary': 'red;background:url(x)'}, {'html': '<p>custom</p>'}, {'button_label': 'Go', 'button_url': 'javascript:alert(1)'}]:
            with self.assertRaises(Denied): design(bad)

    def test_secret_negation_does_not_hide_a_second_malicious_request(self):
        self.assertFalse(inspect('Security advice', 'Never share your password.')['blocked'])
        self.assertTrue(inspect('Security advice', 'Never share your password. Reply with your OTP now.')['blocked'])
        self.assertTrue(inspect('News', 'Visit https://bit.ly/hidden')['blocked'])
        self.assertTrue(inspect('News', 'Hidden\u200b text')['blocked'])
        self.assertTrue(inspect('Visit http://example.org', 'See subject')['blocked'])

    def test_images_reencode_and_wrong_magic_and_dimensions_fail(self):
        raw = png(); clean, info = inspect_file(raw + b'junk trailing metadata', 'image/png', 'logo')
        self.assertNotIn(b'junk trailing', clean); self.assertEqual(info['width'], 24)
        for data, mime in [(raw, 'image/jpeg'), (b'MZ executable', 'image/png'), (raw * 40000, 'image/png')]:
            with self.assertRaises(Denied): inspect_file(data, mime, 'attachment')
        out = io.BytesIO(); Image.new('RGB', (2500, 1)).save(out, 'PNG')
        with self.assertRaises(Denied): inspect_file(out.getvalue(), 'image/png', 'attachment')

    def test_pdf_rejects_active_encrypted_and_too_many_pages(self):
        writer = PdfWriter(); writer.add_blank_page(width=100, height=100); out = io.BytesIO(); writer.write(out)
        raw = out.getvalue(); self.assertEqual(inspect_file(raw, 'application/pdf', 'attachment')[1]['pages'], 1)
        writer._root_object[NameObject('/OpenAction')] = DictionaryObject({NameObject('/S'): NameObject('/JavaScript'), NameObject('/JS'): TextStringObject('app.alert(1)')}); out = io.BytesIO(); writer.write(out)
        with self.assertRaises(Denied): inspect_file(out.getvalue(), 'application/pdf', 'attachment')
        writer = PdfWriter()
        for _ in range(31): writer.add_blank_page(width=100, height=100)
        out = io.BytesIO(); writer.write(out)
        with self.assertRaises(Denied): inspect_file(out.getvalue(), 'application/pdf', 'attachment')
        writer = PdfWriter(); writer.add_blank_page(width=100, height=100); writer.encrypt('secret'); out = io.BytesIO(); writer.write(out)
        with self.assertRaises(Denied): inspect_file(out.getvalue(), 'application/pdf', 'attachment')

    def test_quarantine_ownership_failed_scan_and_immutable_review(self):
        id = self.upload(ready=False)
        with self.assertRaises(Denied): self.assets.snapshot(1, DEFAULT, [id])
        with self.assertRaises(Denied): self.assets.get(2, id)
        bad = self.event(id); bad['account'] = 'untrusted'
        with self.assertRaises(Denied): self.assets.scan(bad)
        self.assets.scan(self.event(id)); manifest = self.assets.snapshot(1, DEFAULT, [id]); self.assets.verify(manifest)
        self.assets.scan(self.event(id, 'THREATS_FOUND'))
        with self.assertRaises(Denied): self.assets.verify(manifest)
        self.assets.scan(self.event(id)); self.assertEqual(self.assets.get(1, id)['state'], 'blocked')
        id = self.upload(ready=False); self.assets.scan(self.event(id, 'UNSUPPORTED'))
        self.assertEqual(self.assets.get(1, id)['state'], 'blocked')

    def test_size_count_daily_upload_and_campaign_traffic_limits(self):
        with self.assertRaises(Denied): self.assets.create(1, {'purpose': 'attachment', 'mime': 'application/pdf', 'name': 'huge.pdf', 'bytes': 1024**3, 'sha256': 'a' * 64})
        id = self.upload()
        for ids in [[id] * 4, [id, id]]:
            with self.assertRaises(Denied): self.assets.snapshot(1, DEFAULT, ids)
        key = ('A#1', 'FILE#' + id); rev, value = self.store.get(key); value['clean_bytes'] = 2 * 1024**2; self.store.commit({key: (rev, value)})
        data = self.data([id]); data['recipients']['manual'] = ['user' + str(n) + '@example.org' for n in range(40)]
        with self.assertRaises(Denied): self.engine.api(self.owner, 'quote', data)

    def test_review_requires_file_and_risk_ack_mime_contains_exact_inline_and_attachment(self):
        logo, attachment = self.upload('logo'), self.upload()
        c = self.engine.api(self.owner, 'create', self.data([attachment], {**DEFAULT, 'logo': logo, 'layout': 'newsletter'}))['campaign']
        review = {'id': c['id'], 'fingerprint': c['fingerprint'], 'decision': 'approved', 'evidence': 'Inspected exact file bytes and consent evidence.'}
        with self.assertRaises(Denied): self.engine.api(self.admin, 'review-campaign', review)
        with self.assertRaises(Denied): self.engine.api(self.admin, 'review-campaign', {**review, 'risk_acknowledged': True})
        self.engine.api(self.admin, 'review-campaign', {**review, 'risk_acknowledged': True, 'files_reviewed': True})
        _, meta = self.engine.campaign(self.owner, c['id']); recipient = self.store.query('C#' + c['id'], 'R#', 1)[0][2]
        message = BytesParser(policy=policy.default).parsebytes(self.engine.mime(meta, recipient))
        self.assertEqual(message.get_content_type(), 'multipart/mixed')
        parts = list(message.walk()); self.assertEqual(len([p for p in parts if p.get_content_disposition() == 'attachment']), 1)
        self.assertTrue(any(p.get('Content-ID') == '<asset-' + logo + '>' for p in parts))
        self.now += 92 * 86400
        with self.assertRaises(Denied): self.engine.mime(meta, recipient)

    def test_pdf_text_cannot_bypass_moderation(self):
        id = self.upload(); key = ('A#1', 'FILE#' + id); rev, value = self.store.get(key)
        value['inspection']['text'] = 'Please send your password.'; self.store.commit({key: (rev, value)})
        with self.assertRaises(Denied): self.engine.api(self.owner, 'create', self.data([id]))

    def test_saved_template_is_owned_and_does_not_approve_or_send(self):
        saved = self.engine.api(self.owner, 'save-template', {'name': 'Community brand', 'design': {**DEFAULT, 'layout': 'showcase'}})
        self.assertEqual(len(self.engine.api(self.owner, 'templates', {})['saved']), 1)
        self.assertEqual(len(self.engine.api({**self.owner, 'id': 2}, 'templates', {})['saved']), 0)
        self.assertEqual(self.engine.provider.sends, [])

    def test_upload_ticket_signs_exact_length_checksum_and_single_write(self):
        import boto3
        from botocore.config import Config
        from urllib.parse import urlsplit, parse_qs
        client = boto3.client('s3', region_name='eu-north-1', endpoint_url='https://s3.eu-north-1.amazonaws.com', aws_access_key_id='FIXTURE', aws_secret_access_key='fixture-key', config=Config(signature_version='s3v4', s3={'addressing_style': 'virtual'}))
        ticket = S3Files(client, 'private-files').ticket({'sha256': 'a' * 64, 'key': 'quarantine/1/fixture', 'bytes': 123, 'mime': 'image/png'})
        query = parse_qs(urlsplit(ticket['url']).query)
        signed = query['X-Amz-SignedHeaders'][0].split(';')
        for header in ['content-length', 'content-type', 'if-none-match', 'x-amz-checksum-sha256']: self.assertIn(header, signed)
        self.assertEqual(ticket['headers']['If-None-Match'], '*'); self.assertEqual(query['X-Amz-Expires'], ['300'])

    def test_pacing_and_daily_byte_cap_do_not_download_files_again(self):
        id = self.upload(); c = self.engine.api(self.owner, 'create', self.data([id]))['campaign']
        c = self.engine.api(self.admin, 'review-campaign', {'id': c['id'], 'fingerprint': c['fingerprint'], 'decision': 'approved', 'evidence': 'Reviewed synthetic exact files and content safely.', 'risk_acknowledged': True, 'files_reviewed': True})['campaign']
        self.assertTrue(c['files_reviewed']); self.assertTrue(c['risk_acknowledged'])
        proof = {k: c[k] for k in ['id', 'fingerprint', 'recipient_count', 'unit_cents', 'user_id']}
        c = self.engine.api(self.owner, 'queue', {'id': c['id'], 'reservation': proof})['campaign']; rid = c['recipients'][0]['id']
        def never_download(*args): raise AssertionError('A paused/quota-blocked worker must not fetch file bytes')
        self.files.bytes = never_download
        key = ('SYSTEM', 'BUDGET'); self.store.commit({key: (0, {'buckets': {}, 'next': self.now + 10})})
        self.assertEqual(self.engine.dispatch_one(c['id'], rid), 'paced')
        rev, value = self.store.get(key); value.update(next=0, byte_buckets={str(self.now // 3600): LIMITS['daily_delivery_bytes']}); self.store.commit({key: (rev, value)})
        self.assertEqual(self.engine.dispatch_one(c['id'], rid), 'quota'); self.assertEqual(self.engine.provider.sends, [])
        rev, value = self.store.get(key); value['byte_buckets'] = {}; self.store.commit({key: (rev, value)})
        self.engine.provider.health = lambda: {'reasons': [], 'max_24h': 10000, 'max_rate': 1, 'sent_24h': 100}
        self.assertEqual(self.engine.dispatch_one(c['id'], rid), 'quota')
