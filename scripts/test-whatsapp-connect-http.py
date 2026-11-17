"""Exercise the real PHP entry points with fixture auth/SQLite only, never live settings/Meta.

The PDO adapter translates Auth's pre-existing MySQL-only revocation DDL for SQLite.
This is HTTP/auth/header coverage, not a MySQL or real Meta onboarding acceptance test.
"""
import base64
import hashlib
import hmac
import http.client
import json
from pathlib import Path
import shutil
import sqlite3
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
AUDIT = ROOT / '.sms-audit.local' / 'whatsapp-customer-connect-20261103'
AUDIT.mkdir(parents=True, exist_ok=True)
SECRET = 'OFFLINE-HTTP-JWT-FIXTURE-' + 'x' * 40
ENCODE = lambda value: base64.urlsafe_b64encode(value).rstrip(b'=')
payload = ENCODE(json.dumps({'sub': 1, 'ver': 1, 'exp': int(time.time()) + 3600}).encode())
signed = ENCODE(b'{"alg":"HS256","typ":"JWT"}') + b'.' + payload
TOKEN = (signed + b'.' + ENCODE(hmac.new(SECRET.encode(), signed, hashlib.sha256).digest())).decode()
checks = 0


def check(condition, name):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(name)


with tempfile.TemporaryDirectory(prefix='http-fixture-', dir=AUDIT) as temporary:
    fixture = Path(temporary).resolve()
    assert fixture.parent == AUDIT.resolve()  # Verified bounded cleanup by TemporaryDirectory.
    files = [
        'api/whatsapp-connect.php', 'api/whatsapp-meta-oauth.php',
        'api/domain/MetaWhatsAppConnectConfig.php', 'api/domain/MetaWhatsAppConnections.php',
        'api/domain/SmsLoginSecurity.php', 'api/services/MetaWhatsAppOnboardingClient.php',
        'api/domain/CentralAdminAccess.php',
        'api/migrations/026_whatsapp_customer_connections.php',
        'api/core/QueryBuilder.php', 'api/core/Request.php', 'api/core/Response.php',
        'api/core/JWT.php', 'api/core/Auth.php',
        'api/bin/whatsapp-connect-cleanup.php',
    ]
    for relative in files:
        destination = fixture / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT / relative, destination)
    (fixture / 'api/config').mkdir()
    (fixture / 'api/config/database.php').write_text('''<?php
function env(string $key,$default=null) {
 $values = ['JWT_SECRET'=>'%s','SMS_LOGIN_SECURITY_REQUIRED'=>'false',
 'WHATSAPP_CONNECT_ENABLED'=>file_exists(__DIR__.'/enabled')?'true':'false',
 'WHATSAPP_CONNECT_ADMIN_PILOT_ONLY'=>file_exists(__DIR__.'/pilot-only')?'true':'false',
 'WHATSAPP_CONNECT_APP_ID'=>'123456789','WHATSAPP_META_APP_SECRET'=>str_repeat('b',32),
 'WHATSAPP_META_GRAPH_VERSION'=>'v25.0','WHATSAPP_CONNECT_CONFIG_ID'=>'987654321',
 'WHATSAPP_CONNECT_REDIRECT_URI'=>'https://portal.example/api/whatsapp-meta-oauth.php',
 'WHATSAPP_CONNECT_TOKEN_KEY'=>base64_encode(str_repeat('k',32))]; return $values[$key]??$default;
}
final class FixturePDO extends PDO {
 public function exec(string $statement):int|false {
  if(str_starts_with($statement,'CREATE TABLE IF NOT EXISTS revoked_auth_tokens'))
   $statement='CREATE TABLE IF NOT EXISTS revoked_auth_tokens (token_hash TEXT PRIMARY KEY,expires_at TEXT)';
  return parent::exec($statement);
 }
}
function db():PDO {
 static $db=null;
 if($db===null){$db=new FixturePDO('sqlite:'.__DIR__.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->sqliteCreateFunction('NOW',static fn()=>date('Y-m-d H:i:s'));
 $db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY,name TEXT,email TEXT,is_active INTEGER,auth_version INTEGER,role TEXT)');
 $db->exec("INSERT OR IGNORE INTO users VALUES (1,'Fixture','fixture@example.invalid',1,1,'user')");
 $migrate=require __DIR__.'/../migrations/026_whatsapp_customer_connections.php';$migrate($db);}
 return $db;
}
''' % SECRET, encoding='utf-8')
    with socket.socket() as reserve:
        reserve.bind(('127.0.0.1', 0))
        port = reserve.getsockname()[1]
    flags = getattr(subprocess, 'CREATE_NO_WINDOW', 0)
    with (fixture / 'server.log').open('wb') as logs:
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(fixture)],
                                  stdout=logs, stderr=logs, creationflags=flags)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.2):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise RuntimeError('Fixture HTTP server did not start')

            def request(path='/api/whatsapp-connect.php', method='GET', body=None, authenticated=True, extra=None):
                headers = {'Authorization': 'Bearer ' + TOKEN} if authenticated else {}
                if method == 'POST':
                    headers.update({'Origin': 'https://portal.example', 'Content-Type': 'application/json'})
                    if body is None:
                        body = '{}'
                headers.update(extra or {})
                connection = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
                connection.request(method, path, body=body, headers=headers)
                response = connection.getresponse()
                result = response.status, dict(response.getheaders()), response.read().decode()
                connection.close()
                return result

            status, headers, body = request(authenticated=False)
            check(status == 401, 'anonymous status rejected')
            check('no-store' in headers.get('Cache-Control', ''), 'private status not cached')
            check(request(extra={'Authorization': 'Bearer invalid'})[0] == 401, 'invalid JWT rejected')
            status, _, body = request()
            check(status == 200 and json.loads(body)['available'] is False, 'feature disabled by default')
            check(request('/api/whatsapp-connect.php?action=start', 'POST')[0] == 503, 'disabled signup cannot start')
            status, headers, body = request('/api/whatsapp-meta-oauth.php?code=do-not-echo&state=bad')
            check(status == 503 and 'do-not-echo' not in body, 'disabled callback fails closed without echo')
            check(headers.get('Referrer-Policy') == 'no-referrer' and "default-src 'none'" in headers.get('Content-Security-Policy', ''), 'callback referrer and content isolation')
            status, _, body = request('/api/whatsapp-meta-oauth.php')
            check(status == 200 and 'No account has been linked' in body, 'bare redirect URI is a harmless installed landing page')
            (fixture / 'api/config/enabled').touch()
            status, _, body = request()
            check(status == 200 and json.loads(body)['available'] is True, 'fixture setup readiness')
            check(request('/api/whatsapp-connect.php?action=start')[0] == 405, 'GET cannot start connection')
            check(request('/api/whatsapp-connect.php?action=start', 'POST', extra={'Origin': 'https://evil.example'})[0] == 403, 'cross-origin mutation blocked')
            check(request('/api/whatsapp-connect.php?action=start', 'POST', extra={'Origin': ''})[0] == 403, 'missing origin mutation blocked')
            check(request('/api/whatsapp-connect.php?action=start', 'POST', extra={'Content-Type': 'text/plain'})[0] == 403, 'simple cross-site form body blocked')
            check(request('/api/whatsapp-connect.php?action=start', 'POST', body='x' * 8193)[0] == 413, 'oversized body blocked')
            check(request('/api/whatsapp-connect.php?action=start', 'POST', body='{')[0] == 422, 'malformed JSON blocked')
            check(request('/api/whatsapp-connect.php?action=confirm', 'POST', body='{"attempt":[],"phone_number_id":[]}')[0] == 422, 'array-shaped identifiers blocked')
            status, headers, body = request('/api/whatsapp-connect.php?action=start', 'POST')
            check(status == 200 and json.loads(body)['authorize_url'].startswith('https://business.facebook.com/messaging/whatsapp/onboard/'), 'authenticated start returns Meta URL')
            cookie = headers.get('Set-Cookie', '')
            check(all(value in cookie.lower() for value in ['__host-ieosuia_wa_connect=', 'secure', 'httponly', 'samesite=lax', 'path=/']) and 'domain=' not in cookie.lower(), 'host-only secure HttpOnly Lax binding cookie')
            from urllib.parse import parse_qs, urlparse
            state = parse_qs(urlparse(json.loads(body)['authorize_url']).query)['state'][0]
            status, _, _ = request('/api/whatsapp-meta-oauth.php?code=fixture&state=' + state)
            check(status == 400, 'callback without matching browser cookie blocked before Graph')
            browser_headers = {'Cookie': cookie.split(';', 1)[0]}
            status, headers, body = request('/api/whatsapp-meta-oauth.php?state=' + state + '&error=access_denied&error_description=do-not-echo', extra=browser_headers)
            check(status == 400 and 'do-not-echo' not in body and state not in body, 'cancel response sanitizes provider data')
            check(request('/api/whatsapp-meta-oauth.php?state=' + state + '&code=fixture', extra=browser_headers)[0] == 409, 'cancelled callback cannot be replayed')
            check(request('/api/whatsapp-meta-oauth.php', 'POST')[0] == 405, 'callback method restricted')
            status, _, body = request()
            check(json.loads(body)['connections'] == [] and json.loads(body)['sending_enabled'] is False, 'HTTP checks create no connection or sending capability')
            with sqlite3.connect(fixture / 'api/config/fixture.sqlite') as database:
                database.execute('INSERT INTO whatsapp_connect_intents (state_hash,owner_id,auth_version,session_hash,browser_hash,status,created_at,expires_at,token_cipher) VALUES (?,?,?,?,?,?,?,?,?)',
                                 ('e' * 64, 1, 1, 'a' * 64, 'b' * 64, 'pending', int(time.time()) - 600, int(time.time()) - 1, 'OFFLINE-expired-cipher'))
            database.close()
            result = subprocess.run(['php', str(fixture / 'api/bin/whatsapp-connect-cleanup.php')], capture_output=True, text=True, creationflags=flags)
            check(result.returncode == 0 and json.loads(result.stdout)['expired_intents'] == 1, 'scheduled cleanup removes expired grants')
            with sqlite3.connect(fixture / 'api/config/fixture.sqlite') as database:
                check(database.execute('SELECT token_cipher,status FROM whatsapp_connect_intents WHERE state_hash = ?', ('e' * 64,)).fetchone() == (None, 'expired'), 'expired grant ciphertext erased')
            database.close()
            result = subprocess.run(['php', str(fixture / 'api/bin/whatsapp-connect-cleanup.php')], capture_output=True, text=True, creationflags=flags)
            check(result.returncode == 0 and json.loads(result.stdout)['expired_intents'] == 0, 'scheduled cleanup idempotent')
            (fixture / 'api/config/pilot-only').touch()
            status, _, body = request()
            check(status == 200 and json.loads(body)['available'] is False, 'pilot connection hidden from customer accounts')
            check(request('/api/whatsapp-connect.php?action=start', 'POST')[0] == 503, 'pilot start rejects customer JWT')
            with sqlite3.connect(fixture / 'api/config/fixture.sqlite') as database:
                database.execute("UPDATE users SET role = 'admin' WHERE id = 1")
            database.close()
            status, _, body = request()
            check(status == 200 and json.loads(body)['available'] is True, 'pilot setup available to verified administrator JWT')
        finally:
            server.terminate()
            server.wait(timeout=5)

print(f'PASS: {checks} HTTP/auth/header checks (fixture SQLite; no live environment or Meta calls).')
