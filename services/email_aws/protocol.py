"""One signed, bounded server-to-server request format shared with the PHP bridge."""
import hashlib
import hmac
import json
import re
import secrets
import time
from urllib.parse import urlsplit
from store import Conflict


class Denied(Exception):
    def __init__(self, message, status=422):
        self.status = status
        super().__init__(message)


def encoded(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=True).encode()


def digest(value):
    return hashlib.sha256(encoded(value)).hexdigest()


def canonical(method, path, audience, timestamp, nonce, body):
    if method != 'POST' or not re.fullmatch(r'/[a-z0-9/_.-]{1,150}', path) or '..' in path or len(body) > 400000:
        raise Denied('Invalid signed request', 400)
    return '\n'.join(['IEOSUIA-EMAIL-1', audience, method, path, str(timestamp), nonce, hashlib.sha256(body).hexdigest()]).encode()


def sign(key, audience, path, body, now=None):
    timestamp = str(int(time.time() if now is None else now))
    nonce = secrets.token_hex(20)
    signature = hmac.new(key.encode(), canonical('POST', path, audience, timestamp, nonce, body), hashlib.sha256).hexdigest()
    return {'Content-Type': 'application/json', 'X-Email-Key': 'v1', 'X-Email-Time': timestamp, 'X-Email-Nonce': nonce, 'X-Email-Signature': signature}


def verify(store, keys, audience, path, body, headers, now):
    headers = {k.lower(): v for k, v in headers.items()}
    key_id, timestamp, nonce, signature = [headers.get(k, '') for k in ['x-email-key', 'x-email-time', 'x-email-nonce', 'x-email-signature']]
    if key_id not in keys or not re.fullmatch(r'[0-9]{10}', timestamp) or abs(now - int(timestamp)) > 120 or not re.fullmatch(r'[a-f0-9]{40}', nonce) or not re.fullmatch(r'[a-f0-9]{64}', signature):
        raise Denied('Service authentication failed', 401)
    expected = hmac.new(keys[key_id].encode(), canonical('POST', path, audience, timestamp, nonce, body), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature):
        raise Denied('Service authentication failed', 401)
    try:
        store.commit({('NONCES', key_id + ':' + nonce): (0, {'_ttl': int(now) + 600})})
    except Conflict:
        raise Denied('Replayed service request', 409) from None


def text(value, minimum, maximum, multiline=False):
    if not isinstance(value, str):
        raise Denied('A text value is required')
    value = value.strip().replace('\r\n', '\n')
    if not minimum <= len(value.encode()) <= maximum or re.search(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]' if multiline else r'[\x00-\x1f\x7f]', value):
        raise Denied('Check the text length and characters')
    return value


def email(value):
    if not isinstance(value, str):
        return None
    value = value.strip().lower()
    return value if len(value) <= 254 and re.fullmatch(r"[a-z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,63}", value) else None


def public_url(value):
    try:
        if not isinstance(value, str) or len(value.encode()) > 1000 or re.search(r'[\s\x00-\x1f\x7f]', value):
            raise ValueError()
        p = urlsplit(value)
        host = p.hostname or ''
        if p.scheme != 'https' or not re.fullmatch(r'[a-z0-9.-]+\.[a-z]{2,63}', host) or p.username or p.password or p.port not in [None, 443] or host.endswith(('.local', '.localhost', '.internal', '.test', '.invalid')):
            raise ValueError()
    except ValueError:
        raise Denied('Use full public HTTPS links without embedded credentials or custom ports') from None
    return value


def content(data):
    if any(k in data for k in ['html', 'headers', 'attachments', 'from']):
        raise Denied('Custom HTML, headers, attachments and From addresses are unsupported')
    result = {'name': text(data.get('name', ''), 0, 100), 'subject': text(data.get('subject'), 1, 180), 'body': text(data.get('body'), 1, 20000, True)}
    if 'design' in data:
        from presentation import design
        result['design'] = design(data['design'])
        if result['design']['button_url']:
            public_url(result['design']['button_url'])
    if 'attachment_ids' in data:
        from presentation import asset_id
        if not isinstance(data['attachment_ids'], list) or len(data['attachment_ids']) > 3:
            raise Denied('Choose at most three attachments')
        result['attachment_ids'] = [asset_id(v) for v in data['attachment_ids']]
    for url in re.findall(r'(?:https?://|www\.)[^\s<>]+', result['body'] + '\n' + result.get('design', {}).get('button_url', ''), flags=re.I):
        public_url(url.rstrip('.,;!)'))
    return result
