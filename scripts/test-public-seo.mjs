import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
const base=process.argv[2]||'http://127.0.0.1:4185';
const pages=JSON.parse(await readFile(new URL('../src/seo/public-pages.json',import.meta.url),'utf8'));
const browser=await chromium.launch({headless:true,...(process.platform==='win32'?{executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'}:{})});
const results={base,pages:[],errors:[],links:[],accessibility:[]};
try {
 const noJs=await browser.newContext({javaScriptEnabled:false});const crawler=await noJs.newPage();
 const titles=new Set(),descriptions=new Set(),links=new Set();
 for(const item of pages){
  const response=await crawler.goto(base+item.path);assert.equal(response.status(),200,item.path);
  assert.equal(await crawler.locator('h1').count(),1,item.path);assert.equal(await crawler.locator('main').count(),1,item.path);
  assert.equal(await crawler.title(),item.title);
  const description=await crawler.locator('meta[name="description"]').getAttribute('content');assert.equal(description,item.description);assert.ok(!descriptions.has(description));descriptions.add(description);assert.ok(!titles.has(item.title));titles.add(item.title);
  assert.equal(await crawler.locator('link[rel="canonical"]').getAttribute('href'),'https://sms.ieosuia.com'+item.path);
  assert.equal(await crawler.locator('meta[property="og:url"]').getAttribute('content'),'https://sms.ieosuia.com'+item.path);
  assert.ok(!(await crawler.locator('meta[name="robots"]').getAttribute('content')).includes('noindex'));
  const schema=JSON.parse(await crawler.locator('script[data-public-schema]').textContent());assert.equal(schema['@context'],'https://schema.org');assert.ok(schema['@graph'].some(n=>n['@type']==='WebPage'));
  assert.ok(!/aggregateRating|reviewCount/.test(JSON.stringify(schema)));
  if(item.path==='/faq'){const faq=schema['@graph'].find(n=>n['@type']==='FAQPage');assert.equal(faq.mainEntity.length,await crawler.locator('main details').count());for(const q of faq.mainEntity)assert.ok((await crawler.locator('main').textContent()).includes(q.acceptedAnswer.text));}
  for(const img of await crawler.locator('img').all())assert.notEqual(await img.getAttribute('alt'),null);
  for(const href of await crawler.locator('a[href]').evaluateAll(nodes=>nodes.map(n=>n.getAttribute('href'))))if(href.startsWith('/')&&!href.startsWith('//'))links.add(href.split('#')[0]||'/');
  results.pages.push({path:item.path,title:item.title,words:(await crawler.locator('main').innerText()).split(/\s+/).length,schema:schema['@graph'].map(n=>n['@type'])});
  console.log('PASS initial HTML '+item.path);
 }
 await noJs.close();
 const context=await browser.newContext();await context.addInitScript(()=>localStorage.setItem('ieosuia_cookie_consent','{}'));
 const page=await context.newPage();page.on('pageerror',e=>results.errors.push(e.message));page.on('console',m=>{if(m.type()==='error')results.errors.push(m.text());});
 for(const width of [360,390,430,768,1440]){
  await page.setViewportSize({width,height:900});
  for(const item of pages){await page.goto(base+item.path,{waitUntil:'networkidle'});assert.equal(await page.locator('h1').count(),1);assert.ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),item.path+' overflow '+width);assert.equal(await page.title(),item.title);assert.equal(await page.locator('script[data-public-schema]').count(),1);}
  console.log('PASS all public pages '+width+'px');
 }
 await page.goto(base+'/pricing');await page.getByLabel('Total billable SMS segments').fill('6');assert.match(await page.locator('output').innerText(),/R2.10/);
 await page.getByRole('link',{name:'Understand delivery outcomes',exact:true}).click();await page.waitForURL('**/delivery-reports');assert.equal(await page.locator('link[rel="canonical"]').getAttribute('href'),'https://sms.ieosuia.com/delivery-reports');
 for(const href of links){if(/^\/(login|register|guymhan)/.test(href))continue;const response=await fetch(base+href,{redirect:'manual'});results.links.push({href,status:response.status});assert.ok([200,301,302].includes(response.status),'Broken link '+href);}
 const sitemap=await fetch(base+'/sitemap.xml').then(r=>r.text());const locs=[...sitemap.matchAll(/<loc>(.*?)<\/loc>/g)].map(m=>m[1]);assert.deepEqual(locs,pages.map(p=>'https://sms.ieosuia.com'+p.path));assert.ok(!sitemap.includes('<lastmod>'));
 for(const route of ['/dashboard','/sms-campaigns/11','/contacts','/reports','/guymhan']){const r=await fetch(base+route);const text=await r.text();assert.ok(text.includes('noindex'));assert.ok(text.includes('<div id="root"></div>'));assert.ok(!text.includes('campaign-window'));assert.match(r.headers.get('x-robots-tag')||'',/noindex/);}
 assert.equal((await fetch(base+'/not-a-real-public-page-987')).status,404);
 assert.deepEqual(results.errors,[]);
 console.log('PASS calculator, client navigation metadata, internal links, sitemap and index safeguards');
 if(process.env.AXE_PATH){const {default:AxeBuilder}=await import(process.env.AXE_PATH);for(const item of pages){await page.goto(base+item.path);const audit=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();results.accessibility.push({path:item.path,violations:audit.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))}))});}console.log('AXE audits saved');}
}finally{await mkdir('.sms-audit.local',{recursive:true});await writeFile('.sms-audit.local/seo-validation.json',JSON.stringify(results,null,2));await browser.close();}
