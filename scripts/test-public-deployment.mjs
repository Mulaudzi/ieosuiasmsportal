import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
const target = process.argv[2] || 'http://127.0.0.1:4181';
const browser = await chromium.launch({headless:true,...(process.platform === 'win32' ? {executablePath:process.env.PRERENDER_BROWSER || 'C:/Program Files/Google/Chrome/Application/chrome.exe'} : {})});
try {
  const response = await fetch(target + '/');
  assert.equal(response.status, 200);
  const html = await response.text();
  assert.equal((html.match(/<h1[ >]/g)||[]).length, 1);
  for(const text of ['Build audiences.','Send campaigns.','Track delivery.','Your messaging operation,','<header','<nav','<main','<section','<footer','rel="canonical"','https://sms.ieosuia.com/','social-preview-communications.png','Bulk SMS South Africa']) assert.ok(html.includes(text),text);
  assert.ok(!/lovable|sourceMappingURL|<script\s*>/i.test(html));
  console.log('PASS raw HTML, one H1, sections, metadata, no inline executable script or template branding');
  const noJs = await browser.newContext({javaScriptEnabled:false});
  const crawler = await noJs.newPage(); await crawler.goto(target);
  assert.match(await crawler.locator('h1').innerText(),/Build audiences/);
  assert.ok(await crawler.locator('main').innerText().then(x=>x.length>2000));
  await crawler.locator('summary').first().click();
  assert.ok(await crawler.locator('details').first().getAttribute('open') !== null);
  console.log('PASS JavaScript-disabled homepage and FAQ');await noJs.close();
  const page = await browser.newPage();const errors=[];
  page.on('pageerror',e=>errors.push(e.message));page.on('console',m=>{if(m.type()==='error')errors.push(m.text())});
  await page.addInitScript(()=>localStorage.setItem('ieosuia_cookie_consent','{}'));
  for(const width of [320,360,390,430,768,1024,1440,1920]){
    await page.setViewportSize({width,height:900});await page.goto(target);await page.locator('.public-site-header').waitFor();
    assert.equal(await page.locator('h1').count(),1);
    assert.ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth));
    if(width<1024){await page.getByRole('button',{name:'Open navigation',exact:true}).click();await page.getByRole('navigation',{name:'Mobile navigation'}).waitFor();await page.keyboard.press('Escape');}
  }
  assert.deepEqual(errors,[]);console.log('PASS interactive homepage, responsive layout, no console errors');
  const robots=await fetch(target+'/robots.txt');assert.equal(robots.status,200);const robotText=await robots.text();assert.match(robotText,/Sitemap: https:\/\/sms.ieosuia.com\/sitemap.xml/);
  const sitemap=await fetch(target+'/sitemap.xml');assert.equal(sitemap.status,200);const xml=await sitemap.text();assert.ok(xml.includes('<urlset'));assert.ok(!/\/dashboard|\/guymhan|\/wallet|\/api\/|\/auth\//.test(xml));
  const app=await fetch(target+'/app.html');const shell=await app.text();assert.equal(app.status,200);assert.ok(!shell.includes('Send bulk SMS.</h1>'));assert.ok(!shell.includes('rel="canonical"'));assert.ok(shell.includes('<div id="root"></div>'));
  for(const asset of ['/social-preview-communications.png','/ieosuia-icon.png']){const r=await fetch(target+asset);assert.equal(r.status,200);assert.match(r.headers.get('content-type'),/image\/png/);}
  console.log('PASS robots, public-only sitemap, separate SPA entry, brand images');
  if(process.env.TEST_DIST){
    const files=await readdir(path.join(process.env.TEST_DIST,'assets'));
    assert.ok(!files.some(x=>/\.map$|lovable/i.test(x)));
    for(const name of files.filter(x=>/\.(js|css)$/.test(x))){const text=await readFile(path.join(process.env.TEST_DIST,'assets',name),'utf8');assert.ok(!/lovable|sourceMappingURL/i.test(text),name);}
    console.log('PASS production JS/CSS provenance and source-map scan');
  }
} finally {await browser.close();}
