import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';

const base = process.argv[2] ?? 'http://127.0.0.1:4271';
assert.match(base, /^http:\/\/127\.0\.0\.1:\d+$/);
const out = process.argv[3] ?? '.sms-audit.local/payment-icons-concurrency-20261028/browser';
mkdirSync(out, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
let checks = 0;
const check = (value, label) => { assert.ok(value, label); checks++; };
try {
  for (const width of [1440, 768, 375, 320]) {
    const context = await browser.newContext({ viewport: { width, height: 1000 } });
    const user = { id: 'fixture', name: 'Payment fixture', email: 'fixture@example.invalid', role: 'user', email_verified: true };
    await context.addInitScript(user => {
      localStorage.setItem('auth_user', JSON.stringify(user));
      localStorage.setItem('auth_token', 'fixture');
      localStorage.setItem('auth_token_issued', String(Date.now()));
      localStorage.setItem('ieosuia_cookie_consent', '{}');
    }, user);
    let writes = 0, external = 0;
    await context.route('**/*', route => {
      if (new URL(route.request().url()).origin !== base) { external++; return route.abort(); }
      return route.continue();
    });
    await context.route('**/api/**', async route => {
      const path = new URL(route.request().url()).pathname;
      if (route.request().method() !== 'GET') writes++;
      let result = { success: true, data: [], orders: [], transactions: [], total: 0, pagination: { total: 0 }, total_paid: 0 };
      if (path.endsWith('/auth/user')) result = { success: true, user };
      else if (path.endsWith('/notifications')) result = { success: true, notifications: [], unread_count: 0, has_more: false };
      else if (path.endsWith('/wallet/purchase-options')) result = { success: true, purchases_available: true, direct_transfer_available: true, proof_email: 'sms@ieosuia.com', cash_balance: { enabled: false } };
      else if (path.endsWith('/wallet/activity')) result = { success: true, transactions: [], total: 0, page: 1, last_page: 1, total_paid: 0 };
      else if (path.endsWith('/wallet/purchase-orders')) result = { success: true, orders: [], total: 0 };
      else if (path.endsWith('/wallet/stats')) result = { success: true, balance: 350, sms_credits: 1000, used_this_month: 0, total_spent: 0, price_per_credit: .35 };
      else if (path.endsWith('/wallet')) result = { success: true, wallet: { balance: 350, reserved: 0, sms_credits: 1000, price_per_segment: .35 } };
      await route.fulfill({ json: result });
    });
    const errors = [];
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/wallet');
    try { await page.getByRole('button', { name: 'Buy Credits', exact: true }).click(); }
    catch (error) {
      await page.screenshot({ path: `${out}/failure-${width}.png`, fullPage: true });
      console.log(JSON.stringify({ width, url: page.url(), errors, text: (await page.locator('body').innerText()).slice(0, 1600) }));
      throw error;
    }
    await page.getByRole('button', { name: 'Choose payment method', exact: true }).click();
    const dialog = page.getByRole('dialog');
    const bank = dialog.getByRole('button', { name: /Pay directly to our FNB account/ });
    const online = dialog.getByRole('button', { name: /Pay online/ });
    check(await bank.getByText('EFT', { exact: true }).isVisible(), 'EFT badge is visible');
    check(await online.getByText('Mastercard', { exact: true }).isVisible(), 'Mastercard badge is visible');
    check(await online.getByText('Instant EFT', { exact: true }).isVisible(), 'Instant EFT badge is visible');
    check(await online.locator('svg circle').count() === 2, 'Local Mastercard mark is rendered');
    await bank.click();
    check(await bank.getAttribute('aria-pressed') === 'true', 'EFT selection is retained');
    await dialog.getByRole('button', { name: 'Create bank transfer order', exact: true }).waitFor();
    await online.click();
    check(await online.getAttribute('aria-pressed') === 'true', 'Online selection is retained');
    await dialog.getByRole('button', { name: 'Continue to secure checkout', exact: true }).waitFor();
    await page.screenshot({ path: `${out}/payment-${width}.png`, fullPage: true });
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Page has no horizontal overflow');
    check(await dialog.evaluate(element => element.scrollWidth <= element.clientWidth + 1), 'Payment dialog has no horizontal overflow');
    check(writes === 0, 'Selecting a method creates no purchase or payment');
    check(external === 0, 'Icons require no external network requests');
    check(errors.length === 0, 'No browser runtime errors: ' + errors.join('; '));
    for (const credits of [50001, 500000]) {
      await dialog.getByRole('button', { name: 'Back', exact: true }).click();
      await dialog.getByRole('spinbutton').fill(String(credits));
      await dialog.getByRole('button', { name: 'Choose payment method', exact: true }).click();
      check(await online.isVisible(), 'Large orders retain online checkout');
      check(await bank.isVisible(), 'Large orders retain discounted EFT');
      check(await dialog.getByText('Purchases above', { exact: false }).count() === 0, 'Direct deposit notice is absent below or at 500,000');
      check(await dialog.getByRole('button', { name: 'Continue to secure checkout', exact: true }).isEnabled(), 'Large online checkout is enabled');
      await bank.click();
      check(await dialog.getByText('Bank transfer saving', { exact: true }).isVisible(), 'EFT discount remains itemized');
      await online.click();
      check(await dialog.getByText('Bank transfer saving', { exact: true }).count() === 0, 'Online purchase has no EFT discount or extra fee');
    }
    await page.screenshot({ path: `${out}/large-payment-${width}.png`, fullPage: true });
    await dialog.getByRole('button', { name: 'Back', exact: true }).click();
    await dialog.getByRole('spinbutton').fill('500001');
    check(await dialog.getByRole('button', { name: 'Choose payment method', exact: true }).isDisabled(), 'Above 500,000 cannot start self-service payment');
    check(await dialog.getByText(/Purchases above 500,000 credits require direct bank deposit/).isVisible(), 'Above 500,000 explains direct deposit and sales');
    check(await dialog.getByRole('link', { name: 'sms@ieosuia.com', exact: true }).getAttribute('href') === 'mailto:sms@ieosuia.com', 'Sales contact is actionable');
    check(writes === 0 && errors.length === 0, 'Boundary selection makes no payments and has no runtime errors');
    await context.close();
  }
  const result = { passed: true, checks, fixture_only: true, widths: [1440, 768, 375, 320], payments_created: 0, real_sms_sent: false };
  writeFileSync(out + '/results.json', JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result));
} finally { await browser.close(); }
