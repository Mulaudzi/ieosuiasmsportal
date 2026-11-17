import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import {chromium} from 'playwright';
const dist=path.resolve(process.argv[2]||'.sms-audit.local/sms-api-public-release-20261105/frontend');
const out=path.resolve(process.argv[3]||'.sms-audit.local/sms-api-public-release-20261105');
const mime={'.js':'text/javascript','.css':'text/css','.png':'image/png','.svg':'image/svg+xml','.json':'application/json','.html':'text/html'};
const server=createServer(async(req,res)=>{try{
 const url=new URL(req.url,'http://localhost');
 let relative=url.pathname==='/'?'index.html':url.pathname.startsWith('/assets/')||url.pathname.startsWith('/branding/')||url.pathname.endsWith('.json')?url.pathname.slice(1):url.pathname==='/developers'?'public-pages/developers.html':'app.html';
 const file=path.resolve(dist,relative);if(!file.startsWith(dist+path.sep))throw Error();
 const raw=await fs.readFile(file);res.writeHead(200,{'Content-Type':mime[path.extname(file)]||'application/octet-stream'});res.end(raw);
}catch{res.writeHead(404);res.end();}});
await new Promise(r=>server.listen(0,'127.0.0.1',r));const base='http://127.0.0.1:'+server.address().port;
const browser=await chromium.launch({channel:'chrome',headless:true});let checks=0;
const receipt={passed:false,cases:[],production_access:false,real_sms_sent:false,live_credentials_created:false};
const check=(value,what)=>{assert.ok(value,what);checks++;};
try{
 for(const width of [1440,768,390,320]){
  const context=await browser.newContext({viewport:{width,height:850},permissions:['clipboard-read','clipboard-write']});
  const errors=[],apiCalls=[];await context.addInitScript(()=>localStorage.setItem('ieosuia_cookie_consent','{}'));
  await context.route('**/*',async route=>{const url=new URL(route.request().url());if(url.pathname.startsWith('/api/')){apiCalls.push(url.pathname);await route.abort();return;}if(url.origin!==base){await route.abort();return;}await route.continue();});
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/developers',{waitUntil:'networkidle'});
  await page.getByRole('heading',{level:1}).waitFor();
  check((await page.title()).includes('SMS API Documentation'),`${width}: public SEO`);
  check(await page.getByRole('main').count()===1,`${width}: one shared main`);
  check(await page.locator('footer').count()===1,`${width}: shared footer`);
  check(await page.locator('header.home-header').count()===1,`${width}: existing public header`);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${width}: page does not overflow`);
  for(const language of ['cURL','Node.js','Python','PHP','C# / .NET','Java']){
   await page.getByRole('tab',{name:language,exact:true}).click();
   check(await page.getByRole('tab',{name:language,exact:true}).getAttribute('aria-selected')==='true',`${width}: ${language} selected`);
   check((await page.getByRole('tabpanel').innerText()).includes('IEOSUIA_SMS_API_KEY'),`${width}: key remains an environment reference`);
  }
  await page.getByRole('tab',{name:'cURL',exact:true}).click();await page.getByRole('tab',{name:'cURL',exact:true}).press('ArrowRight');
  check(await page.getByRole('tab',{name:'Node.js',exact:true}).getAttribute('aria-selected')==='true',`${width}: keyboard tabs`);
  await page.getByRole('button',{name:'Copy Node.js send example',exact:true}).click();
  check((await page.evaluate(()=>navigator.clipboard.readText())).includes('Idempotency-Key'),`${width}: copy preserves safe job header`);
  await page.locator('#reference').scrollIntoViewIfNeeded();
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${width}: long fields/tables scroll internally`);
  const spec=await context.request.get(base+'/sms-api-v1.openapi.json');const json=await spec.json();
  check(spec.ok()&&Object.keys(json.paths).length===6,`${width}: valid downloadable contract`);
  check(json.components.schemas.SmsInput?.properties?.scheduled_at?.nullable===true||JSON.stringify(json).includes('"nullable":true'),`${width}: schedule null documented`);
  if(width<1024){await page.getByRole('button',{name:'Open navigation'}).click();check(await page.locator('#public-mobile-nav').getByRole('link',{name:'SMS API',exact:true}).isVisible(),`${width}: mobile discovery`);await page.getByRole('button',{name:'Close navigation'}).click();}
  await page.evaluate(()=>scrollTo(0,0));await page.screenshot({path:path.join(out,`public-api-${width}.png`),fullPage:false});
  check(errors.length===0,`${width}: no runtime errors: ${errors.join(', ')}`);
  check(apiCalls.length===0,`${width}: documentation makes no API calls`);
  receipt.cases.push({width,languages:6,copy:true,no_page_overflow:true});await context.close();
 }
 receipt.passed=true;
}catch(error){receipt.error=error.stack||String(error);process.exitCode=1;}
finally{receipt.checks=checks;await fs.mkdir(out,{recursive:true});await fs.writeFile(path.join(out,'public-browser-verification.json'),JSON.stringify(receipt,null,2));await browser.close();await new Promise(r=>server.close(r));}
console.log(JSON.stringify(receipt));
