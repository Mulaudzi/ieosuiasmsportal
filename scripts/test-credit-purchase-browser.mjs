import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';

const base = process.argv[2] || 'http://127.0.0.1:4246';
const output = process.env.PURCHASE_CHECK_OUTPUT || '.sms-audit.local/credit-purchase-validation'; await mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
let checks = 0;
try {
  for (const width of [1440, 768, 375, 320]) {
    const context = await browser.newContext({ viewport: { width, height: 950 } });
    const user = { id: 'credit-fixture', name: 'Credit fixture', email: 'fixture@example.invalid', role: 'user', email_verified: true };
    await context.addInitScript(user => { localStorage.setItem('auth_user', JSON.stringify(user)); localStorage.setItem('auth_token', 'fixture-only'); localStorage.setItem('auth_token_issued', String(Date.now())); localStorage.setItem('ieosuia_cookie_consent', '{}'); }, user);
    let order = null; const requests = [], errors = [];
    await context.route('**/api/**', async route => {
      const path = new URL(route.request().url()).pathname; let data = { success: true };
      if (path.endsWith('/auth/user')) data = { ...data, user };
      else if (path.endsWith('/wallet/stats')) data = { ...data, sms_credits: 14, price_per_credit: .35, used_this_month: 0 };
      else if (path.endsWith('/wallet')) data = { ...data, wallet: { sms_credits: 14, price_per_credit: .35 } };
      else if (path.endsWith('/wallet/activity')) data = { ...data, transactions: [], total: 0, page: 1, last_page: 1, total_paid: 0 };
      else if (path.endsWith('/wallet/purchase-options')) data = { ...data, purchases_available: true, direct_transfer_available: true, proof_email: 'sms@ieosuia.com' };
      else if (path.endsWith('/wallet/purchase-orders')) data = { ...data, orders: order ? [order] : [], total: order ? 1 : 0 };
      else if (path.endsWith('/wallet/buy')) {
        const body = route.request().postDataJSON(); requests.push(body);
        order = { id: '1', reference: 'SMS-FIXTURE1234', credits: '1000', unit_cents: '31', subtotal_cents: '31000', discount_cents: '310', total_cents: '30690', status: 'awaiting_payment', payment_method: 'eft', bank_details: { bank_name: 'First National Bank (FNB)', account_name: 'IEOSUIA (PTY) LTD', account_number: '63184542520', branch_code: '255355' }, proof_email: 'sms@ieosuia.com', proofs: [] };
        data = { ...data, order, instructions_email_status: 'queued' };
      } else if (path.endsWith('/proof')) { order = { ...order, status: 'awaiting_verification', proofs: [{ id: 1, mime_type: 'application/pdf' }] }; data = { ...data, order }; }
      else if (path.endsWith('/notifications')) data = { ...data, data: [], unread_count: 0 };
      await route.fulfill({ json: data });
    });
    const page = await context.newPage(); page.on('pageerror', e => errors.push(e.message));
    await page.goto(base + '/wallet');
    await page.getByRole('button', { name: 'Buy Credits', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByText('R0.35', { exact: true }).waitFor();
    await dialog.getByText('View volume pricing', { exact: true }).click();
    await dialog.getByText('R0.22 each', { exact: true }).waitFor();
    assert.doesNotMatch(await dialog.innerText(),/\b\d{1,3}c\b/); checks += 2;
    await dialog.getByRole('spinbutton').fill('29'); assert.equal(await dialog.getByRole('button', { name: 'Choose payment method' }).isDisabled(), true); checks++;
    await dialog.getByRole('spinbutton').fill('1000');
    await dialog.getByRole('button', { name: 'Choose payment method' }).click();
    await dialog.getByRole('button', { name: /Pay directly to our FNB account and save R3.10/ }).click();
    await dialog.getByText('R306.90', { exact: true }).waitFor();
    assert.equal(await dialog.evaluate(el => el.scrollWidth <= el.clientWidth + 1), true); checks++;
    await page.screenshot({ path: `${output}/checkout-${width}.png`, fullPage: true });
    await dialog.getByRole('button', { name: 'Create bank transfer order' }).click();
    await dialog.getByText('Awaiting transfer', { exact: true }).waitFor();
    assert.equal(requests.length, 1); assert.equal(requests[0].credits, 1000); assert.equal(requests[0].quoted_total_cents, 30690); checks += 3;
    await dialog.locator('input[type=file]').setInputFiles({ name: 'receipt.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 fixture-only') });
    await dialog.getByRole('button', { name: 'Upload proof', exact: true }).click();
    await dialog.getByText('Awaiting verification', { exact: true }).waitFor();
    assert.equal(await dialog.evaluate(el => el.scrollWidth <= el.clientWidth + 1), true); checks++;
    await page.screenshot({ path: `${output}/transfer-${width}.png`, fullPage: true });
    await page.reload();
    await page.getByRole('heading', { name: 'Bank transfer orders' }).waitFor();
    await page.getByText('Awaiting verification', { exact: true }).waitFor(); checks++;
    assert.deepEqual(errors, []); checks++;
    await context.close();
  }
  console.log(`PASS: ${checks} production-build browser checks at 1440, 768, 375 and 320px using synthetic API responses. No live payments or emails sent.`);
} finally { await browser.close(); }
