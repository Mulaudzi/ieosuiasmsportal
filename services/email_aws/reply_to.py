"""Owned alternate reply inboxes. Secret codes are returned only to the signed portal mail bridge."""
import hashlib
import hmac
import re
import secrets
import time
from protocol import Denied, email

OPERATIONS = {'reply-to-status', 'request-reply-to', 'reply-to-delivery', 'verify-reply-to', 'remove-reply-to'}


class ReplyTo:
    def __init__(self, store, key, clock=time.time):
        self.store, self.key, self.clock = store, key, clock

    def entries(self, owner):
        return self.store.get(('A#' + str(owner), 'REPLY-ADDRESSES'))[1] or {'items': []}

    def allowed(self, owner, address, account_email, proof=''):
        if address == account_email:
            return True
        return bool(proof and any(v['email'] == address and v['verification_id'] == proof for v in self.entries(owner)['items']))

    def pending(self, value):
        if not value or value['expires_at'] <= self.clock() or value['state'] in ['verified', 'failed_delivery']:
            return None
        return {**{k: value[k] for k in ['id', 'email', 'state', 'expires_at']}, 'seconds_remaining': max(0, int(value['expires_at'] - self.clock())), 'resend_after_seconds': max(0, int(value['requested_at'] + 60 - self.clock())), 'attempts_remaining': max(0, 5 - value['attempts'])}

    def view(self, actor):
        value = self.store.get(('A#' + str(actor['id']), 'REPLY-CHALLENGE'))[1]
        return {'account_email': actor['email'].lower(), 'addresses': self.entries(actor['id'])['items'], 'pending': self.pending(value), 'max_addresses': 5}

    def code_hash(self, owner, id, address, code):
        return hmac.new(self.key.encode(), ('reply-to-code:' + str(owner) + ':' + id + ':' + address + ':' + code).encode(), hashlib.sha256).hexdigest()

    def api(self, actor, operation, data):
        owner = actor['id']; partition = 'A#' + str(owner); now = int(self.clock())
        ck = (partition, 'REPLY-CHALLENGE'); mk = (partition, 'REPLY-ADDRESSES')
        if operation == 'reply-to-status':
            return self.view(actor)
        if operation == 'request-reply-to':
            address = email(data.get('email'))
            if not address:
                raise Denied('Enter a valid reply inbox email address')
            entries = self.entries(owner)['items']
            if address == actor['email'].lower() or any(v['email'] == address for v in entries):
                raise Denied('This address is already verified; select it from the reply-address list', 409)
            if len(entries) >= 5:
                raise Denied('Remove an unused verified reply address before adding another', 409)
            writes = {}
            for key, hourly, daily, cooldown in [((partition, 'REPLY-RATE'), 3, 10, 60), (('MAIL#' + hashlib.sha256(address.encode()).hexdigest(), 'REPLY-RATE'), 3, 5, 60), (('SYSTEM', 'REPLY-RATE'), 100, 100, 0)]:
                rev, rate = self.store.get(key); rate = rate or {}
                if rate.get('hour') != now // 3600:
                    rate.update(hour=now // 3600, hourly=0)
                if rate.get('day') != now // 86400:
                    rate.update(day=now // 86400, daily=0)
                if rate.get('next_at', 0) > now or rate['hourly'] >= hourly or rate['daily'] >= daily:
                    raise Denied('Verification sending limit reached; wait before requesting another code', 429)
                rate.update(hourly=rate['hourly'] + 1, daily=rate['daily'] + 1, next_at=now + cooldown, _ttl=now + 3 * 86400)
                writes[key] = (rev, rate)
            id, code = secrets.token_hex(16), str(secrets.randbelow(1000000)).zfill(6)
            rev, old = self.store.get(ck)
            challenge = {'id': id, 'email': address, 'code_hash': self.code_hash(owner, id, address, code), 'state': 'pending_delivery', 'attempts': 0, 'requested_at': now, 'expires_at': now + 600, '_ttl': now + 86400}
            writes[ck] = (rev, challenge); self.store.commit(writes)
            # PHP consumes this secret in memory, confirms SMTP handoff and strips it from browser responses.
            return {**self.view(actor), 'verification_delivery': {'id': id, 'email': address, 'code': code}}
        if operation in ['reply-to-delivery', 'verify-reply-to']:
            rev, challenge = self.store.get(ck)
            if not challenge or data.get('id') != challenge['id'] or challenge['expires_at'] <= now:
                raise Denied('Verification request is unavailable or expired; request a new code', 409)
            if operation == 'reply-to-delivery':
                if challenge['state'] != 'pending_delivery':
                    if challenge['state'] == 'sent' and data.get('delivered') is True:
                        return self.view(actor)
                    raise Denied('This delivery confirmation is no longer applicable', 409)
                challenge['state'] = 'sent' if data.get('delivered') is True else 'failed_delivery'
                if challenge['state'] == 'failed_delivery':
                    challenge.pop('code_hash', None)
                self.store.commit({ck: (rev, challenge)})
                return self.view(actor)
            if challenge['state'] != 'sent' or challenge['attempts'] >= 5:
                raise Denied('Code unavailable or attempt limit reached; request a new code', 409)
            code = data.get('code')
            if not isinstance(code, str) or not re.fullmatch('[0-9]{6}', code):
                raise Denied('Enter the six-digit code from the reply inbox')
            challenge['attempts'] += 1
            if not hmac.compare_digest(challenge['code_hash'], self.code_hash(owner, challenge['id'], challenge['email'], code)):
                if challenge['attempts'] >= 5:
                    challenge.update(state='locked'); challenge.pop('code_hash', None)
                self.store.commit({ck: (rev, challenge)})
                raise Denied('Code did not match; check it or request a new code')
            mr, addresses = self.store.get(mk); addresses = addresses or {'items': []}
            if len(addresses['items']) >= 5:
                raise Denied('Remove an unused verified reply address first', 409)
            addresses['items'] = [v for v in addresses['items'] if v['email'] != challenge['email']] + [{'email': challenge['email'], 'verification_id': secrets.token_hex(16), 'verified_at': now}]
            challenge.update(state='verified'); challenge.pop('code_hash', None)
            self.store.commit({ck: (rev, challenge), mk: (mr, addresses)})
            return self.view(actor)
        if operation == 'remove-reply-to':
            address = email(data.get('email')); mr, addresses = self.store.get(mk)
            if not address or not addresses or not any(v['email'] == address for v in addresses['items']):
                raise Denied('Verified reply address not found', 404)
            addresses['items'] = [v for v in addresses['items'] if v['email'] != address]; writes = {mk: (mr, addresses)}
            sk = (partition, 'SENDER'); sr, sender = self.store.get(sk)
            if sender and sender['reply_to'] == address:
                sender.update(state='pending', daily_limit=0, version=sender['version'] + 1); writes[sk] = (sr, sender)
            self.store.commit(writes)
            return {**self.view(actor), 'sender': sender}
        raise Denied('Unknown reply-address operation', 404)
