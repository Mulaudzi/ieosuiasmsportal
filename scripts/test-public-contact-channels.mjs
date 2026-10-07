import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import path from 'node:path';

const sites = [
  ['sms', '.sms-audit.local/contact-dist'],
  ['qr', '../ieosuiaqr/.artifacts/contact-dist'],
  ['invoices', '../invoiceieosuia/.artifacts/contact-dist'],
];
const mime = {'.js':'text/javascript','.css':'text/css','.png':'image/png','.svg':'image/svg+xml','.html':'text/html','.woff2':'font/woff2','.json':'application/json'};
const browser = await chromium.launch({headless:true, ...(process.platform === 'win32' ? {executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'} : {})});
await mkdir('.sms-audit.local/contact-checks', {recursive:true});
try {
  for (const [name, directory] of sites) {
    const root = path.resolve(directory);
    const server = createServer(async (req, res) => {
      try {
        const pathname = new URL(req.url, 'http://localhost').pathname;
        if (pathname.startsWith('/api/')) { res.writeHead(401, {'Content-Type':'application/json'}); res.end('{"success":false}'); return; }
        let file = path.resolve(root, '.' + pathname);
        assert.ok(file === root || file.startsWith(root + path.sep));
        if (!path.extname(file)) file = path.join(root, 'index.html');
        const data = await readFile(file);
        res.writeHead(200, {'Content-Type':mime[path.extname(file)] || 'application/octet-stream'}); res.end(data);
      } catch { res.writeHead(404); res.end('Not found'); }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const base = 'http://127.0.0.1:' + server.address().port;
    const context = await browser.newContext({reducedMotion:'reduce'});
    await context.addInitScript(() => {
      localStorage.setItem('ieosuia_cookie_consent', '{}');
      localStorage.setItem('cookie_consent', 'essential');
      localStorage.setItem('cookie-consent', 'rejected');
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    try {
      for (const width of [320,375,768,1024,1440]) {
        await page.setViewportSize({width,height:900});
        await page.goto(base, {waitUntil:'networkidle'});
        const button = page.locator('.support-whatsapp');
        await button.waitFor();
        assert.equal(await button.count(), 1, name + ' one WhatsApp button');
        assert.equal(await button.getAttribute('href'), 'https://wa.me/27638082493');
        assert.equal(await button.getAttribute('target'), '_blank');
        assert.match(await button.getAttribute('rel'), /noopener/);
        assert.match(await button.getAttribute('aria-label'), /WhatsApp/);
        assert.equal(await button.locator('svg path').count(), 1);
        const box = await button.boundingBox();
        assert.ok(Math.abs(width-box.x-box.width-20)<2 && Math.abs(900-box.y-box.height-20)<2, name+' bottom-right '+width);
        assert.ok(box.width>=48 && box.height>=48);
        await button.click({trial:true});
        const menu = page.getByRole('button', {name:/Open navigation/});
        if (await menu.isVisible()) await menu.click();
        const contact = page.locator('header a[href="/contact"], #public-mobile-nav a[href="/contact"]').filter({visible:true});
        assert.ok(await contact.count()>0, name+' Contact navigation '+width);
        await contact.first().click({trial:true});
        assert.equal(await page.getByRole('button', {name:/scroll to top|back to top/i}).count(), 0);
        assert.ok(!await page.evaluate(() => document.documentElement.scrollWidth>innerWidth), name+' overflow '+width);
        const closeMenu = page.getByRole('button', {name:/Close navigation/});
        if (await closeMenu.isVisible()) await closeMenu.click();
        if ([375,1440].includes(width)) await page.screenshot({path:'.sms-audit.local/contact-checks/'+name+'-'+width+'.png'});
      }
      await page.goto(base+'/contact', {waitUntil:'networkidle'});
      assert.equal(await page.locator('.support-whatsapp').count(), 0, name+' homepage only');
      const phones = await page.locator('a[href^="tel:"]').evaluateAll(nodes => nodes.map(a=>a.getAttribute('href')));
      assert.ok(phones.length>0, name+' call link exists');
      assert.ok(phones.every(href=>href==='tel:+27638082493'), name+' same call number');
      assert.doesNotMatch(await page.locator('body').innerText(), /079\s*928\s*2775|063\s*154\s*0696|79\s*928\s*2775/);
      assert.deepEqual(errors, [], name+' runtime errors');
      const noJs = await browser.newContext({javaScriptEnabled:false});
      const snapshot = await noJs.newPage();
      await snapshot.goto(base);
      assert.equal(await snapshot.locator('.support-whatsapp[href="https://wa.me/27638082493"]').count(), 1);
      assert.ok(await snapshot.locator('header a[href="/contact"]').count()>0);
      await noJs.close();
      console.log('PASS '+name+': responsive WhatsApp, Contact menu, call number, no back-to-top, static HTML and no runtime errors');
    } finally { await context.close(); await new Promise(resolve=>server.close(resolve)); }
  }
} finally { await browser.close(); }
