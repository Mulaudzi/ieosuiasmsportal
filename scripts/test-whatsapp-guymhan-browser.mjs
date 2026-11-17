// Built frontend, real Chromium, synthetic API only. No external network is allowed.
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { createReadStream, existsSync, statSync, mkdirSync, writeFileSync } from 'node:fs';
import { resolve, extname, sep } from 'node:path';
const root = resolve(process.argv[2] || '.sms-audit.local/whatsapp-customer-connect-20261103/guymhan-release/source/dist');
const out = resolve(process.argv[3] || '.sms-audit.local/whatsapp-customer-connect-20261103/guymhan-release/browser');
assert.ok(existsSync(resolve(root, 'index.html')), 'Build validation output first');
mkdirSync(out, { recursive: true });
const types = { '.js': 'text/javascript', '.css': 'text/css', '.html': 'text/html', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
const server = createServer((request, response) => {
  const path = resolve(root, '.' + decodeURIComponent(new URL(request.url, 'http://localhost').pathname));
  if (path !== root && !path.startsWith(root + sep)) { response.writeHead(404).end(); return; }
  const file = existsSync(path) && statSync(path).isFile() ? path : resolve(root, 'index.html');
  response.writeHead(200, { 'Content-Type': types[extname(file)] || 'application/octet-stream' }); createReadStream(file).pipe(response);
});
await new Promise(done => server.listen(0, '127.0.0.1', done));
const hosted = process.argv[4];
if (hosted) assert.equal(hosted, 'https://sms.ieosuia.com');
const base = hosted || `http://127.0.0.1:${server.address().port}`;
let browser;
let checks = 0;
const check = (value, label) => { assert.ok(value, label); checks++; };
try {
  browser = await chromium.launch({ channel: 'chrome', headless: true });
  for (const [width, height] of [[1440, 1000], [375, 812]]) {
    const context = await browser.newContext({ viewport: { width, height } });
    const user = { id: 'whatsapp-browser-fixture', name: 'Fixture owner', email: 'fixture@example.invalid', email_verified: true, email_verified_at: '2026-01-01', role: 'admin' };
    const option = { phone_number_id: '222222222', waba_id: '111111111', display_phone_number: '+27 00 000 0000', verified_name: 'Fixture business', business_name: 'Fixture business' };
    let available = false;
    let pending = null;
    let connections = [];
    const writes = [];
    const errors = [];
    await context.addInitScript(value => {
      localStorage.setItem('auth_user', JSON.stringify(value)); localStorage.setItem('auth_token', 'OFFLINE-browser-fixture');
      localStorage.setItem('auth_token_issued', String(Date.now())); localStorage.setItem('ieosuia_cookie_consent', '{}'); sessionStorage.setItem('admin_session_timestamp', String(Date.now()));
    }, user);
    await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    await context.route('**/api/**', async route => {
      const request = route.request(); const url = new URL(request.url());
      let data = { success: true, data: [] };
      if (url.pathname.endsWith('/auth/user')) data = { success: true, user };
      else if (url.pathname.endsWith('/settings/profile')) data = { success: true, user, account: { company_name: 'Fixture business' } };
      else if (url.pathname.endsWith('/notifications')) data = { success: true, notifications: [], unread_count: 0 };
      else if (url.pathname.endsWith('/wallet')) data = { success: true, wallet: { balance: 0, sms_credits: 0 } };
      else if (url.pathname.endsWith('/whatsapp-connect.php')) {
        if (request.method() === 'POST') {
          const action = url.searchParams.get('action'); const body = request.postDataJSON(); writes.push({ action, body });
          if (action === 'confirm') { assert.equal(body.phone_number_id, option.phone_number_id); assert.equal(body.attempt, 'fixture-attempt'); pending = null; connections = [{ ...option, status: 'authorized', expires_at: 1805184000 }]; }
          else if (action === 'disconnect') connections = [{ ...option, status: 'disconnected', expires_at: 1805184000 }];
          else throw new Error('Unexpected browser mutation');
          data = { success: true, updated: true, sending_enabled: false };
        } else data = { success: true, available, sending_enabled: false, pending, connections };
      }
      await route.fulfill({ json: data });
    });
    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/guymhan/whatsapp');
    await page.getByText('Customer WhatsApp connections are awaiting setup. Please check back later.').waitFor();
    check(await page.getByRole('heading', { name: 'WhatsApp setup', exact: true }).isVisible(), 'Guymhan setup page opens in administrator layout');
    await page.goto(base + '/settings?tab=whatsapp');
    await page.waitForURL(base + '/guymhan/whatsapp');
    await page.getByRole('heading', { name: 'WhatsApp setup', exact: true }).waitFor();
    check(await page.getByRole('heading', { name: 'WhatsApp setup', exact: true }).isVisible(), 'legacy administrator Settings link redirects to Guymhan');
    if (width < 1024) await page.getByRole('button', { name: 'Open operations menu', exact: true }).click();
    const nav = page.getByRole('link', { name: 'WhatsApp setup Platform connection & pilot', exact: true });
    check(await nav.getAttribute('href') === '/guymhan/whatsapp', 'Guymhan navigation targets the platform setup page');
    if (width < 1024) await nav.click();
    check(writes.length === 0, 'loading inactive feature creates no changes');
    await page.screenshot({ path: resolve(out, `setup-${width}.png`), fullPage: true });
    available = true; pending = { attempt: 'fixture-attempt', options: [option], expires_at: 1800000600 };
    await page.reload(); await page.getByRole('heading', { name: 'Confirm your business number' }).waitFor();
    check(await page.getByRole('button', { name: 'Confirm connection', exact: true }).isDisabled(), 'selection required before confirmation');
    await page.getByLabel('Business number', { exact: true }).selectOption(option.phone_number_id);
    check(writes.length === 0, 'choosing phone alone does not save credentials');
    await page.screenshot({ path: resolve(out, `review-${width}.png`), fullPage: true });
    await page.getByRole('button', { name: 'Confirm connection', exact: true }).click();
    await page.getByText('Linked for setup', { exact: true }).waitFor();
    check(writes.length === 1 && writes[0].action === 'confirm', 'one explicit confirmation request');
    await page.getByRole('button', { name: `Disconnect ${option.display_phone_number}`, exact: true }).click();
    check(writes.length === 1, 'disconnect requires another confirmation');
    await page.getByRole('button', { name: 'Confirm disconnect', exact: true }).click();
    await page.getByText('Disconnected', { exact: true }).waitFor();
    check(writes.length === 2 && writes[1].action === 'disconnect', 'one confirmed disconnect request');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'no horizontal page overflow');
    check(errors.length === 0, 'no browser runtime exceptions');
    await context.close();
  }
  {
    const context = await browser.newContext();
    const user = { id: 'customer-guard-fixture', email: 'fixture@example.invalid', role: 'user', email_verified: true };
    let whatsappRequests = 0;
    await context.addInitScript(value => { localStorage.setItem('auth_user', JSON.stringify(value)); localStorage.setItem('auth_token', 'OFFLINE-guard-fixture'); localStorage.setItem('auth_token_issued', String(Date.now())); }, user);
    await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    await context.route('**/api/**', async route => { if (new URL(route.request().url()).pathname.endsWith('/whatsapp-connect.php')) whatsappRequests++; await route.fulfill({ json: { success: true, user } }); });
    const page = await context.newPage(); await page.goto(base + '/guymhan/whatsapp');
    await page.waitForURL(url => url.pathname === '/guymhan/login' || url.pathname === '/api/auth/ieosuia/start');
    check(whatsappRequests === 0, 'customer cannot load administrator WhatsApp controls');
    check(await page.getByRole('heading', { name: 'WhatsApp platform connection', exact: true }).count() === 0, 'customer sees no platform setup content');
    await context.close();
  }
  if (hosted) {
    const anonymous = await browser.newContext();
    const page = await anonymous.newPage();
    const response = await page.goto(hosted + '/api/whatsapp-meta-oauth.php');
    check(response.status() === 200, 'live callback landing HTTP success');
    check(await page.getByText(/No account has been linked/).isVisible(), 'live callback landing renders without starting signup');
    const boundary = await anonymous.request.get(hosted + '/api/whatsapp-connect.php');
    check(boundary.status() === 401, 'live authenticated API rejects anonymous request');
    await anonymous.close();
  }
  writeFileSync(resolve(out, 'result.json'), JSON.stringify({ passed: true, checks, scope: (hosted ? 'live hosted frontend' : 'built frontend') + ' + synthetic API; no real customer login or Meta calls', viewports: [1440, 375] }, null, 2) + '\n');
  console.log(`PASS: ${checks} Chromium interface checks at desktop/mobile widths (synthetic API only).`);
} finally {
  if (browser) await browser.close();
  await new Promise(done => server.close(done));
}
