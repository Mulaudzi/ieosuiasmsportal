import assert from 'node:assert/strict';
import http from 'node:http';
import path from 'node:path';
import fs from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const out = path.resolve(process.argv[3] || path.join(root, '.sms-audit.local/sms-integration-api-20261105'));
const dist = path.resolve(process.argv[2] || path.join(out, 'candidate-dist'));
const mime = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.json': 'application/json' };
const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, 'http://localhost');
    const file = path.extname(url.pathname) ? path.resolve(dist, '.' + decodeURIComponent(url.pathname)) : path.join(dist, 'index.html');
    if (!file.startsWith(dist + path.sep)) { res.writeHead(404); res.end(); return; }
    const bytes = await fs.readFile(file);
    res.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' }); res.end(bytes);
  } catch { res.writeHead(404); res.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const receipt = { passed: false, checks: 0, cases: [], mocked_writes: 0, real_sms_sent: false, production_access: false, deployed: false };
const check = (value, message) => { assert.ok(value, message); receipt.checks++; };
try {
  for (const width of [1440, 768, 390, 320]) {
    const user = { id: 'api-ui-fixture', name: 'API Fixture', email: 'fixture@example.invalid', email_verified: true, is_active: true, role: 'user' };
    const context = await browser.newContext({ viewport: { width, height: 800 } });
    let mode = 'ready'; let apps = []; const writes = [];
    await context.addInitScript(u => { localStorage.setItem('auth_user', JSON.stringify(u)); localStorage.setItem('auth_token', 'synthetic-portal-session'); localStorage.setItem('auth_token_issued', String(Date.now())); localStorage.setItem('ieosuia_cookie_consent', '{}'); }, user);
    await context.route('**/*', async route => {
      const request = route.request(); const url = new URL(request.url()); const pathname = url.pathname;
      if (pathname.includes('/api/')) {
        let data = { success: true };
        if (pathname.endsWith('/auth/user')) data.user = user;
        else if (pathname.endsWith('/settings/profile')) Object.assign(data, { user, account: { company_name: 'Synthetic fixture' } });
        else if (pathname.endsWith('/wallet')) data.wallet = { sms_credits: 1000, balance: 350, reserved: 0 };
        else if (pathname.endsWith('/portal/overview')) Object.assign(data, { contacts: 1, bought_credits: true, sent_campaign: true, checked_delivery: true });
        else if (pathname.endsWith('/notifications')) Object.assign(data, { notifications: [], unread_count: 0 });
        else if (pathname.endsWith('/settings/api-integrations') && request.method() === 'GET') Object.assign(data, { available: mode !== 'disabled', can_issue_keys: mode === 'ready', connections: mode === 'disabled' ? [] : apps });
        else if (pathname.endsWith('/settings/api-integrations') && request.method() === 'POST') {
          const input = request.postDataJSON(); writes.push(input);
          apps = [{ id: 'fixture-connection', name: input.name, product: input.product, scopes: input.scopes, status: 'active', daily_credit_limit: input.daily_credit_limit, requests_per_minute: 60, admitted_credits_today: 0, keys: [{ id: 'fixture-key', created_at: 1, expires_at: 4102444800, revoked_at: null, last_used_at: null }] }];
          Object.assign(data, { connection_id: 'fixture-connection', credential: { id: 'fixture-key', token: 'synthetic-key-for-offline-ui-only', expires_at: 4102444800, shown_once: true } });
        } else if (request.method() !== 'GET') throw new Error('Unexpected synthetic mutation: ' + pathname);
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(data) }); return;
      }
      if (url.origin === base) { await route.continue(); return; }
      await route.abort();
    });
    const page = await context.newPage(); const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/settings?tab=api');
    await page.getByRole('heading', { name: 'API access', exact: true }).waitFor();
    const add = page.getByRole('button', { name: 'Add connection' }); await add.waitFor();
    check(await page.getByRole('tab', { name: 'API access', exact: true }).getAttribute('aria-selected') === 'true', `${width}: selected Settings API tab`);
    check(await add.isEnabled(), `${width}: verified fixture can issue keys`);
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${width}: no page overflow`);
    await add.click(); await page.getByLabel('Connection name').fill('Events production fixture');
    await page.getByLabel('Daily credit allowance').fill('250');
    await page.getByRole('button', { name: 'Create connection and key' }).click();
    const key = page.getByLabel('New API key'); await key.waitFor();
    check(await key.inputValue() === 'synthetic-key-for-offline-ui-only', `${width}: one-time key shown`);
    check(writes.length === 1 && writes[0].daily_credit_limit === 250 && !('user_id' in writes[0]), `${width}: no client-selected payer`);
    check(!JSON.stringify(await page.evaluate(() => ({ ...localStorage }))).includes('synthetic-key-for-offline-ui-only'), `${width}: no key persistence`);
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${width}: secret modal has no overflow`);
    await page.screenshot({ path: path.join(out, `api-secret-${width}.png`), fullPage: true });
    await page.getByRole('button', { name: 'I have saved the key' }).click();
    check(await key.count() === 0, `${width}: credential removed from DOM after acknowledgement`);
    await page.getByText('Events production fixture', { exact: true }).waitFor();
    mode = 'security'; await page.getByRole('button', { name: 'Refresh API connections' }).click();
    await page.getByRole('link', { name: 'enable the extra SMS Portal sign-in check' }).waitFor();
    check(await add.isDisabled() && await page.getByRole('button', { name: 'Revoke connection' }).isEnabled(), `${width}: step-up gate does not block emergency revocation`);
    await page.screenshot({ path: path.join(out, `api-settings-${width}.png`), fullPage: true });
    mode = 'disabled'; await page.getByRole('button', { name: 'Refresh API connections' }).click();
    await page.getByText(/awaiting its database setup and activation/).waitFor();
    check(await add.isDisabled(), `${width}: activation gate is clear and closed`);
    check(errors.length === 0, `${width}: no browser runtime errors`);
    receipt.mocked_writes += writes.length; receipt.cases.push({ width, secret_flow: true, disabled_and_step_up_states: true });
    await context.close();
  }
  receipt.passed = true;
} catch (error) { receipt.error = error.stack || String(error); process.exitCode = 1; }
finally {
  await browser.close(); await new Promise(resolve => server.close(resolve));
  await fs.writeFile(path.join(out, 'browser-verification.json'), JSON.stringify(receipt, null, 2));
  console.log(JSON.stringify({ ...receipt, cases: receipt.cases.length }, null, 2));
}
