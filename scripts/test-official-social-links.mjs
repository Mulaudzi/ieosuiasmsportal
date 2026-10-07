import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';

const base = process.argv[2] || 'http://127.0.0.1:4187';
const links = JSON.parse(await readFile(new URL('../src/components/layout/official-social-links.json', import.meta.url), 'utf8'));
const approved = ['https://www.instagram.com/ieosuia_official/', 'https://www.facebook.com/ieosuia/', 'https://www.tiktok.com/@ieosuia'];
assert.deepEqual(links.map(link => link.url), approved);
const pages = JSON.parse(await readFile(new URL('../src/seo/public-pages.json', import.meta.url), 'utf8'));
const socialHost = /(^|\.)(instagram\.com|facebook\.com|tiktok\.com|twitter\.com|x\.com|youtube\.com|youtu\.be|linkedin\.com|threads\.net|threads\.com|snapchat\.com|pinterest\.com|wa\.me|whatsapp\.com|discord\.com|discord\.gg|t\.me|telegram\.org|github\.com)$/i;
const browser = await chromium.launch({headless:true, ...(process.platform === 'win32' ? {executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'} : {})});
try {
  const page = await browser.newPage();
  await page.addInitScript(() => localStorage.setItem('ieosuia_cookie_consent', '{}'));
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  for (const width of [390, 1440]) {
    await page.setViewportSize({width, height:900});
    for (const item of pages) {
      await page.goto(base + item.path, {waitUntil:'networkidle'});
      const anchors = await page.locator('a[href]').evaluateAll(elements => elements.map(a => ({href:a.href, label:a.getAttribute('aria-label'), target:a.target, rel:a.rel, icon:!!a.querySelector('svg')})));
      // WhatsApp is an explicitly approved support channel, not an official social profile.
      const support = anchors.filter(a => a.href === 'https://wa.me/27638082493');
      assert.equal(support.length, item.path === '/' ? 1 : 0);
      for (const a of support) {
        assert.equal(a.target, '_blank');
        assert.ok(a.rel.includes('noopener') && a.rel.includes('noreferrer') && a.icon);
        assert.equal(a.label, 'Chat with IEOSUIA on WhatsApp: +27 63 808 2493');
      }
      const social = anchors.filter(a => socialHost.test(new URL(a.href).hostname) && !support.includes(a));
      assert.deepEqual([...new Set(social.map(a => a.href))].sort(), [...approved].sort(), item.path);
      for (const a of social) {
        const link = links.find(link => link.url === a.href);
        assert.ok(link, `Unsupported link ${a.href}`);
        assert.equal(a.label, `IEOSUIA on ${link.name}`);
        assert.equal(a.target, '_blank');
        assert.ok(a.rel.includes('noopener') && a.rel.includes('noreferrer') && a.icon);
      }
      assert.equal(await page.locator('meta[name^="twitter:"],.public-whatsapp').count(), 0);
      const schema = await page.locator('script[data-public-schema]').textContent();
      assert.deepEqual(JSON.parse(schema)['@graph'].find(node => node['@type'] === 'Organization').sameAs, approved);
      assert.ok(!await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), `${item.path} overflows at ${width}`);
      // The static crawler snapshot must agree with the hydrated application.
      const html = await (await page.request.get(base + item.path)).text();
      for (const url of approved) assert.ok(html.includes(url), `${item.path} snapshot missing ${url}`);
      assert.ok(!/https?:\/\/(?:www\.)?(?:youtube\.com|twitter\.com|linkedin\.com)/i.test(html));
    }
  }
  assert.deepEqual(errors, []);
  console.log(`PASS official social links, labels, icons, metadata, schema and responsive layout on ${pages.length} public pages at mobile and desktop widths`);
} finally { await browser.close(); }
