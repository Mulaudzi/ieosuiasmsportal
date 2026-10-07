import assert from 'node:assert/strict';
import {chromium} from 'playwright';
import {readFile,writeFile} from 'node:fs/promises';
const origin='https://sms.ieosuia.com';
const pages=JSON.parse(await readFile(new URL('../src/seo/public-pages.json',import.meta.url),'utf8'));
const result={redirects:[],privateApis:[],images:[],fragments:[],notes:[]};
for(const [url,destination] of [
 ['http://sms.ieosuia.com/',origin+'/'],
 ['http://sms.ieosuia.com/pricing/',origin+'/pricing'],
 [origin+'/pricing/',origin+'/pricing'],[origin+'/landing',origin+'/'],
 [origin+'/index.html',origin+'/'],[origin+'/public-pages/pricing.html',origin+'/pricing'],
]){const r=await fetch(url,{redirect:'manual'});assert.equal(r.status,301);assert.equal(r.headers.get('location'),destination);assert.equal((await fetch(destination,{redirect:'manual'})).status,200);result.redirects.push({url,destination,status:r.status});}
for(const route of ['/api/dashboard/stats','/api/reports/stats','/api/contacts','/api/wallet','/api/sms/campaigns-v2','/api/auth/user']){const r=await fetch(origin+route);assert.equal(r.status,401);result.privateApis.push({route,status:r.status});}
for(const [route,expected] of [['/login','/api/auth/ieosuia/start'],['/register','/api/auth/ieosuia/start?screen_hint=signup'],['/guymhan/login','/api/auth/ieosuia/start?account_type=admin']]){const r=await fetch(origin+route,{redirect:'manual'});assert.equal(r.status,302);assert.equal(new URL(r.headers.get('location'),origin).href,origin+expected);}
const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
try{
 const context=await browser.newContext({javaScriptEnabled:false});const page=await context.newPage();const images=new Set();
 for(const item of pages){await page.goto(origin+item.path);for(const src of await page.locator('img').evaluateAll(nodes=>nodes.map(n=>n.src)))images.add(src);
  for(const node of JSON.parse(await page.locator('script[data-public-schema]').textContent())['@graph'])if(typeof node.logo==='string')images.add(node.logo);
  for(const href of await page.locator('a[href]').evaluateAll(nodes=>nodes.map(n=>n.href))){const url=new URL(href);if(url.origin!==origin||!url.hash||!pages.some(p=>p.path===url.pathname))continue;result.fragments.push({page:item.path,target:url.pathname+url.hash});}
 }
 for(const src of images){const r=await fetch(src);assert.equal(r.status,200);assert.match(r.headers.get('content-type')||'',/^image\//);result.images.push(src);}
 for(const target of new Set(result.fragments.map(f=>f.target))){await page.goto(origin+target);const id=decodeURIComponent(new URL(origin+target).hash.slice(1));assert.ok(await page.evaluate(id=>!!document.getElementById(id),id),'Missing fragment '+target);}
 await page.goto(origin+'/pricing?utm_source=seo-check');assert.equal(await page.locator('link[rel="canonical"]').getAttribute('href'),origin+'/pricing');
 const social=await fetch(origin+'/social-preview-communications.png');assert.equal(social.status,200);const bytes=new Uint8Array(await social.arrayBuffer());const view=new DataView(bytes.buffer);assert.equal(view.getUint32(16),1200);assert.equal(view.getUint32(20),630);
 result.notes.push('Query canonical, social image 1200x630, auth redirects and all internal fragments passed');
}finally{await browser.close();await writeFile('.sms-audit.local/seo-http-live.json',JSON.stringify(result,null,2));}
console.log('PASS canonical one-hop redirects, protected APIs, auth entry routes, image requests, fragments and social image');
