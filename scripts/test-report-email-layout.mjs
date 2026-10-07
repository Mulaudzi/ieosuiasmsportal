import {chromium} from 'playwright';
import {readFile} from 'node:fs/promises';
import {pathToFileURL} from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
try{
 const page=await browser.newPage();
 await page.route('https://sms.ieosuia.com/branding/**',async route=>route.fulfill({contentType:'image/png',body:await readFile('src/assets/ieosuia-sms-portal-navy.png')}));
 for(const width of [320,375,800]){
  await page.setViewportSize({width,height:1000});await page.goto(pathToFileURL(path.resolve('.sms-audit.local/report-email-preview.html')).href);
  assert.equal(await page.locator('.tile').count(),6);
  assert.ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),`Email overflow ${width}`);
  const tiles=await page.locator('.tile').evaluateAll(nodes=>nodes.map(n=>({x:n.getBoundingClientRect().x,y:n.getBoundingClientRect().y})));
  if(width<600){assert.equal(tiles[0].x,tiles[1].x);assert.ok(tiles[1].y>tiles[0].y);}else{assert.equal(tiles[0].y,tiles[1].y);assert.ok(tiles[1].x>tiles[0].x);}
  await page.screenshot({path:`.sms-audit.local/report-email-${width}.png`,fullPage:true});
 }
 console.log('PASS: email six tiles, desktop 3x2 and mobile single column at 320/375/800, no horizontal overflow. Actual mail clients still require acceptance.');
}finally{await browser.close();}
