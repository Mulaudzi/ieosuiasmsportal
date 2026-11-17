"""AWS-owned campaign state. A committed submitting checkpoint is never submitted twice."""
import hashlib
import hmac
import html
import json
import re
import secrets
import time
from email.message import EmailMessage
from email.policy import SMTP
from email.utils import formataddr, formatdate
from protocol import Denied, content, digest, email, text
from store import Conflict, changed
from assets import Assets, LIMITS
from presentation import CATALOG, design, render, asset_id
from moderation import inspect as inspect_content
from reply_to import ReplyTo, OPERATIONS as REPLY_OPERATIONS


def sid(value):
    if not isinstance(value, str) or not re.fullmatch(r'[a-f0-9]{32}', value):
        raise Denied('Campaign not found', 404)
    return value


class Engine:
    def __init__(self, store, accounting, provider, settings, clock=time.time, assets=None):
        self.store, self.accounting, self.provider = store, accounting, provider
        self.settings, self.clock = settings, clock
        self.assets = assets or Assets(store, None, settings, clock)
        self.replies = ReplyTo(store, settings['preference_key'], clock)

    def materials(self, owner, data):
        message = content(data)
        files = self.assets.snapshot(owner, design(message.get('design')), message.get('attachment_ids', []))
        evidence = '\n'.join(self.assets.get(owner, v['id']).get('inspection', {}).get('text', '') for v in files)
        report = inspect_content(message['subject'], message['body'] + '\n' + evidence, message.get('design', {}).get('button_url', ''), files)
        if report['blocked']:
            raise Denied('Content requires changes: ' + '; '.join(v['message'] for v in report['findings'] if v['severity'] == 'block'))
        return message, files, report

    def load(self, key):
        rev, value = self.store.get(key)
        if value is None:
            raise Denied('Record not found', 404)
        return rev, value

    def account(self, actor):
        if not isinstance(actor, dict) or type(actor.get('id')) is not int or actor['id'] < 1 or actor.get('role') not in ['user', 'admin'] or not email(actor.get('email')) or actor.get('verified') is not True:
            raise Denied('Verified customer authorization required', 403)
        if type(actor.get('unit_cents')) is not int or not 1 <= actor['unit_cents'] <= 10000:
            raise Denied('Server pricing unavailable', 503)
        return actor['id']

    def profile(self, owner, approved=False):
        rev, value = self.load(('A#' + str(owner), 'SENDER'))
        if approved and value['state'] != 'approved':
            raise Denied('Sender approval is required', 403)
        if approved and not self.valid_reply(value):
            raise Denied('Verify the selected reply inbox and request a new sender review', 409)
        return rev, value

    def valid_reply(self, profile):
        return self.replies.allowed(profile['user_id'], profile['reply_to'], profile.get('account_email', profile['reply_to']), profile.get('reply_to_verification', ''))

    def campaign(self, actor, campaign_id, admin=False):
        self.account(actor)
        rev, value = self.load(('C#' + sid(campaign_id), 'META'))
        if value['user_id'] != actor['id'] and not (admin and actor['role'] == 'admin'):
            raise Denied('Campaign not found', 404)
        return rev, value

    def readiness(self):
        health = self.provider.health()
        reasons = list(health.get('reasons', []))
        if not self.settings.get('dispatch_enabled'):
            reasons.insert(0, 'Live campaign sending is disabled pending launch acceptance.')
        return {'ready': not reasons, 'reasons': reasons}

    def quote(self, actor, data):
        owner = self.account(actor)
        message, files, report = self.materials(owner, data)
        source = data.get('recipients', {})
        if not isinstance(source, dict) or source.get('group_ids') or source.get('contact_ids'):
            raise Denied('Contact selections must be resolved by the customer portal')
        raw = source.get('manual', [])
        if not isinstance(raw, list) or len(raw) > 10000 or any(not isinstance(v, str) for v in raw):
            raise Denied('Supply a bounded audience')
        unique, invalid, duplicates, suppressed = set(), 0, 0, 0
        for value in raw:
            address = email(value)
            if not address:
                invalid += 1
            elif address in unique:
                duplicates += 1
            else:
                unique.add(address)
        recipients = []
        for address in sorted(unique):
            if self.store.get(('A#' + str(owner), 'SUP#' + hashlib.sha256(address.encode()).hexdigest()))[1]:
                suppressed += 1
            else:
                recipients.append(address)
        if not recipients or len(recipients) > 1000:
            raise Denied('Select between 1 and 1,000 eligible recipients per campaign')
        cost = len(recipients) * actor['unit_cents']
        available = actor.get('available_cents', 0)
        # Conservative MIME allowance includes base64/line wrapping, both body alternatives and headers.
        estimated = 16384 + len(message['body'].encode()) * 12 + sum(v['clean_bytes'] * 14 // 10 + 2048 for v in files)
        if estimated > LIMITS['mime_bytes'] or files and estimated * len(recipients) > LIMITS['campaign_delivery_bytes']:
            raise Denied('Reduce file size or recipient count: the initial campaign data allowance is 100 MB')
        return {'entered': len(raw), 'invalid': invalid, 'duplicates': duplicates, 'opted_out': suppressed, 'sendable': len(recipients), 'recipients': recipients, 'credits_required': f'{len(recipients) / 5:.1f}', 'unit_amount': f'{actor["unit_cents"] / 100:.4f}', 'amount': f'{cost / 100:.4f}', 'available_balance': f'{available / 100:.4f}', 'sufficient_balance': available >= cost, 'estimated_message_bytes': estimated, 'estimated_delivery_bytes': estimated * len(recipients), 'moderation': report}

    def detail(self, actor, cid, admin=False):
        _, c = self.campaign(actor, cid, admin)
        result = changed(c)
        snapshot = self.load(('C#' + cid, 'SNAPSHOT'))[1]
        result.update(body=snapshot['body'], consent_evidence=snapshot['consent_evidence'])
        result.update(design=snapshot.get('design'), moderation=snapshot.get('moderation'), files=[])
        for item in snapshot.get('files', []):
            try:
                value = self.assets.get(c['user_id'], item['id'])
                result['files'].append(self.assets.view(value))
            except Denied as error:
                if error.status != 404:
                    raise
                result['files'].append({**{k: item[k] for k in ['id', 'owner', 'name', 'mime', 'purpose', 'bytes', 'expires_at']}, 'state': 'expired', 'download_url': None})
        result.pop('audience', None)
        result.pop('reservation', None)
        result['recipients'] = []
        for _, _, r in self.store.query('C#' + cid, 'R#', 1000):
            result['recipients'].append({k: r.get(k) for k in ['id', 'email', 'state', 'delivery_state', 'attempts', 'accepted_at', 'outcome_code', 'provider_message_id']})
        return result

    def create(self, actor, data):
        owner = self.account(actor)
        pr, profile = self.profile(owner, True)
        if profile.get('account_email', profile['reply_to']) != actor['email'].lower():
            raise Denied('Request a new sender review after changing your email', 409)
        if data.get('consent_confirmed') is not True:
            raise Denied('Recipient consent is required')
        evidence = text(data.get('consent_evidence'), 10, 1500, True)
        key = text(data.get('idempotency_key'), 16, 80)
        message, files, report = self.materials(owner, data)
        q = self.quote(actor, data)
        binding = {'content': message, 'audience': q['recipients'], 'sender': profile, 'consent': evidence, 'unit_cents': actor['unit_cents']}
        if 'design' in message or files:
            binding.update(files=files, moderation=report)
        fingerprint = digest(binding)
        cid = hashlib.sha256((str(owner) + ':' + key).encode()).hexdigest()[:32]
        meta_key = ('C#' + cid, 'META')
        rev, existing = self.store.get(meta_key)
        if existing:
            if existing['fingerprint'] != fingerprint:
                raise Denied('Idempotency key was used for different content', 409)
            if existing['state'] != 'initializing':
                return self.detail(actor, cid)
            campaign = existing
            snapshot = self.load(('C#' + cid, 'SNAPSHOT'))[1]
        else:
            # A bounded account manifest serializes campaign creation and daily review limits.
            manifest_key = ('A#' + str(owner), 'CAMPAIGNS')
            mr, manifest = self.store.get(manifest_key)
            manifest = manifest or {'ids': [], 'day': int(self.clock()) // 86400, 'created_today': 0}
            if manifest['day'] != int(self.clock()) // 86400:
                manifest.update(day=int(self.clock()) // 86400, created_today=0)
            if manifest['created_today'] >= 50:
                raise Denied('Daily campaign review limit reached', 429)
            open_jobs = 0
            for old in manifest['ids']:
                value = self.store.get(('C#' + old, 'META'))[1]
                if value and value['state'] not in ['completed', 'cancelled', 'rejected']:
                    open_jobs += 1
            if open_jobs >= 20:
                raise Denied('Finish or cancel existing campaigns first', 429)
            if len(manifest['ids']) >= 100:
                for old in reversed(manifest['ids']):
                    value = self.store.get(('C#' + old, 'META'))[1]
                    if value and value['state'] in ['completed', 'cancelled', 'rejected']:
                        manifest['ids'].remove(old)
                        break
                else:
                    raise Denied('Campaign history requires reconciliation', 429)
            campaign = {'id': cid, 'user_id': owner, 'name': message['name'], 'subject': message['subject'], 'sender': profile, 'fingerprint': fingerprint, 'state': 'initializing', 'unit_cents': actor['unit_cents'], 'recipient_count': q['sendable'], 'reserved_cents': 0, 'charged_cents': 0, 'created_at': time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime(self.clock())), 'cancel_requested': False}
            snapshot = {'body': message['body'], 'consent_evidence': evidence, 'audience': q['recipients'], 'design': message.get('design'), 'files': files, 'moderation': report, 'estimated_message_bytes': q['estimated_message_bytes']}
            manifest['ids'].insert(0, cid)
            manifest['created_today'] += 1
            self.store.commit({meta_key: (0, campaign), ('C#' + cid, 'SNAPSHOT'): (0, snapshot), manifest_key: (mr, manifest)}, {('A#' + str(owner), 'SENDER'): pr})
        for address in snapshot['audience']:
            rid = hashlib.sha256((cid + ':' + address).encode()).hexdigest()[:32]
            rk = ('C#' + cid, 'R#' + rid)
            rr, recipient = self.store.get(rk)
            if recipient is None:
                self.store.commit({rk: (0, {'id': rid, 'email': address, 'state': 'pending', 'delivery_state': 'unknown', 'attempts': 0, 'accepted_at': None, 'provider_message_id': None, 'settled': False})})
        rev, campaign = self.load(meta_key)
        if campaign['state'] == 'initializing':
            campaign['state'] = 'awaiting_review'
            self.store.commit({meta_key: (rev, campaign)})
        return self.detail(actor, cid)

    def api(self, actor, operation, data):
        owner = self.account(actor)
        if operation in REPLY_OPERATIONS:
            return self.replies.api(actor, operation, data)
        ak = ('A#' + str(owner), 'SENDER')
        if operation == 'templates':
            manifest = self.store.get(('A#' + str(owner), 'TEMPLATES'))[1] or {'items': []}
            return {'catalog': CATALOG, 'saved': manifest['items'], 'file_limits': self.assets.limits()}
        if operation == 'save-template':
            key = ('A#' + str(owner), 'TEMPLATES');rev, saved = self.store.get(key);saved = saved or {'items': []}
            label = text(data.get('name'), 2, 80);style = design(data.get('design'))
            self.assets.snapshot(owner, style, [])
            template = {'id': asset_id(data['id']) if data.get('id') else secrets.token_hex(16), 'name': label, 'design': style}
            existing = next((v for v in saved['items'] if v['id'] == template['id']), None)
            if data.get('id') and existing is None:
                raise Denied('Saved template not found', 404)
            saved['items'] = [template] + [v for v in saved['items'] if v['id'] != template['id']]
            if len(saved['items']) > 20:
                raise Denied('Save at most 20 personal templates')
            self.store.commit({key: (rev, saved)})
            return {'template': template}
        if operation == 'files':
            manifest = self.store.get(('A#' + str(owner), 'FILES'))[1] or {'ids': []}
            values = [self.store.get(('A#' + str(owner), 'FILE#' + id))[1] for id in manifest['ids']]
            return {'files': [self.assets.view(v) for v in values if v and v['expires_at'] > self.clock()], 'file_limits': self.assets.limits()}
        if operation == 'upload-ticket':
            return self.assets.create(owner, data)
        if operation == 'preview':
            message, files, report = self.materials(owner, data)
            profile = self.store.get(ak)[1] or {'business_name': 'Your business', 'postal_address': 'Your verified business postal address', 'reply_to': actor['email']}
            return {'html': render(message['subject'], message['body'], profile, message.get('design'), '#unsubscribe-preview', files, True), 'moderation': report}
        if operation == 'limits':
            readiness = self.readiness()
            if actor['role'] != 'admin' and not readiness['ready']:
                readiness['reasons'] = ['Email sending setup and approval are being completed.']
            return {'enabled': True, 'emails_per_credit': 5, 'max_recipients': 1000, 'target_daily_limit': 10000, 'readiness': readiness, 'billing_basis': '0.2 SMS credit per accepted recipient; not inbox delivery', 'file_limits': self.assets.limits()}
        if operation == 'sender':
            return {'sender': self.store.get(ak)[1]}
        if operation == 'save-sender':
            rev, old = self.store.get(ak)
            if old and old['state'] == 'suspended':
                raise Denied('Sender is suspended; contact support', 403)
            address = email(data.get('reply_to', actor['email']))
            proof = next((v for v in self.replies.entries(owner)['items'] if v['email'] == address), None)
            if not address or address != actor['email'].lower() and not proof:
                raise Denied('Verify this reply inbox before selecting it', 403)
            profile = {'user_id': owner, 'business_name': text(data.get('business_name'), 2, 100), 'postal_address': text(data.get('postal_address'), 10, 400, True), 'account_email': actor['email'].lower(), 'reply_to': address, 'reply_to_verification': proof['verification_id'] if proof and address != actor['email'].lower() else '', 'reply_to_verified_at': proof['verified_at'] if proof else None, 'state': 'pending', 'daily_limit': 0, 'version': (old or {}).get('version', 0) + 1}
            self.store.commit({ak: (rev, profile), ('ADMIN', 'S#' + str(owner)): (self.store.get(('ADMIN', 'S#' + str(owner)))[0], {'user_id': owner})})
            return {'sender': profile}
        if operation == 'review-sender':
            if actor['role'] != 'admin' or type(data.get('user_id')) is not int or data['user_id'] == owner:
                raise Denied('Independent administrator review required', 403)
            target = data['user_id']
            rev, profile = self.profile(target)
            if data.get('expected_version') != profile['version']:
                raise Denied('Sender details changed; review again', 409)
            decision = data.get('decision')
            if decision == 'approved' and not self.valid_reply(profile):
                raise Denied('The selected reply inbox no longer has valid ownership verification', 409)
            if decision not in ['approved', 'rejected', 'suspended']:
                raise Denied('Choose an explicit review decision')
            allowance = data.get('daily_limit', 0)
            if decision == 'approved' and (type(allowance) is not int or not 1 <= allowance <= 10000):
                raise Denied('Choose a daily allowance no greater than 10,000')
            profile.update(state=decision, approval_evidence=text(data.get('evidence'), 20, 1500, True), reviewed_by=owner, daily_limit=allowance if decision == 'approved' else 0, version=profile['version'] + 1)
            self.store.commit({('A#' + str(target), 'SENDER'): (rev, profile)})
            return {'sender': profile}
        if operation == 'quote':
            return {'quote': self.quote(actor, data)}
        if operation == 'create':
            return {'campaign': self.create(actor, data)}
        if operation == 'campaigns':
            manifest = self.store.get(('A#' + str(owner), 'CAMPAIGNS'))[1] or {'ids': []}
            return {'campaigns': [self.summary(self.load(('C#' + cid, 'META'))[1]) for cid in manifest['ids']]}
        if operation == 'overview':
            if actor['role'] != 'admin':
                raise Denied('Administrator access required', 403)
            profiles = [self.profile(v['user_id'])[1] for _, _, v in self.store.query('ADMIN', 'S#', 200)]
            campaigns = []
            for profile in profiles:
                manifest = self.store.get(('A#' + str(profile['user_id']), 'CAMPAIGNS'))[1] or {'ids': []}
                campaigns.extend(self.summary(self.load(('C#' + cid, 'META'))[1]) for cid in manifest['ids'])
            return {'senders': profiles, 'campaigns': sorted(campaigns, key=lambda c: c['created_at'], reverse=True)[:200]}
        cid = sid(data.get('id'))
        if operation == 'admin-detail' and actor['role'] != 'admin':
            raise Denied('Administrator access required', 403)
        rev, campaign = self.campaign(actor, cid, operation in ['admin-detail', 'review-campaign'])
        ck = ('C#' + cid, 'META')
        if operation in ['detail', 'admin-detail']:
            return {'campaign': self.detail(actor, cid, operation == 'admin-detail')}
        if operation == 'review-campaign':
            if actor['role'] != 'admin' or campaign['user_id'] == owner:
                raise Denied('Independent administrator review required', 403)
            pr, profile = self.profile(campaign['user_id'], True)
            if profile != campaign['sender'] or campaign['state'] != 'awaiting_review' or data.get('fingerprint') != campaign['fingerprint']:
                raise Denied('Campaign or sender changed; review again', 409)
            if data.get('decision') not in ['approved', 'rejected']:
                raise Denied('Choose approve or reject')
            snapshot = self.load(('C#' + cid, 'SNAPSHOT'))[1]
            if data['decision'] == 'approved':
                self.assets.verify(snapshot.get('files', []))
                report = snapshot.get('moderation') or {}
                if report.get('blocked') or report.get('review_required') and data.get('risk_acknowledged') is not True:
                    raise Denied('Inspect and acknowledge the recorded content-risk findings before approval', 409)
                if snapshot.get('files') and data.get('files_reviewed') is not True:
                    raise Denied('Open and review every exact file before approval', 409)
            campaign.update(state=data['decision'], reviewed_by=owner, reviewed_at=int(self.clock()), risk_acknowledged=data.get('risk_acknowledged') is True, files_reviewed=data.get('files_reviewed') is True, review_evidence=text(data.get('evidence'), 20, 1500, True))
            self.store.commit({ck: (rev, campaign)}, {('A#' + str(campaign['user_id']), 'SENDER'): pr})
        elif operation == 'queue':
            if not self.readiness()['ready']:
                raise Denied('Live sending has not passed launch acceptance', 503)
            if campaign['state'] in ['queued', 'held', 'completed']:
                return {'campaign': self.detail(actor, cid)}
            pr, profile = self.profile(owner, True)
            self.assets.verify(self.load(('C#' + cid, 'SNAPSHOT'))[1].get('files', []))
            proof = data.get('reservation', {})
            if campaign['state'] not in ['approved', 'queueing'] or profile != campaign['sender'] or profile.get('account_email', profile['reply_to']) != actor['email'].lower() or campaign['unit_cents'] != actor['unit_cents']:
                raise Denied('Approved unchanged campaign required', 409)
            if proof != {'id': cid, 'fingerprint': campaign['fingerprint'], 'recipient_count': campaign['recipient_count'], 'unit_cents': campaign['unit_cents'], 'user_id': owner}:
                raise Denied('Portal reservation proof required', 403)
            campaign.update(state='queueing', reservation=proof, reserved_cents=campaign['unit_cents'] * campaign['recipient_count'], queued_at=campaign.get('queued_at', int(self.clock())))
            active_key = ('ACTIVE', cid)
            active_rev, _ = self.store.get(active_key)
            self.store.commit({ck: (rev, campaign), active_key: (active_rev, {'campaign': cid, 'queued_at': campaign['queued_at']})}, {('A#' + str(owner), 'SENDER'): pr})
            for rk, rr, recipient in self.store.query('C#' + cid, 'R#', 1000):
                if recipient['state'] == 'pending':
                    recipient['state'] = 'queued'
                    ready = ('READY', cid + ':' + recipient['id'])
                    self.store.commit({('C#' + cid, rk): (rr, recipient), ready: (0, {'campaign': cid, 'recipient': recipient['id']})})
            rev, campaign = self.load(ck)
            if campaign['state'] == 'queueing':
                campaign['state'] = 'queued'
                self.store.commit({ck: (rev, campaign)})
        elif operation == 'cancel':
            # Stop AWS claims before the portal releases only recipients without an issued permit.
            if campaign['state'] not in ['completed', 'cancelled']:
                campaign.update(cancel_requested=True, state='cancelled')
                self.store.commit({ck: (rev, campaign)})
        else:
            raise Denied('Unknown campaign operation', 404)
        return {'campaign': self.detail(actor, cid, operation == 'review-campaign')}

    @staticmethod
    def summary(c):
        return {k: c.get(k) for k in ['id', 'user_id', 'name', 'subject', 'state', 'recipient_count', 'reserved_cents', 'charged_cents', 'created_at']}

    def token(self, cid, rid):
        return hmac.new(self.settings['preference_key'].encode(), ('preference:' + sid(cid) + ':' + sid(rid)).encode(), hashlib.sha256).hexdigest()

    def preference(self, cid, rid, token, post=False, report=False):
        if not isinstance(token, str) or not hmac.compare_digest(self.token(cid, rid), token):
            raise Denied('Preference link unavailable', 404)
        cr, c = self.load(('C#' + sid(cid), 'META'))
        rr, r = self.load(('C#' + cid, 'R#' + sid(rid)))
        if not post:
            return
        sk = ('A#' + str(c['user_id']), 'SUP#' + hashlib.sha256(r['email'].encode()).hexdigest())
        sr, old = self.store.get(sk)
        if old and (not report or old.get('abuse_reported')):
            return
        writes = {sk: (sr, {'reason': 'abuse' if report else 'unsubscribe', 'abuse_reported': report or (old or {}).get('abuse_reported', False), 'at': int(self.clock())})}
        if report:
            pr, profile = self.profile(c['user_id'])
            profile.update(state='suspended', version=profile['version'] + 1)
            writes[('A#' + str(c['user_id']), 'SENDER')] = (pr, profile)
        self.store.commit(writes)

    def mime(self, c, r):
        snapshot = self.load(('C#' + c['id'], 'SNAPSHOT'))[1]
        body_text, files = snapshot['body'], snapshot.get('files', [])
        self.assets.verify(files)
        base = self.settings['public_url'].rstrip('/')
        preference = base + '/preferences?campaign=' + c['id'] + '&recipient=' + r['id'] + '&token=' + self.token(c['id'], r['id'])
        sender = c['sender']
        footer = f"\n\nSent by {sender['business_name']} via IEOSUIA.\n{sender['postal_address']}\nReplies: {sender['reply_to']}\nUnsubscribe: {preference}\nReport abuse: {preference}&report=1"
        msg = EmailMessage(policy=SMTP)
        msg['From'] = formataddr((sender['business_name'] + ' via IEOSUIA', self.settings['from']))
        msg['To'], msg['Reply-To'], msg['Subject'] = r['email'], sender['reply_to'], c['subject']
        msg['Message-ID'] = '<' + r['id'] + '@campaigns.ieosuia.com>'
        msg['Date'] = formatdate(self.clock(), usegmt=True)
        msg['List-Unsubscribe'] = '<' + preference + '>'
        msg['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click'
        d = design(snapshot.get('design'))
        button = ('\n\n' + d['button_label'] + ': ' + d['button_url']) if d['button_url'] else ''
        msg.set_content(body_text + button + footer)
        msg.add_alternative(render(c['subject'], body_text, sender, snapshot.get('design'), preference, files), subtype='html')
        html_part = msg.get_payload()[1]
        for file in files:
            raw = self.assets.files.bytes(file)
            maintype, subtype = file['mime'].split('/')
            if file['purpose'] in ['logo', 'hero']:
                html_part.add_related(raw, maintype=maintype, subtype=subtype, cid='<asset-' + file['id'] + '>', filename=file['name'], disposition='inline')
            else:
                msg.add_attachment(raw, maintype=maintype, subtype=subtype, filename=file['name'])
        raw = msg.as_bytes()
        if len(raw) > LIMITS['mime_bytes']:
            raise Denied('The complete email exceeds the initial 8 MB MIME limit')
        return raw

    @staticmethod
    def consume_budget(value, now, limit):
        value = value or {'buckets': {}, 'next': 0}
        # Include the full oldest hour: conservative rolling-24h enforcement with at most 25 buckets.
        minute = int(now) // 3600
        value['buckets'] = {k: v for k, v in value['buckets'].items() if int(k) >= minute - 24}
        if sum(value['buckets'].values()) >= limit:
            raise Denied('Rolling daily attempt allowance reached', 429)
        value['buckets'][str(minute)] = value['buckets'].get(str(minute), 0) + 1
        return value

    def dispatch_one(self, cid, rid):
        if not self.readiness()['ready']:
            return 'paused'
        ck, rk = ('C#' + sid(cid), 'META'), ('C#' + cid, 'R#' + sid(rid))
        cr, c = self.load(ck)
        rr, r = self.load(rk)
        ready_key = ('READY', cid + ':' + rid)
        qr, ready = self.store.get(ready_key)
        if not ready:
            return 'skipped'
        if r['state'] != 'queued':
            self.store.commit({ready_key: (qr, None)})
            return 'skipped'
        if c['cancel_requested']:
            return self.release_unattempted(c, r, ready_key, qr)
        if c['state'] != 'queued':
            return 'skipped'
        pr, profile = self.profile(c['user_id'])
        sr, suppression = self.store.get(('A#' + str(c['user_id']), 'SUP#' + hashlib.sha256(r['email'].encode()).hexdigest()))
        if suppression or profile != c['sender'] or profile['state'] != 'approved' or not self.valid_reply(profile):
            return self.release_unattempted(c, r, ready_key, qr)
        now = self.clock()
        gk, uk = ('SYSTEM', 'BUDGET'), ('A#' + str(c['user_id']), 'BUDGET')
        gr, global_budget = self.store.get(gk)
        ur, user_budget = self.store.get(uk)
        if (global_budget or {}).get('next', 0) > now:
            return 'paced'
        snapshot = self.load(('C#' + cid, 'SNAPSHOT'))[1]
        hour = int(now) // 3600
        spent = sum(v for k, v in (global_budget or {}).get('byte_buckets', {}).items() if int(k) >= hour - 24)
        estimate = snapshot.get('estimated_message_bytes', 16384 + len(snapshot['body'].encode()) * 12)
        if spent + estimate > LIMITS['daily_delivery_bytes']:
            return 'quota'
        health = self.provider.health()
        cap = min(10000, self.settings['daily_limit'], int(health['max_24h'] * .9))
        global_budget = self.consume_budget(global_budget, now, cap)
        user_budget = self.consume_budget(user_budget, now, profile['daily_limit'])
        overlap_minute = int(health.get('observed_at', now)) // 3600 - 1
        overlap = sum(v for k, v in global_budget['buckets'].items() if int(k) >= overlap_minute)
        if health['sent_24h'] + overlap >= cap:
            return 'quota'
        try:
            message_bytes = self.mime(c, r)
        except Denied:
            return self.release_unattempted(c, r, ready_key, qr)
        hour = int(now) // 3600
        byte_buckets = {k: v for k, v in global_budget.get('byte_buckets', {}).items() if int(k) >= hour - 24}
        if sum(byte_buckets.values()) + len(message_bytes) > LIMITS['daily_delivery_bytes']:
            return 'quota'
        byte_buckets[str(hour)] = byte_buckets.get(str(hour), 0) + len(message_bytes)
        global_budget['byte_buckets'] = byte_buckets
        global_budget['next'] = now + 1 / min(1.0, health['max_rate'])
        r.update(state='preparing', attempts=1, started_at=int(now))
        self.store.commit({rk: (rr, r), ready_key: (qr, None), gk: (gr, global_budget), uk: (ur, user_budget)}, {ck: cr, ('A#' + str(c['user_id']), 'SENDER'): pr, ('A#' + str(c['user_id']), 'SUP#' + hashlib.sha256(r['email'].encode()).hexdigest()): sr})
        # Any failure after claiming keeps this recipient non-retryable. Only accounting may retry.
        try:
            permit = self.accounting.call('attempt', {'id': cid, 'recipient_id': rid, 'email': r['email'], 'user_id': c['user_id'], 'fingerprint': c['fingerprint']})
            if not permit.get('allowed'):
                released = self.accounting.call('release', {'id': cid, 'recipient_id': rid, 'user_id': c['user_id'], 'fingerprint': c['fingerprint']})
                if released.get('released') is True:
                    self.set_recipient(cid, rid, 'cancelled', 'PORTAL_ATTEMPT_DENIED')
                    return 'cancelled'
                self.set_recipient(cid, rid, 'held', 'PERMIT_STATE_REQUIRES_RECONCILIATION')
                return 'held'
            rr, r = self.load(rk)
            cr, latest = self.load(ck)
            pr, profile = self.profile(c['user_id'], True)
            sr, suppression = self.store.get(('A#' + str(c['user_id']), 'SUP#' + hashlib.sha256(r['email'].encode()).hexdigest()))
            if latest['cancel_requested'] or suppression or profile != c['sender']:
                self.set_recipient(cid, rid, 'held', 'STOPPED_AFTER_PERMIT')
                return 'held'
            r['state'] = 'submitting'
            self.store.commit({rk: (rr, r)}, {ck: cr, ('A#' + str(c['user_id']), 'SENDER'): pr, ('A#' + str(c['user_id']), 'SUP#' + hashlib.sha256(r['email'].encode()).hexdigest()): sr})
            self.assets.verify(self.load(('C#' + cid, 'SNAPSHOT'))[1].get('files', []))
            provider_id = self.provider.send(message_bytes, r['email'], {'campaign': cid, 'recipient': rid, 'owner': str(c['user_id'])})
            self.accepted(cid, rid, provider_id)
            self.settle(cid, rid)
            return 'accepted'
        except BaseException:
            self.set_recipient(cid, rid, 'held', 'UNCERTAIN_SUBMISSION')
            return 'held'

    def release_unattempted(self, campaign, recipient, ready_key, ready_rev):
        result = self.accounting.call('release', {'id': campaign['id'], 'recipient_id': recipient['id'], 'user_id': campaign['user_id'], 'fingerprint': campaign['fingerprint']})
        if result.get('released') is not True:
            return 'held'
        rk = ('C#' + campaign['id'], 'R#' + recipient['id'])
        rr, current = self.load(rk)
        if current['state'] == 'queued':
            current.update(state='cancelled', outcome_code='UNATTEMPTED_RELEASED')
            self.store.commit({rk: (rr, current), ready_key: (ready_rev, None)})
        return 'cancelled'

    def reconcile(self, cid):
        cr, c = self.load(('C#' + sid(cid), 'META'))
        rows = self.store.query('C#' + cid, 'R#', 1000)
        for _, _, r in rows:
            if r['provider_message_id'] and not r['settled']:
                self.settle(cid, r['id'])
            elif r['state'] in ['preparing', 'submitting'] and self.clock() - r['started_at'] > 180:
                self.set_recipient(cid, r['id'], 'held', 'STALE_ATTEMPT_REQUIRES_EVIDENCE')
        cr, c = self.load(('C#' + cid, 'META'))
        if 'reservation' not in c:
            return
        totals = self.accounting.call('status', {'id': cid, 'user_id': c['user_id'], 'fingerprint': c['fingerprint']})
        c.update(reserved_cents=totals['reserved_cents'], charged_cents=totals['charged_cents'])
        states = [r['state'] for _, _, r in self.store.query('C#' + cid, 'R#', 1000)]
        if any(v in ['held', 'preparing', 'submitting'] for v in states):
            c['state'] = 'held'
        elif all(v in ['accepted', 'cancelled'] for v in states):
            c['state'] = 'cancelled' if c['cancel_requested'] else 'completed'
        elif c['state'] == 'held' and any(v == 'queued' for v in states) and not c['cancel_requested']:
            c['state'] = 'queued'
        self.store.commit({('C#' + cid, 'META'): (cr, c)})
        if c['state'] in ['cancelled', 'completed'] and c['reserved_cents'] == 0:
            active_key = ('ACTIVE', cid)
            ar, active = self.store.get(active_key)
            if active:
                self.store.commit({active_key: (ar, None)})

    def feedback(self, event):
        mail = event.get('mail', {})
        tags = mail.get('tags', {})
        def tag(name):
            values = tags.get(name)
            if not isinstance(values, list) or len(values) != 1 or not isinstance(values[0], str):
                raise Denied('Ambiguous provider tags', 409)
            return values[0]
        cid, rid = sid(tag('campaign')), sid(tag('recipient'))
        _, c = self.load(('C#' + cid, 'META'))
        rr, r = self.load(('C#' + cid, 'R#' + rid))
        headers = [h.get('value') for h in mail.get('headers', []) if str(h.get('name', '')).lower() == 'message-id']
        if tag('owner') != str(c['user_id']) or mail.get('sendingAccountId') != self.settings['aws_account'] or tag('ses:configuration-set') != self.settings['configuration_set'] or mail.get('destination') != [r['email']] or headers != ['<' + rid + '@campaigns.ieosuia.com>'] or mail.get('source', '').lower() != self.settings['from'].lower():
            raise Denied('Provider evidence does not match the saved attempt', 409)
        event_type = event.get('eventType')
        if event_type not in ['Send', 'Delivery', 'Bounce', 'Complaint', 'Reject']:
            return
        self.accepted(cid, rid, mail.get('messageId'))
        delivery = {'Send': 'unknown', 'Delivery': 'delivered', 'Complaint': 'complaint', 'Reject': 'provider_rejected', 'Bounce': 'hard_bounce' if event.get('bounce', {}).get('bounceType') == 'Permanent' else 'soft_bounce'}[event_type]
        strength = {'unknown': 0, 'delivered': 1, 'soft_bounce': 2, 'hard_bounce': 3, 'provider_rejected': 4, 'complaint': 5}
        for _ in range(5):
            rr, r = self.load(('C#' + cid, 'R#' + rid))
            if strength[delivery] <= strength[r['delivery_state']]:
                break
            r['delivery_state'] = delivery
            writes = {('C#' + cid, 'R#' + rid): (rr, r)}
            if delivery in ['hard_bounce', 'complaint']:
                sk = ('A#' + str(c['user_id']), 'SUP#' + hashlib.sha256(r['email'].encode()).hexdigest())
                sr, existing = self.store.get(sk)
                writes[sk] = (sr, {'reason': 'abuse' if (existing or {}).get('abuse_reported') else ('complaint' if delivery == 'complaint' else (existing or {}).get('reason', 'hard_bounce')), 'abuse_reported': (existing or {}).get('abuse_reported', False), 'at': int(self.clock())})
            if delivery in ['complaint', 'provider_rejected']:
                pr, profile = self.profile(c['user_id'])
                profile.update(state='suspended', version=profile['version'] + 1)
                writes[('A#' + str(c['user_id']), 'SENDER')] = (pr, profile)
            try:
                self.store.commit(writes)
                break
            except Conflict:
                continue
        else:
            raise Conflict()
        self.settle(cid, rid)

    def set_recipient(self, cid, rid, state, reason):
        for _ in range(4):
            rr, r = self.load(('C#' + cid, 'R#' + rid))
            if r['state'] in ['accepted_pending', 'accepted']:
                return
            r.update(state=state, outcome_code=reason)
            try:
                self.store.commit({('C#' + cid, 'R#' + rid): (rr, r)})
                return
            except Conflict:
                continue
        raise Conflict()

    def accepted(self, cid, rid, provider_id):
        if not isinstance(provider_id, str) or not re.fullmatch(r'[A-Za-z0-9_-]{8,200}', provider_id):
            raise Denied('Provider reference unavailable', 503)
        for _ in range(5):
            rr, r = self.load(('C#' + sid(cid), 'R#' + sid(rid)))
            if r['state'] not in ['preparing', 'submitting', 'held', 'accepted_pending', 'accepted']:
                raise Denied('No submitted attempt matches this provider evidence', 409)
            if r['provider_message_id'] and r['provider_message_id'] != provider_id:
                raise Denied('Provider reference conflict', 409)
            pr, p = self.store.get(('PROVIDERS', provider_id))
            if p and p != {'campaign': cid, 'recipient': rid}:
                raise Denied('Provider reference already belongs to another attempt', 409)
            r.update(provider_message_id=provider_id, accepted_at=r['accepted_at'] or int(self.clock()), state='accepted' if r['settled'] else 'accepted_pending')
            try:
                self.store.commit({('C#' + cid, 'R#' + rid): (rr, r), ('PROVIDERS', provider_id): (pr, {'campaign': cid, 'recipient': rid})})
                return
            except Conflict:
                continue
        raise Conflict()

    def settle(self, cid, rid):
        rr, r = self.load(('C#' + cid, 'R#' + rid))
        _, c = self.load(('C#' + cid, 'META'))
        if not r['provider_message_id'] or r['settled']:
            return
        result = self.accounting.call('settle', {'id': cid, 'recipient_id': rid, 'provider_id': r['provider_message_id'], 'user_id': c['user_id'], 'fingerprint': c['fingerprint']})
        if result.get('settled') is not True:
            raise Denied('Accounting acknowledgement unavailable', 503)
        for _ in range(5):
            rr, r = self.load(('C#' + cid, 'R#' + rid))
            cr, c = self.load(('C#' + cid, 'META'))
            if r['settled']:
                return
            r.update(settled=True, state='accepted')
            c['charged_cents'] += c['unit_cents']
            c['reserved_cents'] -= c['unit_cents']
            try:
                self.store.commit({('C#' + cid, 'R#' + rid): (rr, r), ('C#' + cid, 'META'): (cr, c)})
                return
            except Conflict:
                continue
        raise Conflict()
