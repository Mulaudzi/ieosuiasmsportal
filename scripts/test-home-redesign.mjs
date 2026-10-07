import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
const target = process.argv[2] || 'http://127.0.0.1:4181';
const output = '.sms-audit.local/home-redesign';
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.platform === 'win32' ? { executablePath: process.env.PRERENDER_BROWSER || 'C:/Program Files/Google/Chrome/Application/chrome.exe' } : {}) });
try {
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  await page.addInitScript(() => localStorage.setItem('ieosuia_cookie_consent', '{}'));
  for (const width of [360, 390, 430, 768, 1024, 1440, 1920]) {
    await page.setViewportSize({ width, height: 1000 });
    await page.goto(target, { waitUntil: 'networkidle' });
    await page.locator('.campaign-window').waitFor();
    assert.equal(await page.locator('h1').count(), 1);
    assert.ok(!await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), `overflow ${width}`);
    assert.equal(await page.getByRole('figure', { name: /demo/i }).count(), 3);
    assert.equal(await page.getByText('Coming Soon', { exact: true }).count(), 1);
    assert.ok(!/[\u2013\u2014]/.test(await page.locator('main').innerText()));
    for (const link of await page.getByRole('link', { name: 'Start Messaging' }).all()) {
      assert.equal(await link.getAttribute('href'), '/register');
      if (await link.isVisible()) assert.ok((await link.boundingBox()).height >= 44);
    }
    for (const link of await page.getByRole('link', { name: 'Get Started', exact: true }).all()) assert.equal(await link.getAttribute('href'), '/register');
    for (const link of await page.getByRole('link', { name: 'Resources', exact: true }).all()) assert.equal(await link.getAttribute('href'), '/documentation');
    for (const logo of await page.getByRole('img', { name: 'IEOSUIA SMS Portal', exact: true }).all()) {
      assert.match(await logo.getAttribute('src'), /navy/);
      assert.ok(await logo.evaluate(img => img.complete && img.naturalWidth > 0));
      const box = await logo.evaluate(el => { const style = getComputedStyle(el); return { width: parseFloat(style.width), height: parseFloat(style.height) }; });
      assert.ok(Math.abs(box.width / box.height - 3) < .02, 'Logo aspect ratio');
    }
    await page.screenshot({ path: `${output}/${width}-hero.png` });
    await page.screenshot({ path: `${output}/${width}-full.png`, fullPage: true });
    if (width < 1024) {
      await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
      const navigation = page.getByRole('navigation', { name: 'Mobile navigation' });
      await navigation.getByRole('link', { name: 'Platform', exact: true }).click();
      await navigation.waitFor({ state: 'hidden' });
      assert.equal(new URL(page.url()).hash, '#platform');
      await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
      await page.keyboard.press('Escape');
      await navigation.waitFor({ state: 'hidden' });
    }
    for (const id of ['platform', 'features', 'pricing', 'use-cases', 'faq']) assert.equal(await page.locator(`#${id}`).count(), 1);
    await page.locator('summary').first().focus();
    await page.keyboard.press('Enter');
    assert.notEqual(await page.locator('details').first().getAttribute('open'), null);
    assert.notEqual(await page.locator('summary').first().evaluate(el => getComputedStyle(el).outlineStyle), 'none');
    console.log(`PASS ${width}px: layout, logo, CTAs, demo labels, navigation, keyboard FAQ`);
  }
  await page.emulateMedia({ reducedMotion: 'reduce' });
  assert.equal(await page.locator('.ops-button').first().evaluate(el => getComputedStyle(el).transitionDuration), '0s');
  assert.deepEqual(errors, []);
  console.log('PASS reduced motion and browser console');
} finally { await browser.close(); }
