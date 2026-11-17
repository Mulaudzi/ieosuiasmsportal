import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
const base = process.argv[2] || 'http://127.0.0.1:4237';
if (!base.startsWith('http://127.0.0.1:')) throw Error('Local intercepted fixtures only');
const out = process.argv[3] || '.sms-audit.local/public-banking-void-20261023/browser'; mkdirSync(out, { recursive: true });
let checks = 0; const check = (condition, label) => { assert.ok(condition, label); checks++; };
const bank = { bank_name: 'First National Bank (FNB)', account_name: 'Fixture Account - not real banking details', account_number: '123456789', account_type: 'Business', branch_code: '123456' };
const browser = await chromium.launch({ channel: 'chrome', headless: true }); let currentPage;
try {
 for (const width of [1440, 375]) {
  const context = await browser.newContext({ viewport: { width, height: 1000 } }); let enabled = true; const errors = [];
  await context.addCookies([{name:'hosting_verification_fixture',value:'fixture-only',url:base,sameSite:'Lax'}]);
  await context.addInitScript(() => { localStorage.setItem('ieosuia_cookie_consent', '{}'); Object.defineProperty(navigator, 'clipboard', { value: { writeText: async value => { window.__copiedBankValue = value; } }, configurable: true }); });
  await context.route('**/api/**', async route => {
   assert.equal(route.request().method(), 'GET'); assert.equal(new URL(route.request().url()).pathname, '/api/public/banking-details');
   check(!route.request().headers().authorization, 'Guest banking sends no authorization token');
   check(route.request().headers().cookie?.includes('hosting_verification_fixture=fixture-only'), 'Guest banking retains the same-site hosting verification cookie');
   await route.fulfill({ json: { success: true, available: enabled, bank_details: enabled ? bank : null, proof_email: 'sms@ieosuia.com' } });
  });
  const page = await context.newPage(); currentPage = page; page.on('pageerror', error => errors.push(error.message));
  await page.goto(base + '/pricing', { waitUntil: 'networkidle' });
  await page.getByRole('link', { name: 'View banking details', exact: true }).click();
  await page.getByRole('heading', { name: 'Banking Details & EFT Payments', exact: true }).waitFor();
  await page.getByText(bank.account_number, { exact: true }).waitFor();
  check(new URL(page.url()).pathname === '/banking-details', 'Banking page opens without sign-in');
  check((await page.locator('main').innerText()).includes('SMS-'), 'Unique order reference explained');
  check((await page.locator('main').innerText()).includes('registered SMS Portal email address'), 'Email fallback explained');
  await page.getByRole('button', { name: 'Copy Account number', exact: true }).click();
  check(await page.evaluate(() => window.__copiedBankValue) === bank.account_number, 'Copy uses the displayed account number');
  check(await page.getByRole('link', { name: 'Create a credit purchase', exact: true }).first().getAttribute('href') === '/wallet#buy-credits', 'Purchase reference CTA preserved');
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Guest page has no horizontal overflow');
  await page.screenshot({ path: `${out}/banking-${width}.png`, fullPage: true });
  for (const path of ['/', '/support', '/contact']) {
   await page.goto(base + path, { waitUntil: 'networkidle' });
   check(await page.locator('a[href="/banking-details"]').count() > 0, `${path} links to public banking`);
  }
  enabled = false; await page.goto(base + '/banking-details', { waitUntil: 'networkidle' });
  await page.getByText(/Direct EFT instructions are currently unavailable/).waitFor();
  check(await page.getByText(bank.account_number, { exact: true }).count() === 0, 'Disabled transfers hide account details');
  check(errors.length === 0, `No guest runtime errors: ${errors}`); await context.close();
 }
 for (const width of [1440, 375]) {
  const context = await browser.newContext({ viewport: { width, height: 1000 } }); let voided = false; const writes = [], errors = [];
  const user = { id: 'void-fixture', name: 'Fixture admin', email: 'admin@example.invalid', role: 'admin', email_verified: true };
  await context.addInitScript(user => { localStorage.setItem('auth_user', JSON.stringify(user)); localStorage.setItem('auth_token', 'synthetic-only'); localStorage.setItem('auth_token_issued', String(Date.now())); localStorage.setItem('ieosuia_cookie_consent', '{}'); }, user);
  const order = { id: 1, reference: 'SMS-VOID-FIXTURE', credits: 1000, total_cents: 30690, subtotal_cents: 31000, discount_cents: 310, payment_method: 'eft', status: 'awaiting_payment', customer_name: 'Fixture customer', customer_email: 'customer@example.invalid', proofs: [] };
  await context.route('**/api/**', async route => {
   const request = route.request(), url = new URL(request.url()), path = url.pathname; let data = { success: true };
   if (request.method() !== 'GET') {
    assert.equal(path, '/api/admin/operations/purchase-orders/1/review'); assert.equal(request.method(), 'POST'); const body = request.postDataJSON();
    assert.equal(body.decision, 'void'); assert.equal(body.funds_not_received, true); assert.ok(body.note); assert.equal(body.master_password, undefined);
    writes.push(body); voided = true;
   } else if (path.endsWith('/auth/user')) data = { ...data, user };
   else if (path.endsWith('/credit-approval-security')) data = { ...data, available: true, configured: false, is_owner: false, owner_email: 'owner@example.invalid' };
   else if (path.endsWith('/wallet/purchase-orders')) {
    const row = { ...order, ...(voided ? { status: 'voided', review_note: 'Checked bank; no money received', reviewed_at: '2026-10-23 12:00:00', reviewer_name: 'Fixture admin' } : {}) };
    const rows = voided && url.searchParams.get('status') === 'pending' ? [] : [row]; data = { ...data, orders: rows, total: rows.length };
   } else if (path.endsWith('/operations/payments')) data = { ...data, payments: [], pagination: { page: 1, last_page: 1, total: 0 } };
   else if (path.endsWith('/overview')) data = { ...data, users: {}, campaigns: {}, messages: {}, wallets: {}, payments: {}, recent_customers: [] };
   else if (path.endsWith('/services')) data = { ...data, services: [] };
   else if (path.endsWith('/provider')) data = { ...data, available: false };
   else if (path.endsWith('/finance')) data = { ...data, payments: {}, ledger: {}, wallets: {}, recent_payments: [] };
   else if (path.endsWith('/customers')) data = { ...data, customers: [], pagination: { page: 1, total: 0, last_page: 1 } };
   else if (path.endsWith('/campaigns')) data = { ...data, campaigns: [], pagination: { page: 1, total: 0, last_page: 1 } };
   else if (path.endsWith('/notifications')) data = { ...data, notifications: [], unread_count: 0 };
   await route.fulfill({ json: data });
  });
  const page = await context.newPage(); currentPage = page; page.on('pageerror', error => errors.push(error.message));
  await page.goto(base + '/guymhan?tab=finance', { waitUntil: 'networkidle' });
  const review = page.getByRole('region', { name: 'Bank transfer verification' });
  await review.getByRole('button', { name: 'Void unpaid request', exact: true }).click();
  const dialog = page.getByRole('alertdialog'); await dialog.waitFor();
  check((await dialog.innerText()).includes('This is not a refund'), 'Voiding is clearly separate from refunding');
  const confirm = dialog.getByRole('button', { name: 'Confirm void unpaid request' }); check(await confirm.isDisabled(), 'Void requires confirmation and reason');
  await dialog.getByLabel('Reason for voiding').fill('Checked bank; no money received'); check(await confirm.isDisabled(), 'Reason alone cannot void');
  await dialog.getByRole('checkbox').check(); check(await confirm.isEnabled(), 'No-funds affirmation enables void');
  await dialog.screenshot({ path: `${out}/void-confirm-${width}.png` }); await confirm.click();
  await review.getByText('No bank transfers match these filters.', { exact: true }).waitFor();
  check(writes.length === 1, 'One explicit void request, no credit mutation calls');
  await review.getByLabel('Review status').selectOption('voided');
  await review.getByText('Voided - unpaid request', { exact: true }).waitFor();
  check(await review.getByRole('button', { name: 'Review payment', exact: true }).count() === 0, 'Voided request cannot be approved');
  check(await review.getByRole('button', { name: 'Void unpaid request', exact: true }).count() === 0, 'Voided history has no repeat mutation controls');
  check(await review.getByText(/Checked bank; no money received/).count() > 0, 'Void reason retained in history');
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Admin page has no viewport overflow');
  check(errors.length === 0, `No admin runtime errors: ${errors}`); await context.close();
 }
 writeFileSync(`${out}/results.json`, JSON.stringify({ passed: true, checks, widths: [1440, 375], allApiResponsesIntercepted: true, productionWrites: false, bankTransfersInitiated: 0, creditsChanged: 0 }, null, 2));
 console.log(`PASS: ${checks} public banking and void-request browser checks; synthetic API fixtures only.`);
} catch (error) { if (currentPage && !currentPage.isClosed()) { await currentPage.screenshot({ path: `${out}/failure.png`, fullPage: true }); writeFileSync(`${out}/failure.txt`, String(error) + '\n' + await currentPage.locator('body').innerText()); } throw error; }
finally { await browser.close(); }
