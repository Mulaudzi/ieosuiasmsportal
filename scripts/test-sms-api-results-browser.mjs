import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import { chromium } from 'playwright';

const out = path.resolve(process.argv[3] || '.sms-audit.local/api-sending-results-20261105');
const dist = path.resolve(process.argv[2] || path.join(out, 'frontend'));
const mime = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.json': 'application/json' };
const server = createServer(async (req, res) => {
  try {
    const pathname = new URL(req.url, 'http://localhost').pathname;
    const file = path.extname(pathname) ? path.resolve(dist, '.' + pathname) : path.join(dist, 'index.html');
    if (!file.startsWith(dist + path.sep)) throw Error('Outside test assets');
    const bytes = await fs.readFile(file);
    res.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' }); res.end(bytes);
  } catch { res.writeHead(404); res.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const receipt = { passed: false, checks: 0, cases: [], production_access: false, deployed: false, real_sms_sent: false, live_credentials_used: false };
let activePage; const diagnostics = [];
const check = (ok, label) => { assert.ok(ok, label); receipt.checks++; };
const user = { id: 'api-results-fixture', name: 'Fixture Owner', email: 'owner@example.invalid', email_verified: true, is_active: true, role: 'user' };
const campaign = { id: '1', name: 'API | Blu-T | 05 Nov 2026 12:00 SAST | EXAMPLE', content: 'Synthetic result only', state: 'completed', recipient_count: 2, sent_count: 2, delivered_count: 1, failed_count: 1, failed_submission_count: 0, skipped_count: 0, queued_count: 0, processing_count: 0, awaiting_delivery_count: 0, dlr_unavailable_count: 0, credits_used: 2, total_segments: 2, estimated_cost: .7, actual_cost: .7, created_at: '2026-11-05 12:00:00', started_at: '2026-11-05 12:00:10', completed_at: '2026-11-05 12:00:12', source: { type: 'api', connection_name: 'Blu-T production', reference: 'API-EXAMPLE-NOT-A-CREDENTIAL', external_reference: 'blu-t:order:123:ready' } };
try {
  for (const width of [1440, 768, 390, 320]) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    const errors = [], calls = []; diagnostics.push({ width, errors, calls });
    await context.addInitScript(() => localStorage.setItem('ieosuia_cookie_consent', '{}'));
    await context.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.origin !== base) { await route.abort(); return; }
      if (url.pathname.startsWith('/api/')) { calls.push(url.pathname); await route.abort(); return; }
      await route.continue();
    });
    const page = await context.newPage(); activePage = page; page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/developers', { waitUntil: 'networkidle' });
    await page.getByRole('table', { name: 'Public sending statuses' }).waitFor();
    for (const status of ['queued', 'sent', 'not_sent', 'partially_sent']) check((await page.getByRole('table', { name: 'Public sending statuses' }).innerText()).includes(status), `${width}: ${status} documented`);
    const example = JSON.parse(await page.locator('#reference pre code').filter({ hasText: '"complete": false' }).innerText());
    check(example.message.status === 'queued' && !example.message.complete, `${width}: example uses public contract`);
    check(!/LogicSMS|9999|secret_hash|provider postback|worker capacity|failed_submissions/.test(await page.getByRole('main').innerText()), `${width}: no internal diagnostics in public guide`);
    check(await page.locator('#reference').getByRole('link', { name: 'SMS Campaigns', exact: true }).getAttribute('href') === '/sms-campaigns', `${width}: portal click-through`);
    for (const language of ['cURL', 'Node.js', 'Python', 'PHP', 'C# / .NET', 'Java']) {
      await page.getByRole('tab', { name: language, exact: true }).click();
      check((await page.getByRole('tabpanel').innerText()).includes('/sms/messages'), `${width}: ${language} example retained`);
    }
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${width}: guide fits screen`);
    check(!calls.length && !errors.length, `${width}: no API calls/runtime errors on public guide`);
    await page.screenshot({ path: path.join(out, `results-guide-${width}.png`) });
    await context.close();

    const owner = await browser.newContext({ viewport: { width, height: 900 } });
    const ownerErrors = [], requests = []; diagnostics.push({ width, ownerErrors, requests });
    await owner.addInitScript(user => {
      localStorage.setItem('auth_token', 'synthetic-only-not-a-live-token');
      localStorage.setItem('auth_user', JSON.stringify(user)); localStorage.setItem('auth_token_issued', String(Date.now()));
      localStorage.setItem(`sms-ui:${user.id}:dashboard-tour-seen`, 'true'); sessionStorage.setItem(`sms-portal-entered:${user.id}`, '1');
      localStorage.setItem('ieosuia_cookie_consent', '{}');
    }, user);
    await owner.route('**/*', async route => {
      const request = route.request(), url = new URL(request.url()), endpoint = url.pathname;
      if (endpoint.startsWith('/api/')) {
        requests.push(request.method()); assert.equal(request.method(), 'GET', 'Browser checks must never mutate');
        let payload = { success: true };
        if (endpoint.endsWith('/auth/user')) payload = { ...payload, user, wallet: { balance: 350, reserved: 0 } };
        else if (endpoint.endsWith('/sms/campaigns-v2/1/recipient-view')) payload = { ...payload, total: 2, rows: [
          { id: '1', contact: 'Fixture contact A', number: '+27820000001', state: 'delivered', status: 'Delivered', segments: 1, sent: '5 Nov 2026, 12:00', result: 'Delivery confirmed by the mobile network.' },
          { id: '2', contact: 'Fixture contact B', number: '+27820000002', state: 'failed', technical_status: 'FAILED', status: 'Not Delivered', segments: 1, sent: '5 Nov 2026, 12:00', result: 'The mobile network reported that your SMS could not be delivered.' },
        ] };
        else if (endpoint.endsWith('/sms/campaigns-v2/1')) payload = { ...payload, campaign };
        else if (endpoint.endsWith('/sms/campaigns-v2')) payload = { ...payload, campaigns: [campaign], stats: { total: 1, sent: 2, scheduled: 0, credits_used: 2 }, status_counts: { all: 1, completed: 1 } };
        else if (endpoint.endsWith('/notifications')) payload = { ...payload, notifications: [], unread_count: 0 };
        else payload = { ...payload, balance: 350, available_credits: 1000, drafts: [], groups: [], contacts: [] };
        await route.fulfill({ json: payload }); return;
      }
      if (url.origin !== base) { await route.abort(); return; } await route.continue();
    });
    const dashboard = await owner.newPage(); activePage = dashboard; dashboard.on('pageerror', error => ownerErrors.push(error.message));
    await dashboard.goto(base + '/sms-campaigns', { waitUntil: 'networkidle' });
    await dashboard.getByLabel('API campaign source').waitFor();
    check((await dashboard.getByLabel('API campaign source').innerText()).includes('Blu-T production'), `${width}: campaign list shows connection`);
    check(!(await dashboard.getByLabel('API campaign source').innerText()).includes('blu-t:order'), `${width}: list stays compact`);
    await dashboard.goto(base + '/sms-campaigns/1', { waitUntil: 'networkidle' });
    await dashboard.getByText('API reference: ' + campaign.source.reference, { exact: true }).waitFor();
    check(await dashboard.getByText('Application reference: blu-t:order:123:ready', { exact: true }).count() === 1, `${width}: detail correlates the original business job`);
    check(await dashboard.getByRole('heading', { name: 'Recipient delivery details', exact: true }).count() === 1, `${width}: existing detailed delivery view remains`);
    check(await dashboard.getByText('Fixture contact A', { exact: true }).count() === 1, `${width}: recipient data retained`);
    check(await dashboard.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${width}: campaign attribution fits screen`);
    check(!ownerErrors.length && requests.every(method => method === 'GET'), `${width}: read-only owner checks with no runtime errors`);
    await dashboard.screenshot({ path: path.join(out, `results-campaign-${width}.png`) });
    receipt.cases.push({ width, public_statuses: 4, languages: 6, authenticated_source: true });
    await owner.close();
  }
  receipt.passed = true;
} catch (error) { receipt.error = error.stack || String(error); receipt.diagnostics = diagnostics; if (activePage && !activePage.isClosed()) { receipt.last_url = activePage.url(); receipt.visible_text = (await activePage.locator('body').innerText()).slice(0, 8000); await activePage.screenshot({ path: path.join(out, 'results-failure.png') }); } process.exitCode = 1; }
finally {
  await fs.mkdir(out, { recursive: true }); await fs.writeFile(path.join(out, 'browser-verification.json'), JSON.stringify(receipt, null, 2));
  await browser.close(); await new Promise(resolve => server.close(resolve));
}
console.log(JSON.stringify(receipt));
