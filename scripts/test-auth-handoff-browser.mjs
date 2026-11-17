import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';

const base = process.argv[2] || 'http://127.0.0.1:4235';
const out = process.argv[3] || '.sms-audit.local/auth-handoff-review-20261020/browser';
mkdirSync(out, { recursive: true });
let checks = 0;
const scenarios = [];
const check = (value, message) => { assert.ok(value, message); checks++; };
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const fixtureToken = 'synthetic-only-not-a-real-token';

async function scenario(name, { admin = false, failure = 0, invalidRole = false, hash, oldSession = false, signedOut = false, requested = null, width = 1440 } = {}, test) {
  if (process.env.AUTH_HANDOFF_SCENARIO && !name.includes(process.env.AUTH_HANDOFF_SCENARIO)) return;
  const context = await browser.newContext({ viewport: { width, height: 900 } });
  const navigation = [], errors = [];
  let profileRequests = 0, starts = 0;
  const control = { failure };
  await context.addInitScript(({ oldSession, signedOut, requested }) => {
    // Seed only the first document; a successful callback must survive its reload.
    if (!sessionStorage.getItem('fixture-seeded')) {
      sessionStorage.setItem('fixture-seeded', '1');
      if (oldSession) {
        localStorage.setItem('auth_token', 'previous-fixture');
        localStorage.setItem('auth_user', JSON.stringify({ id: 'previous', email: 'previous@example.invalid', role: 'user', email_verified: true }));
      }
      if (signedOut) localStorage.setItem('ieosuia_explicit_logout', String(Date.now()));
      if (requested) sessionStorage.setItem('redirectAfterLogin', requested);
    }
  }, { oldSession, signedOut, requested });
  await context.route('**/api/**', async route => {
    assert.equal(route.request().method(), 'GET', 'Handoff tests must not make API writes');
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/auth/ieosuia/start')) {
      starts++;
      control.startQuery = Object.fromEntries(new URL(route.request().url()).searchParams);
      return route.fulfill({ contentType: 'text/html', body: '<main>Fixture central sign-in start</main>' });
    }
    if (path.endsWith('/auth/user')) {
      profileRequests++;
      assert.equal(route.request().headers().authorization, `Bearer ${fixtureToken}`);
      await new Promise(resolve => setTimeout(resolve, 350));
      if (control.failure === 'network') return route.abort('failed');
      if (control.failure === 'timeout') { await new Promise(resolve => setTimeout(resolve, 12500)); return route.abort('failed').catch(() => {}); }
      if (control.failure) return route.fulfill({ status: control.failure, contentType: 'text/html', body: '<h1>Private hosting details</h1>' });
      return route.fulfill({ json: { success: true, user: { id: 'fixture-user', name: 'Fixture user', email: 'fixture@example.invalid', role: admin && !invalidRole ? 'admin' : 'user', email_verified: true } } });
    }
    const status = { status: 'healthy', message: 'Fixture only' };
    return route.fulfill({ json: { success: true, data: [], campaigns: [], users: [], notifications: [], unread_count: 0, transactions: [], groups: [], stats: {}, recent_campaigns: [], total: 0, balance: 0, credits: 0, total_users: 1, total_campaigns: 0, total_messages: 0, total_revenue: 0, health: { database: status, smtp: status, api: status, storage: status, overall: 'healthy' } } });
  });
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  page.on('framenavigated', frame => { if (frame === page.mainFrame()) navigation.push(new URL(frame.url()).pathname); });
  const callback = admin ? '/guymhan/auth/callback' : '/auth/callback';
  const fragment = hash === undefined ? `#${admin ? 'ieosuia_admin_token' : 'ieosuia_token'}=${fixtureToken}` : hash;
  try {
    await page.goto(base + callback + fragment, { waitUntil: 'domcontentloaded' });
    await test({ page, control, callback, profileRequests: () => profileRequests, starts: () => starts, navigation });
    check(errors.length === 0, `${name}: no uncaught browser errors: ${errors.join(', ')}`);
    scenarios.push({ name, profileRequests, centralLoginStarts: starts, navigation });
  } finally { await context.close(); }
}

async function complete({ page, profileRequests, starts, navigation }, target = '/dashboard') {
  await page.waitForURL(url => url.pathname === target);
  await page.waitForTimeout(500);
  check(profileRequests() === 1, 'Exactly one profile request owns the handoff');
  check(starts() === 0, 'No automatic central login restart');
  check(!navigation.some(path => path === '/login' || path === '/guymhan/login'), 'No intermediate login bounce');
  const stored = await page.evaluate(() => ({ token: localStorage.getItem('auth_token'), user: JSON.parse(localStorage.getItem('auth_user')), issued: localStorage.getItem('auth_token_issued') }));
  check(stored.token === fixtureToken && stored.user.id === 'fixture-user' && Number(stored.issued) > 0, 'Validated token, user and issue time survive navigation');
}

try {
  for (const admin of [false, true]) {
    await scenario(`${admin ? 'admin' : 'customer'}-delayed-success`, { admin, oldSession: true }, async fixture => {
      await fixture.page.waitForFunction(() => !location.hash);
      await complete(fixture, admin ? '/guymhan' : '/dashboard');
      await fixture.page.reload({ waitUntil: 'networkidle' });
      check(fixture.profileRequests() === 1, 'Reload uses the completed session, not another callback check');
      if (admin) check(await fixture.page.evaluate(() => !!sessionStorage.getItem('admin_session_timestamp')), 'Admin inactivity timestamp is set');
    });
  }
  await scenario('mobile-delayed-success', { width: 375 }, async fixture => complete(fixture));
  for (const failure of [503, 507, 'network', 'timeout']) {
    await scenario(`recover-${failure}`, { failure }, async fixture => {
      const retry = fixture.page.getByRole('button', { name: 'Retry sign-in check' });
      await retry.waitFor({ timeout: 20000 });
      check(new URL(fixture.page.url()).pathname === fixture.callback, 'Transient error stays on the callback');
      check(fixture.profileRequests() === 1 && fixture.starts() === 0, 'Transient error never starts another sign-in');
      check(await fixture.page.evaluate(() => !localStorage.getItem('auth_token') && !location.hash), 'Unvalidated token is not persisted or left in URL');
      check(!(await fixture.page.locator('body').innerText()).includes('Private hosting details'), 'Private HTML errors never appear');
      fixture.control.failure = 0;
      await retry.click();
      await fixture.page.waitForURL(url => url.pathname === '/dashboard');
      check(fixture.profileRequests() === 2 && fixture.starts() === 0, 'Explicit retry validates the original handoff without password entry');
    });
  }
  for (const failure of [401, 403]) {
    await scenario(`reject-${failure}`, { failure }, async fixture => {
      await fixture.page.getByRole('button', { name: 'Try secure sign-in again' }).waitFor();
      await fixture.page.waitForTimeout(500);
      check(fixture.profileRequests() === 1 && fixture.starts() === 0, 'Rejected credentials do not retry or redirect automatically');
      check(await fixture.page.evaluate(() => !localStorage.getItem('auth_token')), 'Rejected credentials never become a session');
    });
  }
  await scenario('admin-role-denied', { admin: true, invalidRole: true }, async fixture => {
    await fixture.page.getByRole('button', { name: 'Try secure sign-in again' }).waitFor();
    check(new URL(fixture.page.url()).pathname === fixture.callback, 'Customer role cannot enter admin from an admin fragment');
    check(await fixture.page.evaluate(() => !localStorage.getItem('auth_token')), 'Admin mismatch is not persisted');
  });
  for (const hash of ['', '#ieosuia_admin_token=wrong-mode', '#ieosuia_token=a&ieosuia_admin_token=b']) {
    await scenario('missing-or-mismatched-handoff', { hash }, async fixture => {
      await fixture.page.getByRole('button', { name: 'Try secure sign-in again' }).waitFor();
      check(fixture.profileRequests() === 0 && fixture.starts() === 0, 'Missing or ambiguous handoff fails closed without login loops');
      check(await fixture.page.evaluate(() => !location.hash), 'Sensitive fragment is removed even on invalid handoff');
    });
  }
  await scenario('sign-out-during-validation', {}, async fixture => {
    // Wait until validation has begun but before its delayed response.
    await fixture.page.waitForFunction(() => !location.hash);
    await fixture.page.evaluate(() => {
      const value = String(Date.now());
      localStorage.setItem('ieosuia_explicit_logout', value);
      dispatchEvent(new StorageEvent('storage', { key: 'ieosuia_explicit_logout', newValue: value }));
    });
    await fixture.page.waitForURL(url => url.pathname === '/' && url.searchParams.get('signed_out') === '1');
    await fixture.page.waitForTimeout(600);
    check(await fixture.page.evaluate(() => !localStorage.getItem('auth_token') && !localStorage.getItem('auth_user')), 'Late validation cannot resurrect a signed-out session');
    check(fixture.starts() === 0, 'Sign-out does not start another login');
  });
  await scenario('reject-stale-callback-after-logout', { signedOut: true }, async fixture => {
    await fixture.page.waitForURL(url => url.pathname === '/' && url.searchParams.get('signed_out') === '1');
    check(fixture.profileRequests() === 0 && fixture.starts() === 0, 'Stale callback cannot override explicit logout');
  });
  for (const mode of ['customer', 'admin', 'signup']) {
    const admin = mode === 'admin';
    await scenario(`fresh-${mode}-login-after-logout`, { signedOut: true }, async fixture => {
      await fixture.page.waitForURL(url => url.pathname === '/' && url.searchParams.get('signed_out') === '1');
      check(await fixture.page.evaluate(() => !!localStorage.getItem('ieosuia_explicit_logout')), 'Sign-out marker survives returning to the public home page');
      await fixture.page.goto(base + (admin ? '/guymhan/login' : mode === 'signup' ? '/register' : '/login'));
      await fixture.page.waitForURL(url => url.pathname === '/api/auth/ieosuia/start');
      check(fixture.control.startQuery?.prompt === 'login', `Explicit login after sign-out requests fresh central authentication: ${JSON.stringify({ admin, query: fixture.control.startQuery, starts: fixture.starts() })}`);
      check(!admin || fixture.control.startQuery?.account_type === 'admin', 'Admin login retains the admin authentication mode');
      check(mode !== 'signup' || fixture.control.startQuery?.screen_hint === 'signup', 'Signup retains its central authentication mode');
    });
  }
  await scenario('unsafe-return-path', { requested: '//example.invalid' }, async fixture => complete(fixture));
  await scenario('preserve-customer-return-path', { requested: '/sms-campaigns' }, async fixture => complete(fixture, '/sms-campaigns'));
  writeFileSync(`${out}/results.json`, JSON.stringify({ passed: true, checks, scenarios, allApiRequestsIntercepted: true, productionWrites: false }, null, 2));
  console.log(JSON.stringify({ passed: true, checks, scenarios: scenarios.length, productionWrites: false }));
} catch (error) {
  writeFileSync(`${out}/results.json`, JSON.stringify({ passed: false, checks, scenarios, error: error.message, allApiRequestsIntercepted: true, productionWrites: false }, null, 2));
  throw error;
} finally { await browser.close(); }
