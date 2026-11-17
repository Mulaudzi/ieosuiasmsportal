import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import { chromium } from 'playwright';

const out = path.resolve(process.argv[3] || '.sms-audit.local/settings-redesign-20261105');
const dist = path.resolve(process.argv[2] || path.join(out, 'frontend'));
const mime = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.json': 'application/json' };
const server = createServer(async (req, res) => {
  try {
    const pathname = new URL(req.url, 'http://localhost').pathname;
    const file = path.extname(pathname) ? path.resolve(dist, '.' + pathname) : path.join(dist, 'app.html');
    if (!file.startsWith(dist + path.sep)) throw Error('Outside test build');
    const bytes = await fs.readFile(file); res.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' }); res.end(bytes);
  } catch { res.writeHead(404); res.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const liveBase = process.argv[4];
if (liveBase && liveBase !== 'https://sms.ieosuia.com') throw Error('Unapproved live smoke-check origin');
const base = liveBase || `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const receipt = { passed: false, checks: 0, cases: [], production_access: !!liveBase, deployed: !!liveBase, synthetic_identity: true, all_api_calls_intercepted: true, actual_backend_writes: 0, real_emails_sent: false, real_keys_created: false };
const check = (ok, message) => { assert.ok(ok, message); receipt.checks++; };
let activePage;
const identity = { id: 'settings-browser-fixture', name: 'Lufuno Fixture', email: 'owner@example.invalid', phone: '0820000001', role: 'user', email_verified: true, email_verified_at: '2026-11-01' };
const connections = [{ id: 'synthetic-connection', name: 'Blu-T production', product: 'website', status: 'active', daily_credit_limit: 1000, requests_per_minute: 60, admitted_credits_today: 17, scopes: ['sms:quote', 'sms:send', 'sms:read'], keys: [{ id: 'synthetic-key-123456', expires_at: 4102444800, created_at: 1, revoked_at: null, last_used_at: null }] }];
try {
  for (const [width, dark] of (liveBase ? [[1440, false], [390, false], [390, true]] : [[1440, false], [1024, false], [768, false], [390, false], [320, false], [1440, true], [390, true]])) {
    const context = await browser.newContext({ viewport: { width, height: 980 } });
    const user = { ...identity }, business = { company_name: 'Fixture Shop', industry: 'Retail', address: 'Example Street', website: 'https://example.invalid' };
    const errors = [], calls = [], writes = [];
    await context.addInitScript(({ user, dark }) => {
      localStorage.setItem('auth_token', 'synthetic-only-not-a-live-token'); localStorage.setItem('auth_user', JSON.stringify(user)); localStorage.setItem('auth_token_issued', String(Date.now()));
      localStorage.setItem(`sms-ui:${user.id}:dark`, String(dark)); localStorage.setItem(`sms-ui:${user.id}:dashboard-tour-seen`, 'true'); sessionStorage.setItem(`sms-portal-entered:${user.id}`, '1'); localStorage.setItem('ieosuia_cookie_consent', '{}');
    }, { user, dark });
    await context.route('**/*', async route => {
      const request = route.request(), url = new URL(request.url()), endpoint = url.pathname;
      if (endpoint.startsWith('/api/')) {
        calls.push(endpoint); let result = { success: true };
        if (request.method() !== 'GET') {
          writes.push({ endpoint, method: request.method(), body: request.postDataJSON() });
          assert.ok(['PUT'].includes(request.method()) && ['/api/settings/profile', '/api/auth/user', '/api/settings/sms'].includes(endpoint), 'Only explicit mocked profile/business/SMS saves are allowed');
          const body = request.postDataJSON(); if (endpoint.endsWith('/settings/profile')) { if (body.company_name !== undefined) Object.assign(business, body); else Object.assign(user, body); } else if (endpoint.endsWith('/auth/user')) Object.assign(user, body);
          result = { ...result, user, account: business };
        } else if (endpoint.endsWith('/auth/user') || endpoint.endsWith('/settings/profile')) result = { ...result, user, account: business, wallet: { balance: 350, reserved: 0 } };
        else if (endpoint.endsWith('/settings/api-integrations')) result = { ...result, available: true, can_issue_keys: true, connections };
        else if (endpoint.endsWith('/settings/login-security')) result = { ...result, available: true, enrollment_enabled: true, enabled: false, recovery_codes_remaining: 0 };
        else if (endpoint.endsWith('/settings/sms')) result = { ...result, opt_out_text: 'Reply STOP to unsubscribe' };
        else if (endpoint.endsWith('/whatsapp-connect.php')) result = { ...result, available: true, pending: null, connections: [] };
        else if (endpoint.endsWith('/portal/overview')) result = { ...result, contacts: 4, bought_credits: true, sent_campaign: false, checked_delivery: false };
        else if (endpoint.endsWith('/notifications')) result = { ...result, notifications: [], unread_count: 0 };
        else result = { ...result, balance: 350, available_credits: 1000, reserved_credits: 0 };
        await route.fulfill({ json: result }); return;
      }
      if (url.origin !== base) { await route.abort(); return; } await route.continue();
    });
    const page = await context.newPage(); activePage = page; page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/settings', { waitUntil: 'networkidle' });
    await page.getByRole('heading', { level: 2, name: 'Account overview' }).waitFor(); await page.getByRole('heading', { name: user.name, exact: true }).waitFor();
    check(!calls.some(endpoint => endpoint.includes('api-integrations') || endpoint.includes('login-security')), `${width}/${dark}: overview is not an integration/security setup page`);
    check(writes.length === 0, `${width}/${dark}: opening settings is read only`);
    check(await page.getByRole('tablist').count() === 0, `${width}/${dark}: crowded tab bar removed`);
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${width}/${dark}: overview fits screen`);
    check(width >= 1200 ? await page.getByRole('navigation', { name: 'Settings sections' }).isVisible() : await page.getByLabel('Settings section', { exact: true }).isVisible(), `${width}/${dark}: appropriate settings navigation`);
    await page.screenshot({ path: path.join(out, `settings-overview-${width}-${dark ? 'dark' : 'light'}.png`), fullPage: true });

    const choose = async (value, label) => {
      if (width < 1200) await page.getByLabel('Settings section', { exact: true }).selectOption(value);
      else await page.getByRole('navigation', { name: 'Settings sections' }).getByRole('link', { name: label, exact: true }).click();
      await page.waitForURL('**tab=' + value);
    };
    await choose('profile', 'My profile'); await page.getByRole('button', { name: 'Edit profile', exact: true }).click();
    await page.getByLabel('Full name', { exact: true }).fill('Updated Fixture'); await page.getByRole('button', { name: 'Save profile', exact: true }).click();
    await page.getByRole('button', { name: 'Edit profile', exact: true }).waitFor();
    check(writes.some(write => write.endpoint.endsWith('/settings/profile') && write.body.name === 'Updated Fixture'), `${width}/${dark}: profile saves using existing API`);
    check(await page.getByText('Updated Fixture', { exact: true }).count() >= 1, `${width}/${dark}: profile refreshes after save`);
    await choose('organization', 'Business details'); await page.getByLabel('Organization name', { exact: true }).fill('Updated Shop'); await page.getByRole('button', { name: 'Save business details', exact: true }).click();
    await page.getByText('Business details saved', { exact: true }).first().waitFor();
    check(writes.some(write => write.body.company_name === 'Updated Shop'), `${width}/${dark}: business details save`);
    await choose('security', 'Sign-in & security'); await page.getByRole('button', { name: 'Enable extra sign-in check', exact: true }).waitFor();
    check(await page.getByRole('heading', { name: 'Email sign-in verification' }).count() === 1, `${width}/${dark}: email check has clear identity`);
    check(await page.getByRole('link', { name: 'Change IEOSUIA password', exact: true }).getAttribute('href') === 'https://auth.ieosuia.com/forgot-password', `${width}/${dark}: existing password action preserved`);
    check(!(await page.getByText('What happens when I enable this?', { exact: true }).evaluate(element => element.parentElement.open)), `${width}/${dark}: optional explanation collapsed`);
    await page.screenshot({ path: path.join(out, `settings-security-${width}-${dark ? 'dark' : 'light'}.png`), fullPage: true });
    await choose('compliance', 'SMS preferences'); await page.getByLabel('Default Opt-Out Text', { exact: true }).waitFor();
    check(await page.locator('.settings-content [role=switch]').count() === 0, `${width}/${dark}: no fake compliance toggles`);
    await choose('integrations', 'Connected apps');
    await page.getByRole('link', { name: /Manage API connections/ }).click(); await page.waitForURL('**tab=api');
    await page.getByRole('heading', { name: 'Blu-T production', exact: true }).waitFor();
    check(!(await page.locator('.settings-api-guide').evaluate(element => element.open)), `${width}/${dark}: API setup hidden until requested`);
    await page.getByText('Keys & permissions (1)', { exact: true }).click();
    check(await page.getByRole('button', { name: /Revoke key syntheti/ }).isVisible(), `${width}/${dark}: key actions still accessible`);
    await page.getByRole('button', { name: 'Add connection', exact: true }).click(); await page.getByRole('dialog').waitFor();
    check(await page.getByLabel('Connection name', { exact: true }).isVisible(), `${width}/${dark}: existing API creation form accessible`);
    await page.getByRole('button', { name: 'Back', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${width}/${dark}: connected app view fits screen`);
    await page.screenshot({ path: path.join(out, `settings-api-${width}-${dark ? 'dark' : 'light'}.png`), fullPage: true });
    await page.getByRole('link', { name: 'Back to connected apps', exact: true }).click(); await page.getByRole('link', { name: /Manage WhatsApp connection/ }).click();
    await page.getByRole('button', { name: 'Connect WhatsApp Business', exact: true }).waitFor();
    check(page.url().includes('tab=whatsapp'), `${width}/${dark}: WhatsApp remains in its own view`);
    await choose('get-started', 'Help & getting started'); await page.getByRole('link', { name: /API developer guide/ }).waitFor();
    check(await page.getByRole('link', { name: /API developer guide/ }).getAttribute('href') === '/developers', `${width}/${dark}: developer help stays discoverable`);
    check(!errors.length, `${width}/${dark}: no runtime errors: ${errors.join('; ')}`);
    check(writes.length === 3 && writes.every(write => write.method === 'PUT'), `${width}/${dark}: no extra mutations, keys, messages or security changes`);
    receipt.cases.push({ width, dark, checked_sections: 9, synthetic_saves: writes.length }); await context.close();
  }
  receipt.passed = true;
} catch (error) { receipt.error = error.stack || String(error); process.exitCode = 1; if (activePage && !activePage.isClosed()) { receipt.url = activePage.url(); receipt.visible_text = (await activePage.locator('body').innerText()).slice(0, 6000); await activePage.screenshot({ path: path.join(out, 'settings-failure.png') }); } }
finally { await fs.writeFile(path.join(out, 'browser-verification.json'), JSON.stringify(receipt, null, 2)); await browser.close(); await new Promise(resolve => server.close(resolve)); }
console.log(JSON.stringify(receipt));
