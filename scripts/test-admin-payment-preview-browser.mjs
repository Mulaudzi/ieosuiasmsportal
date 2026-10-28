import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';

const base = process.argv[2] || 'http://127.0.0.1:4268';
assert.ok(/^http:\/\/127\.0\.0\.1:\d+$/.test(base), 'This test is localhost only.');
const out = '.sms-audit.local/admin-payment-preview-20261021/browser';
await mkdir(out, { recursive: true });
const csp = (await readFile('public/.htaccess', 'utf8')).match(/Header always set Content-Security-Policy "([^"]+)"/)[1].replace('; upgrade-insecure-requests', '');
function samplePdf() {
  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 500] /Contents 5 0 R /Resources << /Font << /F1 7 0 R >> >> >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 500] /Contents 6 0 R /Resources << /Font << /F1 7 0 R >> >> >>',
    ...[1, 2].map(page => { const stream = `BT /F1 18 Tf 30 450 Td (Synthetic proof page ${page}) Tj ET`; return `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`; }),
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
  ];
  let text = '%PDF-1.4\n'; const offsets = [0];
  objects.forEach((object, index) => { offsets.push(Buffer.byteLength(text)); text += `${index + 1} 0 obj\n${object}\nendobj\n`; });
  const xref = Buffer.byteLength(text);
  text += `xref\n0 8\n0000000000 65535 f \n${offsets.slice(1).map(offset => `${String(offset).padStart(10, '0')} 00000 n \n`).join('')}trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
  return Buffer.from(text);
}
const pdf = samplePdf();
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a8N8AAAAASUVORK5CYII=', 'base64');
let checks = 0;
const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
  for (const width of [1440, 375, 320]) {
    const context = await browser.newContext({ viewport: { width, height: 950 } });
    const user = { id: 'preview-fixture', name: 'Fixture admin', email: 'admin@example.invalid', role: 'admin', email_verified: true };
    await context.addInitScript(user => {
      localStorage.setItem('auth_user', JSON.stringify(user)); localStorage.setItem('auth_token', 'fixture-only');
      localStorage.setItem('auth_token_issued', String(Date.now())); localStorage.setItem('ieosuia_cookie_consent', '{}');
      window.addEventListener('securitypolicyviolation', event => { window.cspFailures = [...(window.cspFailures || []), event.violatedDirective]; });
    }, user);
    let proofMode = 'pdf', downloads = 0, proofRequests = 0;
    const errors = [];
    const order = { id: 1, reference: 'SMS-PREVIEW-FIXTURE', credits: 1000, total_cents: 30690, status: 'awaiting_verification', customer_name: 'Fixture customer', customer_email: 'customer@example.invalid', proofs: [{ id: 1, mime_type: 'application/pdf' }] };
    await context.route('**/*', async route => {
      const request = route.request(), url = new URL(request.url()), path = url.pathname;
      if (path.startsWith('/api/')) {
        assert.equal(request.method(), 'GET', 'Read-only preview regression must never submit a payment or credit.');
        let data = { success: true };
        if (path.endsWith('/payment-proofs/1')) {
          assert.equal(request.headers().authorization, 'Bearer fixture-only'); proofRequests++;
          if (proofMode === 'error') return route.fulfill({ status: 404, json: { success: false, message: 'Document not found.' } });
          return route.fulfill({ contentType: proofMode === 'pdf' ? 'application/pdf' : 'image/png', body: proofMode === 'pdf' ? pdf : png, headers: { 'Cache-Control': 'private, no-store', 'Content-Disposition': 'attachment; filename="fixture.pdf"' } });
        }
        if (path.endsWith('/auth/user')) data = { ...data, user };
        else if (path.endsWith('/credit-approval-security')) data = { ...data, available: true, configured: true, is_owner: false, owner_email: 'lufuno@ieosuia.com' };
        else if (path.endsWith('/wallet/purchase-orders')) data = { ...data, orders: [order], total: 1 };
        else if (path.endsWith('/operations/payments')) data = { ...data, payments: [], pagination: { page: 1, last_page: 1, total: 0 } };
        else if (path.endsWith('/overview')) data = { ...data, users: {}, campaigns: {}, messages: {}, wallets: {}, payments: {}, recent_customers: [] };
        else if (path.endsWith('/services')) data = { ...data, services: [] };
        else if (path.endsWith('/provider')) data = { ...data, available: true, credits: 10000 };
        else if (path.endsWith('/finance')) data = { ...data, payments: {}, ledger: {}, wallets: {} };
        else if (path.endsWith('/customers')) data = { ...data, customers: [] };
        else if (path.endsWith('/cron/status')) data = { ...data, jobs: [null, { job_name: 'sms-scheduler', last_seen_at: '2026-10-21 08:00:00' }, null] };
        else if (path.endsWith('/notifications')) data = { ...data, data: [], unread_count: 0 };
        else data = { ...data, campaigns: [], total_users: 1, active_users: 1, pending_sender_ids: 0, total_campaigns: 0, total_messages: 0, total_revenue: 0 };
        return route.fulfill({ json: data });
      }
      assert.equal(url.origin, base, 'No unmocked external network requests allowed.');
      if (request.resourceType() === 'document') {
        const response = await route.fetch();
        return route.fulfill({ response, headers: { ...response.headers(), 'Content-Security-Policy': csp } });
      }
      return route.continue();
    });
    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message)); page.on('download', () => downloads++);
    await page.goto(base + '/guymhan/tools?tab=scheduled');
    await page.getByText('Scheduled Campaign Processor', { exact: true }).waitFor();
    await page.getByText(/Last run:/).waitFor(); checks++;
    if (width < 1024) await page.getByRole('button', { name: 'Open operations menu' }).click();
    const menu = width < 1024 ? page.getByRole('dialog').locator('aside') : page.locator('aside').first();
    await menu.getByRole('link', { name: 'Credit payments Review payments & add credits' }).click();
    await page.getByRole('region', { name: 'Bank transfer verification' }).waitFor();
    assert.equal(new URL(page.url()).searchParams.get('tab'), 'finance'); checks++;
    assert.equal(await page.getByRole('tab', { name: 'Finance', selected: true }).count(), 1); checks++;
    await page.reload(); await page.getByRole('button', { name: 'View proof 1' }).waitFor(); checks++;
    await page.getByRole('button', { name: 'View proof 1' }).click();
    await page.waitForFunction(() => { const canvas = document.querySelector('canvas[aria-label="Payment proof PDF, page 1"]'); return canvas?.width > 0 && canvas.getBoundingClientRect().width > 0; });
    const dialog = page.getByRole('dialog');
    await dialog.getByText('Page 1 of 2').waitFor(); checks++;
    await dialog.getByRole('button', { name: 'Next page' }).click();
    await dialog.getByText('Page 2 of 2').waitFor();
    await page.waitForFunction(() => document.querySelector('canvas[aria-label="Payment proof PDF, page 2"]')?.getBoundingClientRect().width > 0); checks++;
    assert.equal(downloads, 0); checks++;
    assert.equal(await dialog.evaluate(element => element.getBoundingClientRect().width <= innerWidth), true); checks++;
    await dialog.screenshot({ path: `${out}/pdf-${width}.png` });
    await dialog.getByRole('button', { name: 'Close preview' }).click();
    proofMode = 'image'; await page.getByRole('button', { name: 'View proof 1' }).click();
    await page.waitForFunction(() => { const image = document.querySelector('img[alt="Proof of payment for SMS-PREVIEW-FIXTURE"]'); return image?.complete && image.naturalWidth > 0; }); checks++;
    await page.getByRole('button', { name: 'Close preview' }).click();
    proofMode = 'error'; await page.getByRole('button', { name: 'View proof 1' }).click();
    await page.getByRole('alert').filter({ hasText: 'not found' }).waitFor(); checks++;
    proofMode = 'pdf'; await page.getByRole('button', { name: 'Retry preview' }).click();
    await page.getByText('Page 1 of 2').waitFor(); checks++;
    await page.getByRole('button', { name: 'Close preview' }).click();
    await page.getByRole('button', { name: 'Review payment', exact: true }).click();
    assert.equal(await page.getByRole('button', { name: 'Approve and add 1,000 credits' }).isDisabled(), true); checks++;
    assert.equal(proofRequests, 4); assert.equal(downloads, 0); checks += 2;
    assert.deepEqual(errors, []); assert.deepEqual(await page.evaluate(() => window.cspFailures || []), []); checks += 2;
    await context.close();
  }
  await writeFile(`${out}/results.json`, JSON.stringify({ passed: true, checks, widths: [1440, 375, 320], productionWrites: false, api: 'all intercepted synthetic fixtures', strictCsp: true }, null, 2));
  console.log(`PASS: ${checks} admin, PDF/image preview and scheduler browser checks under strict CSP; no downloads or production writes.`);
} finally { await browser.close(); }
