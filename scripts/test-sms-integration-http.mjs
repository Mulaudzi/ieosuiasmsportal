import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const temporary = await mkdtemp(path.join(os.tmpdir(), 'sms-api-http-'));
const listener = net.createServer();
await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
const port = listener.address().port;
await new Promise(resolve => listener.close(resolve));
const fixture = spawn('C:/php8/php.exe', ['-S', `127.0.0.1:${port}`, 'api/tests/sms-integration-http-router.php'], { cwd: root, windowsHide: true, env: { ...process.env, SMS_API_HTTP_FIXTURE: '1', SMS_API_RATE_FIXTURE: temporary } });
let diagnostic = ''; fixture.stderr.on('data', data => { diagnostic += data.toString(); });
const base = `http://127.0.0.1:${port}/api`;
const token = 'smsk_' + 'a'.repeat(32) + '.' + 'b'.repeat(64); // Deliberately synthetic, no live credential.
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };
async function call(endpoint, options = {}) {
  const { expected = 200, auth = token, headers = {}, body, raw, method = body || raw ? 'POST' : 'GET' } = options;
  const response = await fetch(base + endpoint, { method, headers: { ...(auth ? { Authorization: `Bearer ${auth}` } : {}), ...(body || raw ? { 'Content-Type': 'application/json' } : {}), ...headers }, body: raw ?? (body ? JSON.stringify(body) : undefined) });
  const text = await response.text(); let data;
  try { data = JSON.parse(text); } catch { throw new Error(`Non-JSON fixture reply: ${response.status}; ${diagnostic.slice(-1500)}`); }
  check(response.status === expected, `${endpoint}: expected ${expected}, got ${response.status}: ${JSON.stringify(data)}`);
  check(!text.includes(token) && !text.includes('secret_hash') && !text.includes('Stack trace'), `${endpoint}: no secret/internal error leakage`);
  return { response, data };
}
try {
  let ready = false;
  for (let i = 0; i < 50; i++) {
    try { await fetch(base + '/v1/balance'); ready = true; break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); }
  }
  assert.ok(ready, 'Loopback fixture failed to start');
  await call('/v1/balance', { auth: '', expected: 401 });
  await call('/v1/balance', { auth: 'synthetic-portal-session', expected: 401 });
  await call('/settings/api-integrations', { expected: 401 });
  const status = await call('/settings/api-integrations', { auth: 'synthetic-portal-session' });
  check(status.data.available === true, 'Portal session can manage its own integrations');
  const balance = await call('/v1/balance'); check(balance.data.balance.available_credits === 1000, 'Existing real wallet conversion used');
  await call('/v1/usage');
  await call('/v1/balance', { headers: { Origin: 'https://website.example.invalid' }, expected: 403 });
  await call('/v1/balance?api_key=unsafe', { expected: 422 });
  await call('/v1/balance', { headers: { 'X-Synthetic-Http': '1' }, expected: 400 });
  await call('/v1/balance', { headers: { 'X-Synthetic-Disabled': '1' }, expected: 503 });
  const body = { content: 'HTTP synthetic fixture', recipients: ['0820000001'] };
  const quote = await call('/v1/sms/quotes', { body }); check(quote.data.quote.credits_required === 1 && quote.data.quote.binding === false, 'Quote is explicit and non-binding');
  await call('/v1/sms/quotes', { raw: '{', expected: 422 });
  await call('/v1/sms/quotes', { raw: '[]', expected: 422 });
  await call('/v1/sms/quotes', { raw: '{}', headers: { 'Content-Type': 'text/plain' }, expected: 415 });
  await call('/v1/sms/quotes', { raw: JSON.stringify({ content: 'x'.repeat(65537), recipients: ['0820000001'] }), expected: 413 });
  await call('/v1/sms/messages', { body, expected: 422 });
  const sent = await call('/v1/sms/messages', { body, headers: { 'Idempotency-Key': 'http-synthetic-request-001' }, expected: 202 });
  check(sent.data.message.status === 'queued' && sent.data.message.state === 'queued' && sent.data.message.complete === false && !('accepted' in sent.data.message) && sent.data.message.acceptance_is_not_delivery === true, '202 means queued, without internal breakdown');
  check(Object.keys(sent.data.message).sort().join(',') === ['id','reference','external_reference','status','state','complete','scheduled_at','created_at','recipients','credits_required','acceptance_is_not_delivery'].sort().join(','), 'External receipt has only the documented public fields');
  check(sent.response.headers.get('cache-control') === 'no-store', 'Response is not cached');
  const scheduled = await call('/v1/sms/messages', { body: { ...body, scheduled_at: '2099-01-01T11:00:00+02:00' }, headers: { 'Idempotency-Key': 'http-synthetic-schedule-001' }, expected: 202 });
  check(scheduled.data.message.status === 'queued' && scheduled.data.message.scheduled_at === '2099-01-01T11:00:00+02:00', 'HTTP schedule is preserved without leaking lifecycle state');
  for (const scenario of ['queued', 'sent', 'not_sent', 'partially_sent', 'held']) {
    const result = await call('/v1/sms/messages/' + 'd'.repeat(32), { headers: { 'X-Synthetic-Result': scenario } });
    check(result.data.message.status === (scenario === 'held' ? 'queued' : scenario), `GET publishes simple ${scenario} result`);
    check(!JSON.stringify(result.data).match(/PRIVATE|9999|last_error|campaign_id|held|delivered|failed_submissions/), 'Status response does not expose private diagnostics or breakdown');
  }
  const cancelled = await call('/v1/sms/messages/' + 'd'.repeat(32) + '/cancel', { method: 'POST' });
  check(cancelled.data.message.status === 'not_sent' && cancelled.data.message.complete, 'Cancellation returns the same simple result contract');
  await call('/v1/sms/messages', { body: { ...body, user_id: 2 }, headers: { 'Idempotency-Key': 'http-synthetic-unsafe-001' }, expected: 422 });
  await call('/v1/sms/messages/' + 'c'.repeat(32), { expected: 404 });
  await call('/v1/sms/messages/' + 'c'.repeat(32) + '/cancel', { method: 'POST', expected: 404 });
  const created = await call('/settings/api-integrations', { auth: 'synthetic-portal-session', body: { name: 'Created HTTP fixture', product: 'custom', scopes: ['sms:send'] }, expected: 201 });
  check(created.data.credential.shown_once === true && created.response.headers.get('cache-control') === 'no-store', 'Credential is shown only on creation with no-store headers');
  console.log(JSON.stringify({ passed: true, checks, real_sms_sent: false, production_access: false, fixture_only: true }));
} finally {
  fixture.kill(); await new Promise(resolve => fixture.once('exit', resolve));
}
