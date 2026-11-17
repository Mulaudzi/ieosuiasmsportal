import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

// Offline browser and asset checks only. Every non-local request is intercepted.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const projects = path.dirname(root);
const auth = path.join(projects, 'ieosuia/apps/auth');
const output = path.join(root, '.sms-audit.local/customer-copy-branding-20261105');
const mime = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
const receipt = { passed: false, checks: 0, cases: [], realEmailsSent: false, smsSent: false, productionAccessed: false, deployed: false };
const servers = [];
let browser;

function check(condition, message) { assert.ok(condition, message); receipt.checks++; }
async function hash(file) { return createHash('sha256').update(await fs.readFile(file)).digest('hex'); }
async function serve(directory, fixture = false) {
  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://localhost');
      if (fixture && url.pathname === '/') {
        const app = url.searchParams.get('app') || 'sms';
        const view = url.searchParams.get('view') || 'login';
        const html = execFileSync(process.env.PHP_BINARY || 'C:/php8/php.exe', [path.join(auth, 'tests/render-customer-brand-preview.php'), app, view], { encoding: 'utf8' });
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(html); return;
      }
      let target = path.resolve(directory, `.${decodeURIComponent(url.pathname)}`);
      if (!target.startsWith(directory + path.sep)) { res.writeHead(404); res.end(); return; }
      // The Auth preview only serves static assets, never real PHP or private files.
      if (fixture && !url.pathname.startsWith('/assets/')) { res.writeHead(404); res.end(); return; }
      if (!path.extname(target)) target = path.join(directory, 'index.html');
      const data = await fs.readFile(target);
      res.writeHead(200, { 'Content-Type': mime[path.extname(target)] || 'application/octet-stream' }); res.end(data);
    } catch { res.writeHead(404); res.end(); }
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  servers.push(server);
  return `http://127.0.0.1:${server.address().port}`;
}

try {
  const assets = [
    ['sms-light', 'ieosuiasmsportal/src/assets/ieosuia-sms-portal-light.png'],
    ['sms-dark', 'ieosuiasmsportal/src/assets/ieosuia-sms-portal-navy.png'],
    ['invoice-light', 'invoiceieosuia/src/assets/IEOSUIA Invoices Standard.png'],
    ['invoice-dark', 'invoiceieosuia/src/assets/IEOSUIA Invoices Light.png'],
    ['qr-light', 'ieosuiaqr/src/assets/IEOSUIA QR Standard.png'],
    ['qr-dark', 'ieosuiaqr/src/assets/IEOSUIA QR High Contrast.png'],
  ];
  for (const [name, canonical] of assets) check(await hash(path.join(auth, `assets/logos/${name}-20261105.png`)) === await hash(path.join(projects, canonical)), `${name} is byte-identical to its approved product asset`);

  for (const repo of ['ieosuiasmsportal', 'invoiceieosuia', 'ieosuiaqr']) {
    const source = await fs.readFile(path.join(projects, repo, 'src/pages/Contact.tsx'), 'utf8');
    check(!/Your message will be (sent|routed)|with a copy to|CC'd to|Will be sent to:/i.test(source), `${repo}: internal contact routing is not rendered`);
  }

  const authBase = await serve(auth, true);
  const appBases = Object.fromEntries(await Promise.all(['sms', 'invoice', 'qr'].map(async app => [app, await serve(path.join(output, `${app}-dist`))])));
  const localOrigins = new Set([authBase, ...Object.values(appBases)]);
  browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext();
  const submissions = [];
  await context.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (/\/api(?:\/|$)/.test(url.pathname)) {
      if (request.method() === 'POST' && /\/contact$/.test(url.pathname)) {
        submissions.push({ origin: new URL(request.headers().referer).origin, body: request.postDataJSON() });
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, message: 'Synthetic contact fixture accepted' }) }); return;
      }
      await route.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ success: false, authenticated: false, message: 'Offline anonymous fixture' }) }); return;
    }
    if (localOrigins.has(url.origin)) { await route.continue(); return; }
    await route.abort();
  });
  const page = await context.newPage();

  for (const width of [1440, 390, 320]) {
    await page.setViewportSize({ width, height: 900 });
    for (const app of ['sms', 'qr', 'invoice']) {
      for (const view of ['login', 'register', 'forgot', 'reset', 'verify', 'verified', 'email']) {
        await page.goto(`${authBase}/?app=${app}&view=${view}`);
        const image = view === 'email' ? page.locator('img').first() : page.locator('.customer-form .product-logo');
        await image.waitFor({ state: 'visible' });
        const surface = view === 'email' ? 'dark' : 'light';
        check((await image.getAttribute('src')).endsWith(`${app}-${surface}-20261105.png`), `${app}/${view}/${width}: correct logo variant`);
        await image.evaluate(img => img.decode());
        const geometry = await image.evaluate(img => ({ natural: [img.naturalWidth, img.naturalHeight], rendered: [img.getBoundingClientRect().width, img.getBoundingClientRect().height] }));
        check(geometry.natural[0] === 2172 && geometry.natural[1] === 724, `${app}/${view}/${width}: image loads`);
        check(Math.abs(geometry.rendered[0] / geometry.rendered[1] - 3) < 0.02, `${app}/${view}/${width}: no logo distortion`);
        if (view !== 'email') check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${app}/${view}/${width}: no overflow`);
        if (['login', 'register', 'reset', 'forgot'].includes(view)) check(await page.locator('input[name="_token"]').count() === 1, `${app}/${view}/${width}: existing CSRF field retained`);
        if (view === 'login' && app === 'sms') await page.screenshot({ path: path.join(output, `sms-login-${width}.png`), fullPage: true });
        receipt.cases.push({ app, view, width });
      }
    }
  }

  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 900 });
    for (const app of ['sms', 'invoice', 'qr']) {
      for (const purpose of ['general', 'support', 'sales']) {
        await page.goto(`${appBases[app]}/contact?purpose=${purpose}`);
        const form = page.locator('form').filter({ has: page.locator('textarea') }).first();
        await form.waitFor({ state: 'visible' });
        check(!/your message will be (sent|routed)|with a copy to|cc'd to|will be sent to:/i.test(await form.innerText()), `${app}/${purpose}/${width}: clean contact form`);
        await form.locator('#name').fill('Contact browser fixture');
        await form.locator('#email').fill('fixture@example.invalid');
        await form.locator('#message').fill('Synthetic offline contact submission only.');
        const previous = submissions.length;
        await form.getByRole('button', { name: 'Send Message', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('textarea')?.value === '');
        check(submissions.length === previous + 1, `${app}/${purpose}/${width}: one submission`);
        const submitted = submissions.at(-1);
        check(submitted.body.purpose === purpose, `${app}/${purpose}/${width}: inquiry category preserved`);
        check(submitted.body.email === 'fixture@example.invalid', `${app}/${purpose}/${width}: reply address preserved`);
        if (app === 'qr') check(submitted.body.targetEmail === (purpose === 'support' ? 'support@ieosuia.com' : 'hello@ieosuia.com'), `QR/${purpose}/${width}: existing routing payload preserved`);
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${app}/${purpose}/${width}: no overflow`);
        if (purpose === 'support') await page.screenshot({ path: path.join(output, `${app}-contact-${width}.png`), fullPage: true });
        receipt.cases.push({ app, view: 'contact', purpose, width });
      }
    }
  }
  receipt.mockedContactSubmissions = submissions.length;
  receipt.passed = true;
} catch (error) {
  receipt.error = error.stack || String(error);
  process.exitCode = 1;
} finally {
  await browser?.close();
  await Promise.all(servers.map(server => new Promise(resolve => server.close(resolve))));
  await fs.mkdir(output, { recursive: true });
  await fs.writeFile(path.join(output, 'browser-verification.json'), JSON.stringify(receipt, null, 2));
  console.log(JSON.stringify({ ...receipt, cases: receipt.cases.length }, null, 2));
}
