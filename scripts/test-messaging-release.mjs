import {chromium} from 'playwright';
import assert from 'node:assert/strict';
const base=process.argv[2]||'http://127.0.0.1:4196';
const browser=await chromium.launch({headless:true,...(process.platform==='win32'?{executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'}:{})});
const fixture={id:'release-test',name:'Release test',email:'fixture@example.invalid',role:'user',email_verified:true};
try{
 // The local preview's self-only CSP cannot call the production-origin API URL.
 // API calls remain mocked; keep the real CSP enabled when checking production.
 const context=await browser.newContext({bypassCSP:base.startsWith('http://127.0.0.1:')});
 await context.addInitScript(user=>{
  localStorage.setItem('ieosuia_cookie_consent','{}');
  localStorage.setItem('auth_token','ui-fixture-not-a-real-token');
  localStorage.setItem('auth_token_issued',Date.now().toString());
  localStorage.setItem('auth_user',JSON.stringify(user));
 },fixture);
 // All API traffic is fulfilled locally, even against production assets.
 // No SMS, login, credit or campaign mutations reach the production API.
 await context.route('**/api/**',route=>{
  const url=new URL(route.request().url());
  let body={success:true,data:[],notifications:[],unread_count:0};
  if(url.pathname.endsWith('/auth/user'))body={success:true,data:{user:fixture},user:fixture};
  if(url.pathname.endsWith('/settings/sms'))body={success:true,opt_out_text:'Reply STOP to unsubscribe'};
  if(url.pathname.includes('/wallet'))body={success:true,data:{wallet:{sms_credits:414,balance:150,available:145.24,price_per_credit:.35}}};
  if(url.pathname.endsWith('/preview'))body={success:true,preview:{sendable_count:1}};
  return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(body)});
 });
 const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 for(const width of [375,1440]){
  if(width===1440) await page.evaluate(()=>{for(const key of Object.keys(localStorage))if(key.startsWith('sms-campaign-draft:'))localStorage.removeItem(key);});
  await page.setViewportSize({width,height:1000});
  await page.goto(base+'/sms-campaigns/new',{waitUntil:'networkidle'});
  await page.locator('main input').first().fill('Release preview only');
  await page.getByRole('button',{name:'Next',exact:true}).last().click();
  await page.locator('main textarea').fill('27638082493');
  await page.getByRole('button',{name:'Next',exact:true}).last().click();
  const footer=page.getByRole('switch',{name:'Include opt-out text'});
  await footer.click();
  const input=page.getByRole('textbox',{name:'SMS message',exact:true});
  await input.fill("The Grace, Communion & Love of God series continues this Sunday at 9AM at CE South! Come ready for an unforgettable time in God’s presence. Don’t miss it!");
  const credits=page.getByText('Estimated credits per recipient',{exact:true}).locator('..').locator('p').last();
  assert.equal(await credits.innerText(),'3');
  await page.getByRole('button',{name:'Use straight quotes',exact:true}).click();
  assert.equal(await credits.innerText(),'1');
  await footer.click();
  assert.equal(await credits.innerText(),'2');
  const savedMessage=await input.inputValue();
  const savedKey=await page.evaluate(()=>JSON.parse(localStorage.getItem('sms-campaign-draft:v1:release-test')).submissionKey);
  await page.reload({waitUntil:'networkidle'});
  assert.equal(await input.inputValue(),savedMessage,'Message survives refresh');
  assert.equal(await footer.getAttribute('aria-checked'),'true','Footer survives refresh');
  assert.equal(await page.evaluate(()=>JSON.parse(localStorage.getItem('sms-campaign-draft:v1:release-test')).submissionKey),savedKey,'Idempotency key survives refresh');
  await page.evaluate(()=>{const key='sms-campaign-draft:v1:release-test';const draft=JSON.parse(localStorage.getItem(key));draft.step=5;draft.schedule='2027-01-01T12:00';draft.manual='27638082493';draft.groupIds=['8'];draft.contactIds=['4'];draft.excluded=['27111111111'];localStorage.setItem(key,JSON.stringify(draft));});
  await page.reload({waitUntil:'networkidle'});
  assert.equal(await input.inputValue(),savedMessage);
  assert.equal(await page.getByRole('button',{name:'Queue now',exact:true}).count(),0,'Final review must recalculate after restore');
  assert.equal(await page.getByRole('button',{name:'Next',exact:true}).count(),1);
  const restored=await page.evaluate(()=>JSON.parse(localStorage.getItem('sms-campaign-draft:v1:release-test')));
  assert.deepEqual([restored.schedule,restored.manual,restored.groupIds,restored.contactIds,restored.excluded],['2027-01-01T12:00','27638082493',['8'],['4'],['27111111111']]);
  assert.equal(await page.locator('.support-whatsapp').count(),0);
  assert.ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),'Composer overflow '+width);
 }
 assert.deepEqual(errors,[]);
 page.once('dialog',dialog=>dialog.accept());
 await page.getByRole('button',{name:'Discard draft and start again'}).click();
 assert.equal(await page.locator('main input').first().inputValue(),'');
 assert.equal(await page.evaluate(()=>localStorage.getItem('sms-campaign-draft:v1:release-test')),null);
 await page.evaluate(()=>localStorage.setItem('sms-campaign-draft:v1:another-user',JSON.stringify({content:'Other account private draft'})));
 await page.reload({waitUntil:'networkidle'});
 assert.equal(await page.locator('main input').first().inputValue(),'','Other account draft is not restored');
 await context.close();
 const publicPage=await browser.newPage();
 await publicPage.addInitScript(()=>localStorage.setItem('ieosuia_cookie_consent','{}'));
 await publicPage.goto(base,{waitUntil:'networkidle'});
 assert.equal(await publicPage.locator('.support-whatsapp[href="https://wa.me/27638082493"]').count(),1);
 assert.ok(await publicPage.locator('header a[href="/contact"]').count()>0);
 console.log('PASS release assets: composer 3/1/2-credit example at mobile and desktop, no runtime exceptions, public contact controls; all private API traffic mocked');
}finally{await browser.close();}
