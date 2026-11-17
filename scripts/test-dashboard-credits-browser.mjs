import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';

const base = process.argv[2] || 'http://127.0.0.1:4307';
assert.match(base, /^http:\/\/127\.0\.0\.1:\d+$/);
const channel = process.argv[3] || 'chrome';
assert.ok(['chrome', 'msedge'].includes(channel));
const out = process.argv[4] || `.sms-audit.local/dashboard-credits-20261030/browser-${channel}`;
mkdirSync(out, { recursive: true });
const browser = await chromium.launch({ channel, headless: true });
let checks = 0;
const check = (value, label) => { assert.ok(value, label); checks++; };
try {
  for (const [width, height] of [[1440, 900], [768, 1024], [375, 667], [320, 568]]) {
    const context = await browser.newContext({ viewport: { width, height } });
    const user = { id: 'dashboard-credit-fixture', name: 'Dashboard Fixture', email: 'fixture@example.invalid', role: 'user', email_verified: true };
    const errors = [], writes = [];
    let available = 487, walletFailure = false, statsFailure = false;
    await context.addInitScript(u => {
      localStorage.setItem('auth_user', JSON.stringify(u));
      localStorage.setItem('auth_token', 'local-fixture-not-a-credential');
      localStorage.setItem('auth_token_issued', String(Date.now()));
      localStorage.setItem(`sms-ui:${u.id}:dashboard-tour-seen`, 'true');
      localStorage.setItem('ieosuia_cookie_consent', '{}');
    }, user);
    // All non-loopback traffic is blocked; all API reads are synthetic fixtures.
    await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    await context.route('**/api/**', async route => {
      const request = route.request(), path = new URL(request.url()).pathname;
      if (request.method() !== 'GET') { writes.push(path); return route.abort(); }
      let data = { success: true };
      if (path.endsWith('/auth/user')) data = { success: true, user };
      else if (path.endsWith('/wallet')) {
        if (walletFailure) return route.fulfill({ status: 503, json: { success: false, message: 'Fixture offline' } });
        data = { success: true, wallet: { balance: 175, reserved: 4.55, available: 170.45, sms_credits: available, price_per_credit: .35, currency: 'ZAR' } };
      } else if (path.endsWith('/wallet/credit-transfers')) data = { success:true,enabled:true,transfers:[],total:0,page:1,last_page:1 };
      else if (path.endsWith('/portal/overview')) data = { success: true, contacts: 10, bought_credits: true, sent_campaign: true, checked_delivery: true };
      else if (path.endsWith('/contact-groups')) data = { success: true, groups: [{ id: '1', name: 'Fixture group', contact_count: 10 }] };
      else if (path.endsWith('/notifications')) data = { success: true, notifications: [], unread_count: 0, has_more: false };
      else if (path.endsWith('/dashboard/recent-campaigns')) data = { success: true, campaigns: [] };
      else if (path.endsWith('/dashboard/chart')) data = { success: true, chart: [] };
      else if (path.endsWith('/dashboard/stats')) {
        if (statsFailure) return route.fulfill({ status: 503, json: { success: false } });
        data = { success: true, total_sent: 10, total_contacts: 10, total_delivered: 8, total_failed: 1, total_pending: 0, total_awaiting_delivery: 1, total_dlr_unavailable: 0, total_skipped: 0, total_recipients: 10, total_failed_submissions: 0, active_campaigns: 0, delivery_rate: 80 };
      }
      await route.fulfill({ json: data });
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${base}/dashboard`);
    const card = page.getByRole('region', { name: 'Available SMS credits' });
    await card.getByLabel('487 available SMS credits', { exact: true }).waitFor();
    check(await card.isVisible(), `Credits visible at ${width}px`);
    check(await card.getByRole('link', { name: 'Buy credits' }).getAttribute('href') === '/wallet', 'Buy credits opens wallet');
    const bounds = await card.boundingBox();
    check(bounds.x >= 0 && bounds.x + bounds.width <= width + 1, `Credit card contained at ${width}px`);
    check(bounds.y + bounds.height < height - (width < 1024 ? 64 : 0), `Balance and actions above mobile navigation at ${width}px`);
    for (const button of [card.getByRole('button', { name: 'Refresh credits' }), card.getByRole('link', { name: 'Buy credits' })]) {
      const box = await button.boundingBox();
      check(box.x >= bounds.x && box.x + box.width <= bounds.x + bounds.width, 'Actions fit inside card');
    }
    await page.screenshot({ path: `${out}/${width}-available.png`, fullPage: true });
    const transfer=card.getByRole('button',{name:'Transfer credits'});
    await transfer.waitFor({state:'visible'});await transfer.click();
    await page.getByRole('dialog',{name:'Transfer your credits'}).waitFor();
    check(await page.getByRole('textbox',{name:'Recipient account email'}).isVisible(),'Dashboard transfer shortcut opens the existing verified flow');
    await page.getByRole('button',{name:'Cancel',exact:true}).click();

    available = 0;
    await card.getByRole('button', { name: 'Refresh credits' }).click();
    await card.getByLabel('0 available SMS credits', { exact: true }).waitFor();
    check(true, 'Genuine zero is displayed');
    walletFailure = true;
    await card.getByRole('button', { name: 'Refresh credits' }).click();
    await card.getByRole('alert').waitFor();
    check(await card.getByLabel('0 available SMS credits', { exact: true }).count() === 0, 'A failed refresh does not display stale zero');
    walletFailure = false; available = 12500;
    await card.getByRole('button', { name: 'Retry credits' }).click();
    await card.getByLabel('12,500 available SMS credits', { exact: true }).waitFor();
    check(true, 'Retry updates balance');
    await page.screenshot({ path: `${out}/${width}-refreshed.png`, fullPage: true });

    statsFailure = true;
    await page.reload();
    await card.getByLabel('12,500 available SMS credits', { exact: true }).waitFor();
    await page.getByText('Could not refresh dashboard data. Please try again.').waitFor();
    check(true, 'Credit balance remains independent of activity statistics errors');
    check(writes.length === 0, 'No API mutation requested');
    check(errors.length === 0, `No browser runtime errors: ${errors.join(', ')}`);
    await context.close();
  }
  writeFileSync(`${out}/result.json`, JSON.stringify({ passed: true, checks, channel, synthetic_api: true, external_traffic_blocked: true }, null, 2));
  console.log(JSON.stringify({ passed: true, checks, channel, out }));
} finally { await browser.close(); }
