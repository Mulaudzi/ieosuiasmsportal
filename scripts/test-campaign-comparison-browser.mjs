import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdirSync } from 'node:fs';

const base = process.argv[2] || 'http://127.0.0.1:4217';
const output = '.sms-audit.local/comparison-release';
mkdirSync(output, { recursive: true });
const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
const row = (id, name, overrides = {}) => ({ id, name, type: 'sms', created_at: '2026-01-01 10:00:00', sent_count: 2, delivered_count: 2, failed_count: 0, pending_count: 0, delivery_rate: 100, actual_cost: .7, avg_delivery_time_seconds: 49 * 3600, cost_per_delivery: .35, ...overrides });
const fixtures = [
  row('1', 'ALPHA UPDATE'),
  row('2', 'BETA UPDATE', { sent_count: 4, delivered_count: 4, actual_cost: 1.4, avg_delivery_time_seconds: 90 * 60 }),
  row('3', 'PENDING UPDATE', { sent_count: 3, delivered_count: 0, pending_count: 3, delivery_rate: 0, avg_delivery_time_seconds: null, cost_per_delivery: null }),
  row('4', 'NOT DELIVERED UPDATE', { delivered_count: 0, failed_count: 2, delivery_rate: 0, avg_delivery_time_seconds: null, cost_per_delivery: null }),
  row('5', 'DRAFT UPDATE', { sent_count: 0, delivered_count: 0, delivery_rate: null, actual_cost: 0, avg_delivery_time_seconds: null, cost_per_delivery: null }),
  row('6', 'SINGLE MESSAGE', { sent_count: 1, delivered_count: 1, avg_delivery_time_seconds: 60 }),
  row('7', 'COMMUNITY CAMPAIGN WITH A LONG NAME THAT MUST REMAIN READABLE IN FULL'),
];
const user = { id: 'comparison-fixture', name: 'Fixture', email: 'comparison@example.invalid', role: 'user', email_verified: true };
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await context.addInitScript(user => {
    localStorage.setItem('auth_user', JSON.stringify(user));
    localStorage.setItem('auth_token', 'fixture');
    localStorage.setItem('auth_token_issued', String(Date.now()));
    localStorage.setItem('ieosuia_cookie_consent', '{}');
  }, user);
  const requests = [];
  let comparisonFailure = false, incomplete = false, selectionFailure = false;
  await context.route('**/api/**', async route => {
    const url = new URL(route.request().url());
    let body = { success: true };
    if (url.pathname.endsWith('/auth/user')) body = { success: true, user };
    else if (url.pathname.endsWith('/notifications')) body = { success: true, notifications: [], unread_count: 0, has_more: false };
    else if (url.pathname.endsWith('/wallet')) body = { success: true, balance: 10 };
    else if (url.pathname.endsWith('/reports/compare')) {
      if (url.searchParams.has('selection')) {
        if (selectionFailure) return route.fulfill({ status: 503, json: { success: false } });
        body = { success: true, campaigns: fixtures };
      } else {
        const ids = url.searchParams.get('ids').split(',');
        requests.push(ids);
        if (comparisonFailure) return route.fulfill({ status: 404, json: { success: false } });
        // Deliberately reverse response order. The table must retain user selection order.
        const selected = ids.map(id => fixtures.find(c => c.id === id)).reverse();
        body = { success: true, campaigns: incomplete ? selected.slice(1) : selected };
      }
    }
    return route.fulfill({ json: body });
  });
  const page = await context.newPage();
  const runtimeErrors = [];
  page.on('pageerror', error => runtimeErrors.push(error.message));
  const go = async () => {
    await page.goto(base + '/reports/compare');
    await page.getByRole('checkbox', { name: 'Alpha update', exact: true }).waitFor();
  };
  const pick = async name => page.getByRole('checkbox', { name, exact: true }).click();
  const compare = async count => {
    await page.getByRole('button', { name: `Compare (${count})`, exact: true }).click();
    await page.getByRole('table', { name: 'Detailed comparison' }).waitFor();
  };
  const table = page.getByRole('table', { name: 'Detailed comparison' });
  const card = name => page.getByRole('heading', { name, exact: true }).locator('..');
  const metric = name => table.getByRole('row').filter({ has: page.getByRole('rowheader', { name, exact: true }) });
  await go();
  assert.equal(await page.getByRole('heading', { level: 1 }).innerText(), 'Campaign comparison');
  assert.equal(await page.getByRole('link', { name: 'Back to Reports', exact: true }).count(), 1);
  assert.equal(await page.getByRole('button', { name: 'Back to Reports', exact: true }).count(), 0);
  assert.equal(await page.getByRole('link', { name: 'Back to Reports', exact: true }).getAttribute('title'), null);
  assert.ok(await page.getByRole('button', { name: 'Compare (0)', exact: true }).isDisabled());
  await pick('Alpha update');
  assert.ok(await page.getByRole('button', { name: 'Compare (1)', exact: true }).isDisabled());
  await page.getByPlaceholder('Search campaigns', { exact: true }).fill('beta');
  assert.equal(await page.getByRole('checkbox').count(), 1);
  await pick('Beta update');
  await page.getByPlaceholder('Search campaigns', { exact: true }).fill('');
  assert.equal(await page.getByRole('checkbox', { name: 'Alpha update', exact: true }).getAttribute('aria-checked'), 'true');
  await pick('Pending update');
  await pick('Not delivered update');
  await pick('Draft update');
  assert.ok(await page.getByRole('checkbox', { name: 'Single message', exact: true }).isDisabled());
  await pick('Not delivered update');
  await pick('Draft update');
  await compare(3);
  assert.deepEqual(requests.at(-1), ['1','2','3']);
  assert.equal(await page.getByRole('checkbox').count(), 0);
  assert.match(await card('Best delivery rate').innerText(), /100%[\s\S]*Tied: Alpha update, Beta update[\s\S]*Alpha update: 2 messages; Beta update: 4 messages/);
  assert.match(await card('Shortest confirmation time').innerText(), /2 hours[\s\S]*Beta update/);
  assert.match(await card('Best value').innerText(), /R0.35[\s\S]*per delivered message: Tied: Alpha update, Beta update/);
  assert.deepEqual(await table.getByRole('columnheader').allTextContents(), ['Metric','Alpha update','Beta update','Pending update']);
  assert.deepEqual(await table.getByRole('rowheader').allTextContents(), ['Sent','Delivered','Not delivered','Pending','Delivery rate','Confirmation time','Cost per delivery']);
  assert.deepEqual(await metric('Pending').getByRole('cell').allTextContents(), ['0','0','3']);
  assert.deepEqual(await metric('Confirmation time').getByRole('cell').allTextContents(), ['2 days','2 hours','—']);
  assert.deepEqual(await metric('Cost per delivery').getByRole('cell').allTextContents(), ['R0.35','R0.35','—']);
  assert.ok(await metric('Delivery rate').getByRole('cell').first().evaluate(el => el.classList.contains('text-accent')));
  assert.ok(await metric('Pending').getByRole('cell').last().evaluate(el => el.classList.contains('text-muted-foreground')));
  assert.equal(await page.getByText('Performance Insights', { exact: true }).count(), 0);
  assert.equal(await page.getByText('Fastest Delivery', { exact: true }).count(), 0);
  assert.ok((await table.boundingBox()).y < (await page.getByRole('button', { name: 'Change campaigns' }).boundingBox()).y);
  await page.getByRole('button', { name: 'Change campaigns' }).click();
  for (const name of ['Alpha update','Beta update','Pending update']) assert.equal(await page.getByRole('checkbox', { name, exact: true }).getAttribute('aria-checked'), 'true');
  assert.equal(await page.getByRole('button', { name: /^Compare \(/ }).count(), 1);
  await page.getByRole('combobox', { name: 'Campaign type' }).click();
  await page.getByRole('option', { name: 'SMS', exact: true }).click();
  await page.getByRole('checkbox', { name: 'Alpha update', exact: true }).waitFor();
  assert.equal(await page.getByRole('checkbox').count(), fixtures.length);
  await page.getByRole('button', { name: 'Change campaigns' }).click();
  await page.getByRole('button', { name: 'Remove Pending update', exact: true }).click();
  await table.waitFor();
  assert.deepEqual(requests.at(-1), ['1','2']);
  await page.getByRole('button', { name: 'Remove Beta update', exact: true }).click();
  assert.equal(await table.count(), 0);
  assert.ok(await page.getByRole('button', { name: 'Compare (1)', exact: true }).isDisabled());
  assert.equal(await page.getByRole('checkbox', { name: 'Alpha update', exact: true }).getAttribute('aria-checked'), 'true');
  await pick('Alpha update'); await pick('Pending update'); await pick('Draft update');
  await compare(2);
  assert.match(await card('Shortest confirmation time').innerText(), /—[\s\S]*No qualifying campaign/);
  assert.match(await card('Best value').innerText(), /—[\s\S]*No qualifying campaign/);
  assert.deepEqual(await metric('Not delivered').getByRole('cell').allTextContents(), ['0','0']);
  assert.deepEqual(await metric('Delivery rate').getByRole('cell').allTextContents(), ['0%','—']);
  assert.ok(!(await table.innerText()).includes('R0.0000'));
  await page.getByRole('button', { name: 'Change campaigns' }).click();
  await pick('Draft update'); await pick('Single message'); await compare(2);
  assert.match(await card('Best delivery rate').innerText(), /Based on 1 message\./);
  await page.getByRole('button', { name: 'Change campaigns' }).click();
  await pick('Community campaign with a long name that must remain readable in full');
  await compare(3);
  for (const width of [320,375,768,1440]) {
    await page.setViewportSize({ width, height: 1000 });
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Page overflow at ${width}`);
    const header = table.getByRole('columnheader').last();
    assert.equal(await header.innerText(), 'Community campaign with a long name that must remain readable in full');
    assert.ok(await header.evaluate(el => el.scrollWidth <= el.clientWidth + 1 && getComputedStyle(el).textOverflow !== 'ellipsis'));
    await page.screenshot({ path: `${output}/comparison-${width}.png`, fullPage: true });
  }
  await page.getByRole('button', { name: 'Change campaigns' }).click();
  await pick('Alpha update'); await pick('Beta update');
  await compare(5);
  assert.equal(await table.getByRole('columnheader').count(), 6);
  await page.getByRole('switch', { name: 'Dark mode', exact: true }).click();
  assert.ok(await page.evaluate(() => document.documentElement.hasAttribute('data-portal-dark')));
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
  await page.screenshot({ path: `${output}/comparison-five-dark.png`, fullPage: true });
  await page.getByRole('button', { name: 'Remove Alpha update', exact: true }).click();
  await table.waitFor();
  await page.getByRole('button', { name: 'Remove Beta update', exact: true }).click();
  await table.waitFor();
  await page.getByRole('button', { name: 'Change campaigns' }).click();
  comparisonFailure = true;
  await page.getByRole('button', { name: 'Compare (3)', exact: true }).click();
  await page.getByRole('alert').filter({ hasText: 'Could not compare' }).waitFor();
  assert.equal(await table.count(), 0);
  comparisonFailure = false; incomplete = true;
  await page.getByRole('button', { name: 'Compare (3)', exact: true }).click();
  await page.getByRole('alert').filter({ hasText: 'Could not compare' }).waitFor();
  assert.equal(await table.count(), 0);
  incomplete = false;
  await compare(3);
  selectionFailure = true;
  await page.reload();
  await page.getByRole('alert').filter({ hasText: 'Could not load campaigns' }).waitFor();
  selectionFailure = false;
  await page.getByRole('button', { name: 'Retry', exact: true }).click();
  await page.getByRole('checkbox', { name: 'Alpha update', exact: true }).waitFor();
  assert.deepEqual(runtimeErrors, []);
  console.log('PASS: picker/search/type/limits, single return link, top results, ties, sample sizes, nulls, table order, chip refresh/reset, full names, 320/375/768/1440 layouts, API failures and retry. APIs mocked.');
} finally { await browser.close(); }
