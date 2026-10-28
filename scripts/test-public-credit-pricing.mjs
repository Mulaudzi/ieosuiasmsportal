import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {readFile,mkdir,writeFile} from 'node:fs/promises';
const base=process.argv[2]||'http://127.0.0.1:4248';
const dir=process.env.PRICING_CHECK_OUTPUT||'.sms-audit.local/public-credit-pricing-release-20261019';await mkdir(dir,{recursive:true});
const live=base.startsWith('https://');const rules=JSON.parse(await readFile('api/domain/credit-purchase-pricing.json','utf8'));
const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',args:live?['--disable-blink-features=AutomationControlled']:[]});
const options=live?{storageState:'.sms-audit.local/credit-purchases-release-20261019/release-browser-state.json',userAgent:'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'}:{};
let checks=0;const errors=[];
try{
 for(const width of [1440,768,375,320]){
  const context=await browser.newContext({...options,viewport:{width,height:950}});await context.addInitScript(()=>localStorage.setItem('ieosuia_cookie_consent','{}'));
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});page.on('response',r=>{if(r.status()>=400)errors.push(r.status()+' '+r.url());});
  for(const route of ['/','/pricing']){
   await page.goto(base+route,{waitUntil:'networkidle'});
   assert.doesNotMatch(await page.locator('body').innerText(),/\b\d{1,3}c\b/);assert.doesNotMatch(await page.title(),/\b\d{1,3}c\b/);checks+=2;
   const table=page.getByRole('table',{name:'SMS credit prices by purchase quantity'});assert.equal(await table.count(),1);checks++;
   assert.equal(await table.evaluate(t=>{const r=t.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.width<=t.parentElement.clientWidth+1;}),true,route+' both pricing columns fit '+width);checks++;
   const rows=table.locator('tbody tr');assert.equal(await rows.count(),rules.tiers.length);checks++;
   for(const [i,tier] of rules.tiers.entries()){
    const range=`${Math.max(rules.minimum,tier.min).toLocaleString('en-ZA')} to ${tier.max.toLocaleString('en-ZA')}`;
    assert.equal((await rows.nth(i).locator('th').textContent()).replace(/\s/g,' '),range.replace(/\s/g,' '));
    assert.equal(await rows.nth(i).locator('td').textContent(),'R'+(tier.cents/100).toFixed(2));checks+=2;
   }
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,route+' overflow '+width);checks++;
   if(route==='/'){
    const start=page.getByRole('link',{name:'Start with 30 credits for R10.50'});assert.equal(await start.getAttribute('href'),'#pricing');checks++;
    await page.getByText('Or R9.50 by direct FNB transfer. No VAT charged.').waitFor();checks++;
    assert.equal(await page.getByRole('link',{name:'Calculate your purchase'}).getAttribute('href'),'/pricing');checks++;
    await page.screenshot({path:`${dir}/${live?'live':'candidate'}-hero-${width}.png`});
    await start.click();await table.scrollIntoViewIfNeeded();await table.screenshot({path:`${dir}/${live?'live':'candidate'}-prices-${width}.png`});
   }else{
    const quantity=page.getByRole('spinbutton');await quantity.fill('30');await page.getByRole('status').filter({hasText:'30 credits: R10.50 at R0.35 each'}).waitFor();checks++;
    await quantity.fill('1000');await page.getByRole('status').filter({hasText:'1,000 credits: R310.00 at R0.31 each'}).waitFor();await page.getByText(/pay R306.90 and save R3.10/).waitFor();checks++;
    await quantity.fill('29');await page.getByRole('status').filter({hasText:'Choose a whole number between 30 and 500,000 credits.'}).waitFor();checks++;
    await quantity.fill('50001');await page.getByRole('status').filter({hasText:'Direct EFT only.'}).waitFor();checks++;
    await quantity.fill('250001');await page.getByRole('status').filter({hasText:'at R0.22 each'}).waitFor();checks++;
    await quantity.fill('500000');await page.getByRole('status').filter({hasText:'500,000 credits: R110,000.00'}).waitFor();checks++;
    await quantity.fill('500001');await page.getByRole('status').filter({hasText:'contact sales at hello@ieosuia.com'}).waitFor();
    assert.equal(await page.getByRole('link',{name:'Contact sales: hello@ieosuia.com'}).getAttribute('href'),'mailto:hello@ieosuia.com');checks+=2;
   }
  }
  await context.close();
 }
 const context=await browser.newContext({...options,javaScriptEnabled:false});const page=await context.newPage();
 for(const route of ['/','/pricing']){await page.goto(base+route);assert.equal(await page.getByRole('table',{name:'SMS credit prices by purchase quantity'}).locator('tbody tr').count(),rules.tiers.length);checks++;}
 await context.close();assert.deepEqual(errors,[]);
 await writeFile(`${dir}/${live?'live':'candidate'}-browser-checks.json`,JSON.stringify({passed:true,checks,widths:[1440,768,375,320],javascriptDisabledPages:2,errors,paymentRequestsMade:false},null,2));
 console.log(`PASS ${checks} public pricing checks including all tiers, four responsive widths, calculator, no-JS HTML and no console errors.`);
}finally{await browser.close();}
