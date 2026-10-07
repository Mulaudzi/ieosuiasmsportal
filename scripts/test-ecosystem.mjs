import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import path from 'node:path';

const sites = [
  {key:'sms', name:'IEOSUIA SMS Portal', root:process.env.SMS_TEST_DIST || 'dist', secondary:'features'},
  {key:'qr', name:'IEOSUIA QR', root:process.env.QR_TEST_DIST || '../ieosuiaqr/dist', secondary:'docs'},
  {key:'invoices', name:'IEOSUIA Invoices', root:process.env.INVOICES_TEST_DIST || '../invoiceieosuia/dist', secondary:'features'},
  {key:'pos', name:'IEOSUIA POS', root:process.env.POS_TEST_DIST || 'C:/IEOSUIA/ieosuia-pos/dist', secondary:'features'},
];
const products = {'IEOSUIA QR':'https://qr.ieosuia.com/', 'IEOSUIA Invoices':'https://invoices.ieosuia.com/', 'IEOSUIA SMS Portal':'https://sms.ieosuia.com/', 'IEOSUIA POS':'https://pos.ieosuia.com/'};
const categories = ['Websites','Mobile Apps','Business Software','AI Solutions','Automation','Graphic Design','UI/UX Design','Animation & Motion'];
const mime = {'.js':'text/javascript','.css':'text/css','.png':'image/png','.webp':'image/webp','.svg':'image/svg+xml','.html':'text/html','.woff2':'font/woff2','.ttf':'font/ttf','.json':'application/json'};
const browser = await chromium.launch({headless:true, ...(process.platform==='win32'?{executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'}:{})});
await mkdir('.sms-audit.local/ecosystem', {recursive:true});
try {
 for (const site of sites.filter(site => !process.env.ECOSYSTEM_TEST_SITE || site.key === process.env.ECOSYSTEM_TEST_SITE)) {
  const root = path.resolve(site.root);
  const server = createServer(async(req,res)=>{
   try {
    const url = new URL(req.url,'http://localhost');
    if(url.pathname.startsWith('/api/')){res.writeHead(401,{'Content-Type':'application/json'});res.end('{"success":false}');return;}
    let relative = url.pathname === '/' ? 'index.html' : decodeURIComponent(url.pathname.slice(1));
    if(relative===site.secondary) relative=(site.key==='sms'?'public-pages/':'')+relative+'.html';
    const file=path.resolve(root,relative);
    assert.ok(file.startsWith(root+path.sep));
    const data=await readFile(file);res.writeHead(200,{'Content-Type':mime[path.extname(file)]||'application/octet-stream'});res.end(data);
   }catch{res.writeHead(404);res.end('Not found');}
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const base=process.env.ECOSYSTEM_TEST_BASE || 'http://127.0.0.1:'+server.address().port;
  const context=await browser.newContext({reducedMotion:'reduce'});
  await context.addInitScript(()=>localStorage.setItem('ieosuia_cookie_consent','{}'));
  const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
  try {
   for(const width of [360,390,430,768,1440,1920]){
    await page.setViewportSize({width,height:1000});await page.goto(base,{waitUntil:'networkidle'});
    const section=page.locator('section').filter({has:page.getByRole('heading',{level:2,name:'Explore more from the IEOSUIA ecosystem'})});
    await section.waitFor();assert.equal(await section.count(),1);
    assert.equal(await section.getByText('Current product',{exact:false}).count(),1);
    const current=section.locator('article').filter({hasText:'Current product'});
    assert.ok((await current.innerText()).includes(site.name));
    assert.equal(await current.locator('a').count(),0);
    for(const [name,url] of Object.entries(products)){
     if(name===site.name)continue;
     const link=section.getByRole('link',{name:'Explore '+name,exact:true});assert.equal(await link.getAttribute('href'),url);
     await link.focus();assert.ok(await link.evaluate(el=>el===document.activeElement));
     assert.ok(await link.evaluate(el=>getComputedStyle(el).outlineStyle!=='none'||getComputedStyle(el).boxShadow!=='none'));
    }
    assert.equal(await section.locator('a').count(),4);
    assert.equal(await section.getByRole('heading',{name:'IEOSUIA POS',exact:true}).count(),1);
    assert.equal(await section.getAttribute('data-ecosystem'),site.key);
    assert.equal(await section.locator('[data-product]').count(),4);
    for(const [product,color] of Object.entries({qr:'rgb(34, 211, 238)',invoice:'rgb(52, 211, 153)',sms:'rgb(56, 189, 248)',pos:'rgb(251, 146, 60)'})){
     const action=section.locator(`a[data-product="${product}"]`).getByText('Explore product',{exact:true});
     if(await action.count())assert.equal(await action.evaluate(el=>getComputedStyle(el).backgroundColor),color,`${site.key} ${product} card colour`);
    }
    if(site.key==='pos'){
     assert.ok((await section.getByRole('heading',{level:2}).evaluate(el=>getComputedStyle(el).fontFamily)).includes('Oxanium'));
     assert.equal(await section.evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(16, 16, 16)');
    }
    const rail=section.getByRole('region',{name:'Digital service categories. Scroll to explore all eight.'});
    await rail.focus();assert.ok(await rail.evaluate(el=>el===document.activeElement));
    assert.equal(await rail.locator('a').count(),0);
    assert.equal(await section.getByRole('link',{name:'Explore digital services'}).getAttribute('href'),'https://ieosuia.com/');
    for(const name of categories)assert.equal(await section.getByText(name,{exact:true}).count(),1);
    assert.ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),`${site.key} overflow ${width}`);
    assert.ok(await page.locator('footer a[href*="privacy"]').count()>0);
    assert.ok(await page.locator('footer a[href*="terms"]').count()>0);
    await page.evaluate(()=>document.activeElement instanceof HTMLElement && document.activeElement.blur());
    if([390,1440].includes(width))await section.screenshot({path:`.sms-audit.local/ecosystem/${site.key}-${width}.png`});
    if(['qr','invoices'].includes(site.key)&&[390,1440].includes(width)){
     const light=await section.evaluate(el=>getComputedStyle(el).backgroundColor);
     await page.evaluate(()=>document.documentElement.classList.add('dark'));
     assert.notEqual(await section.evaluate(el=>getComputedStyle(el).backgroundColor),light,`${site.key} follows dark theme`);
     assert.ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth));
     await section.screenshot({path:`.sms-audit.local/ecosystem/${site.key}-${width}-dark.png`});
    }
   }
   const noJs=await browser.newContext({javaScriptEnabled:false});const crawler=await noJs.newPage();await crawler.goto(base);
   const section=crawler.locator('section').filter({has:crawler.getByRole('heading',{name:'Explore more from the IEOSUIA ecosystem'})});
   assert.equal(await section.count(),1);
   assert.ok((await section.innerText()).toLowerCase().includes('current product'));
   for(const [name,url] of Object.entries(products))if(name!==site.name)assert.equal(await section.locator(`a[href="${url}"]`).count(),1);
   for(const name of categories)assert.ok((await section.innerText()).includes(name));
   if(site.key!=='pos'){await crawler.goto(base+'/'+site.secondary);assert.equal(await crawler.getByRole('heading',{name:'Explore more from the IEOSUIA ecosystem'}).count(),0);}
   await noJs.close();
   assert.deepEqual(errors,[]);
   console.log(`PASS ${site.key}: six widths, current product, cross-links, keyboard focus, footer links, no-JS HTML, no runtime errors${site.key==='pos'?'':', services rail, homepage-only placement'}`);
  }finally{await context.close();await new Promise(resolve=>server.close(resolve));}
 }
}finally{await browser.close();}
