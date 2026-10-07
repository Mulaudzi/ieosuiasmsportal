import { chromium } from 'playwright';
import assert from 'node:assert/strict';

const base = process.argv[2] || 'http://127.0.0.1:4186';
const browser = await chromium.launch({ headless: true, ...(process.platform === 'win32' ? { executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' } : {}) });
try {
  const page = await browser.newPage();
  await page.addInitScript(() => localStorage.setItem('ieosuia_cookie_consent', '{}'));
  for (const width of [1024, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(base, { waitUntil: 'networkidle' });
    const navigation = page.getByRole('navigation', { name: 'Main navigation', exact: true });
    const contact = navigation.getByRole('link', { name: 'Contact', exact: true });
    assert.equal(await contact.count(), 1);
    assert.ok(await contact.isVisible());
    assert.equal(await contact.getAttribute('href'), '/contact');
    assert.equal(await contact.getAttribute('class'), await navigation.getByRole('link', { name: 'Features', exact: true }).getAttribute('class'));
    assert.ok(!await page.evaluate(() => document.documentElement.scrollWidth > innerWidth));
    await contact.click();
    await page.waitForURL('**/contact');
  }
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
  const contact = page.getByRole('navigation', { name: 'Mobile navigation', exact: true }).getByRole('link', { name: 'Contact', exact: true });
  assert.equal(await contact.count(), 1);
  await contact.click();
  await page.waitForURL('**/contact');
  console.log('PASS Contact header link styling, desktop layouts and mobile navigation');
} finally {
  await browser.close();
}
