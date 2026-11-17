import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
const base=process.argv[2]||'http://127.0.0.1:4235';
const out=process.argv[3]||'.sms-audit.local/admin-fixes-review-20261020/browser';
mkdirSync(out,{recursive:true});let checks=0;
function check(value,message){assert.ok(value,message);checks++;}
const browser=await chromium.launch({channel:'chrome',headless:true});
try {
 for(const width of [1440,375]) {
  const context=await browser.newContext({viewport:{width,height:1000}}),requests=[],errors=[];
  const failures=new Map(),pending=new Map();let opsActive=0,opsPeak=0;
  context.on('requestfailed',request=>pending.get(request)?.());
  await context.addInitScript(()=>{
   localStorage.setItem('auth_user',JSON.stringify({id:'admin-fixture',name:'Fixture admin',email:'admin@example.invalid',role:'admin',email_verified:true}));
   localStorage.setItem('auth_token','synthetic-only-not-a-real-token');localStorage.setItem('auth_token_issued',String(Date.now()));
   localStorage.setItem('ieosuia_cookie_consent','{}');
  });
  await context.route('**/api/**',async route=>{
   assert.equal(route.request().method(),'GET','Browser regression must not send any API writes');
   const path=new URL(route.request().url()).pathname;requests.push(path);
   const ops=path.startsWith('/api/admin/')&&!path.endsWith('/realtime/poll');
   let active=ops;
   const release=()=>{if(active){opsActive--;active=false;}pending.delete(route.request());};
   pending.set(route.request(),release);
   if(ops){opsActive++;opsPeak=Math.max(opsPeak,opsActive);}
   try {
    await new Promise(resolve=>setTimeout(resolve,45));
    // The simulated server work ends before response delivery. Waiting for
    // fulfill() to resolve also counts client acknowledgement and can falsely
    // overlap with the next batch that the browser has already received.
    release();
    if(failures.has(path))return await route.fulfill({status:failures.get(path),contentType:'text/html',body:'<!DOCTYPE html><h1>Private hosting details must not appear</h1>'});
    const status={status:'healthy',message:'Fixture only'};
    const json={success:true,users:[{id:'fixture-user',name:'Fixture customer',email:'customer@example.invalid',role:'user',is_active:true,email_verified_at:'2026-10-20 10:00:00',created_at:'2026-10-20 10:00:00',last_login_at:null}],jobs:[],campaigns:[],logs:[],settings:[],recipients:[],emails:[],trends:[],notifications:[],unread_count:0,total_users:1,active_users:1,pending_sender_ids:0,total_campaigns:0,total_messages:0,total_revenue:0,health:{database:status,smtp:status,api:status,storage:status,overall:'healthy'},heatmap:null};
    await route.fulfill({json});
   } finally {release();}
  });
  const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
  const selected=()=>page.getByRole('tab',{selected:true}).innerText();
  async function navigateMenu(name){
   if(width<1024)await page.getByRole('button',{name:'Open operations menu'}).click();
   const menu=width<1024?page.getByRole('dialog').locator('aside'):page.locator('aside').first();
   await menu.getByRole('link',{name}).click();
   if(width<1024)await page.getByRole('dialog').waitFor({state:'hidden'});
   // SPA navigation does not reset the document's networkidle load state.
   await page.waitForTimeout(75);
   await page.waitForFunction(()=>Array.from(document.querySelectorAll('button')).some(button=>button.textContent.trim()==='Refresh'&&!button.disabled));
   await page.waitForLoadState('networkidle');
  }
  async function oneActive(){
   if(width<1024)await page.getByRole('button',{name:'Open operations menu'}).click();
   const menu=width<1024?page.getByRole('dialog').locator('aside'):page.locator('aside').first();
   check(await menu.locator('a[aria-current="page"]').count()===1,'Exactly one menu entry must be selected');
   if(width<1024){await page.keyboard.press('Escape');await page.getByRole('dialog').waitFor({state:'hidden'});}
  }
  await page.goto(base+'/guymhan/tools?tab=settings',{waitUntil:'networkidle'});
  check((await selected()).trim()==='Settings','Settings deep link selects Settings');
  await navigateMenu('User Management All platform users');
  check((await selected()).trim()==='Users','Sidebar updates the tab without reload');
  await page.getByText('Fixture customer',{exact:true}).waitFor();check(true,'User list is available');
  const sectionPosition=await page.locator('#admin-tools-sections').evaluate(element=>{
   const rect=element.getBoundingClientRect();return {top:rect.top,bottom:rect.bottom,height:window.innerHeight,scrollY:window.scrollY};
  });
  check(sectionPosition.top>=0&&(sectionPosition.top<250||sectionPosition.bottom<=sectionPosition.height+1),`Sidebar brings the selected section into view: ${JSON.stringify({width,...sectionPosition})}`);
  await oneActive();
  await page.goBack({waitUntil:'networkidle'});check((await selected()).trim()==='Settings','Back restores Settings');
  await page.goForward({waitUntil:'networkidle'});check((await selected()).trim()==='Users','Forward restores Users');
  requests.length=0;await page.reload({waitUntil:'networkidle'});
  check(requests.filter(path=>path.startsWith('/api/admin/')).every(path=>['/api/admin/users','/api/admin/stats'].includes(path)),'User Management must not request Operations data');
  failures.set('/api/admin/stats',503);await page.reload({waitUntil:'networkidle'});
  check(await page.getByText('Fixture customer',{exact:true}).count()===1,'Summary failure must not discard users');
  check(await page.getByRole('alert').filter({hasText:'temporarily unavailable'}).count()===1,'503 has a useful error');
  failures.clear();await page.getByRole('button',{name:'Retry this section'}).click();await page.waitForLoadState('networkidle');
  check(await page.getByRole('alert').count()===0,'Successful retry clears errors');
  for(const status of [500,503,507]) {
   failures.set('/api/admin/contact-emails/stats',status);
   await page.goto(base+'/guymhan/tools?tab=contact-emails',{waitUntil:'networkidle'});
   check(await page.getByRole('alert').filter({hasText:'Contact email statistics'}).count()===1,`${status} is contained within its section`);
   check(!(await page.locator('body').innerText()).includes('Private hosting details'),`${status} never exposes the HTML response`);
   await navigateMenu('User Management All platform users');
   check(await page.getByText('Fixture customer',{exact:true}).count()===1,`Users work after an Operations ${status}`);
   failures.clear();
  }
  requests.length=0;await navigateMenu('System Health Service status');
  check((await selected()).trim()==='System Health','System Health opens its own tab');await oneActive();
  check(requests.filter(path=>path.startsWith('/api/admin/')).every(path=>['/api/admin/stats','/api/admin/system-health','/api/admin/activity-heatmap'].includes(path)),'Health requests only its relevant data');
  failures.set('/api/admin/system-health',507);await page.reload({waitUntil:'networkidle'});
  check(await page.getByRole('alert').filter({hasText:'System health'}).count()===1,'Health failure is contained');
  check(await page.getByText('System health information is unavailable.',{exact:true}).count()===1,'Health failure is not shown as an endless loading spinner');
  failures.clear();
  await navigateMenu('Notifications Alert settings');await oneActive();
  check(new URL(page.url()).hash==='#notification-settings','Notifications has a unique destination');
  await navigateMenu('SMTP Settings Email configuration');await oneActive();
  check(new URL(page.url()).hash==='#smtp-settings','SMTP has a unique destination');
  await navigateMenu('Operations Tools Support, audit & settings');await oneActive();
  check((await selected()).trim()==='Contact Emails','Operations Tools opens Contact Emails');
  await page.goto(base+'/guymhan/tools?tab=health',{waitUntil:'networkidle'});
  opsPeak=0;
  await page.reload({waitUntil:'networkidle'});
  check(opsPeak<=2,`At most two admin section requests per page load, got ${opsPeak}`);
  await navigateMenu('User Management All platform users');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'User Management does not overflow the viewport');
  await page.screenshot({path:`${out}/users-${width}.png`,fullPage:true});
  check(errors.length===0,`No uncaught browser errors: ${errors.join(', ')}`);
  await context.close();
 }
 writeFileSync(`${out}/results.json`,JSON.stringify({passed:true,checks,widths:[1440,375],allApiRequestsIntercepted:true,productionWrites:false},null,2));
 console.log(`PASS: ${checks} admin browser checks. All API calls were intercepted; no production writes.`);
} finally {await browser.close();}
