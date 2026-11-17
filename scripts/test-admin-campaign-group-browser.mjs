import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
const base=process.argv[2]??'http://127.0.0.1:4243';
assert.ok(base.startsWith('http://127.0.0.1:'));
const out='.sms-audit.local/admin-campaign-group-20261023/browser';mkdirSync(out,{recursive:true});
let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;};
const browser=await chromium.launch({channel:'chrome',headless:true});
const auth=async(context,user)=>context.addInitScript(user=>{
 localStorage.setItem('auth_user',JSON.stringify(user));localStorage.setItem('auth_token','synthetic-only');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');localStorage.setItem(`sms-ui:${user.id}:dashboard-tour-seen`,'true');
},user);
try{
 for(const width of [1440,375,320]){
  const context=await browser.newContext({viewport:{width,height:1000}});
  const user={id:'setup-fixture',name:'Setup fixture',email:'fixture@example.invalid',role:'user',email_verified:true};await auth(context,user);
  let created=false,added=false;const writes=[],errors=[];
  const groups=()=>[{id:'uncategorized',name:'Uncategorized',contact_count:0,is_virtual:true},...(created?[{id:7,name:'Church members',contact_count:added?1:0},{id:9,name:'Uncategorized',contact_count:0}]:[])];
  await context.route('**/api/**',async route=>{
   const req=route.request(),path=new URL(req.url()).pathname;let result={success:true};
   if(req.method()!=='GET')writes.push({path,body:req.postDataJSON()});
   assert.ok(!(path.endsWith('/sms/campaigns-v2')&&req.method()==='POST'),'No final SMS submission in this test');
   if(path.endsWith('/auth/user'))result={...result,user};
   else if(path.endsWith('/notifications'))result={...result,notifications:[],unread_count:0};
   else if(path.endsWith('/portal/overview'))result={...result,contacts:0,bought_credits:true,sent_campaign:false,checked_delivery:false};
   else if(path.endsWith('/dashboard/stats'))result={...result,total_contacts:0,total_sent:0,total_delivered:0,total_failed:0,total_pending:0,total_awaiting_delivery:0,total_dlr_unavailable:0,total_skipped:0,total_recipients:0,total_failed_submissions:0,active_campaigns:0,delivery_rate:0};
   else if(path.endsWith('/dashboard/recent-campaigns'))result={...result,campaigns:[]};
   else if(path.endsWith('/dashboard/chart'))result={...result,chart:[]};
   else if(path.endsWith('/settings/sms'))result={...result,opt_out_text:'Reply STOP to unsubscribe'};
   else if(path.endsWith('/contact-groups')){if(req.method()==='POST'){created=true;result={...result,group:{id:7,name:'Church members',contact_count:0}};}else result={...result,groups:groups(),total_contacts:added?1:0};}
   else if(path.endsWith('/contacts')){if(req.method()==='POST'){assert.equal(req.postDataJSON().group_id,'7');added=true;result={...result,contact:{id:1,phone:'+27820000001'}};}else result={...result,data:added?[{id:1,name:'Ada',phone:'+27820000001',group_id:'7'}]:[],meta:{total:added?1:0}};}
   else if(path.endsWith('/sms/campaigns-v2/23'))result={...result,campaign:{id:23,name:'Sunday reminder',state:'completed',content:'Welcome on Sunday'}};
   else if(path.endsWith('/sms/campaigns-v2'))result={...result,campaigns:[{id:23,name:'Sunday reminder',state:'completed'}]};
   else if(path.endsWith('/sms/preview'))result={...result,preview:{sendable_count:1,required_credits:1,sufficient_balance:true}};
   else if(path.endsWith('/templates'))result={...result,templates:[]};
   else if(path.endsWith('/wallet'))result={...result,wallet:{balance:350,reserved:0,sms_credits:1000,price_per_credit:.35}};
   else result={...result,data:[]};
   await route.fulfill({json:result});
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/dashboard');await page.getByRole('dialog',{name:'Create your first contact group'}).waitFor();
  check(await page.getByLabel('Group name',{exact:true}).isVisible(),'Dashboard prompts a customer with no real groups after the tour is resolved');
  await page.getByRole('button',{name:'Not now',exact:true}).click();
  await page.goto(base+'/contacts');await page.getByRole('dialog',{name:'Create your first contact group'}).waitFor();
  check(writes.length===0,'Dashboard and Contacts prompts do not create a group without confirmation');
  await page.getByRole('button',{name:'Not now',exact:true}).click();
  await page.goto(base+'/sms-campaigns/new?group=7');await page.getByLabel('Name this SMS campaign').waitFor();
  check(!(await page.getByRole('button',{name:'Choose recipients',exact:true}).isEnabled()),'Group shortcut cannot bypass campaign naming');
  check(await page.getByRole('button',{name:'Choose an existing campaign',exact:true}).isVisible(),'Existing campaign is offered before group selection');
  await page.getByRole('button',{name:'Choose an existing campaign',exact:true}).click();await page.getByRole('button',{name:'Sunday reminder — completed'}).click();
  await page.getByLabel('Name this SMS campaign').waitFor();check(await page.getByLabel('Name this SMS campaign').inputValue()==='Sunday reminder','Selected campaign name copied');
  await page.getByRole('button',{name:'Choose recipients',exact:true}).click();await page.getByRole('dialog',{name:'Create your first contact group'}).waitFor();
  await page.screenshot({path:`${out}/first-group-${width}.png`,fullPage:true,animations:'disabled'});
  await page.getByLabel('Group name',{exact:true}).fill('Church members');await page.getByRole('button',{name:'Create group and continue'}).click();
  await page.getByRole('button',{name:'Add a contact manually',exact:true}).click();await page.getByLabel('Name',{exact:true}).fill('Ada');await page.getByLabel('Phone Number *',{exact:true}).fill('+27820000001');
  await page.getByRole('button',{name:'Add Contact',exact:true}).click();await page.getByRole('button',{name:'Done',exact:true}).click();
  const card=page.getByRole('button',{name:/Church members 1 contacts/});await card.waitFor();check(await card.getAttribute('aria-pressed')==='true','Newly populated group selected for SMS');
  const picker=page.getByRole('region',{name:'SMS recipient groups'});check(await picker.getByText('Uncategorized',{exact:true}).count()===0,'Empty real and virtual Uncategorized are absent when sending');
  await page.getByRole('button',{name:'Continue to message',exact:true}).click();check(await page.getByLabel('SMS message',{exact:true}).inputValue()==='Welcome on Sunday','Original campaign message copied into editable new send');
  await page.screenshot({path:`${out}/message-${width}.png`,fullPage:true,animations:'disabled'});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Customer page has no horizontal overflow');
  check(writes.filter(w=>w.path.endsWith('/contact-groups')).length===1,'Only one group created');check(writes.filter(w=>w.path.endsWith('/contacts')).length===1,'Only one contact added with explicit group');check(!writes.some(w=>w.path.endsWith('/sms/campaigns-v2')),'No real or fixture SMS submission');check(errors.length===0,'No customer runtime errors');await context.close();

  const admin=await browser.newContext({viewport:{width,height:1000}});const owner={...user,id:'admin-fixture',role:'admin'};await auth(admin,owner);
  await admin.route('**/api/**',async route=>{
   const req=route.request(),path=new URL(req.url()).pathname;assert.equal(req.method(),'GET','Admin summary test is read-only');let data={};
   if(path.endsWith('/auth/user'))data={user:owner};
   else if(path.endsWith('/notifications'))data={notifications:[],unread_count:0};
   else if(path.endsWith('/overview'))data={users:{total:1,active:1,verified:1},campaigns:{total:6},messages:{total:500},wallets:{},payments:{},recent_customers:[],customer_credits:{sms_credits_available:482,sms_credits_reserved:4,sms_credits_total:486},dispatch:{jobs:{active:2,queued:1,processing:1},messages:{waiting:44,processing:10,held:4},observed_at:'2026-10-23T22:30:00+02:00'}};
   else if(path.endsWith('/customers'))data={customers:[{id:7,name:'Customer fixture',email:'fixture@example.invalid',is_active:1,balance:170.1,reserved:1.4,sms_credits_available:482,sms_credits_reserved:4,sms_credits_total:486,contacts:50,campaigns:6,messages:500}]};
   else if(path.endsWith('/services'))data={services:[]};
   else if(path.endsWith('/provider'))data={available:true,credits:999};
   else if(path.endsWith('/campaigns'))data={campaigns:[]};
   await route.fulfill({json:{success:true,...data}});
  });
  const ap=await admin.newPage();const ae=[];ap.on('pageerror',e=>ae.push(e.message));await ap.goto(base+'/guymhan');
  const summary=ap.getByRole('region',{name:'Customer credits and live SMS jobs'});await summary.waitFor();
  check((await summary.innerText()).includes('482'),'Admin sees customer credits, not internal rand');check((await summary.innerText()).includes('44 waiting to send'),'Admin sees waiting count');check((await summary.innerText()).includes('4 held for review'),'Admin distinguishes held messages');check(await ap.getByText('Available SMS credits',{exact:true}).isVisible(),'Customer table labels credit unit explicitly');
  await ap.screenshot({path:`${out}/admin-${width}.png`,fullPage:true,animations:'disabled'});check(await ap.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Admin page has no horizontal overflow');check(ae.length===0,'No admin runtime errors');await admin.close();
 }
 writeFileSync(`${out}/result.json`,JSON.stringify({passed:true,checks,live_sms_sent:false,production_access:false},null,2));console.log(`PASS ${checks} desktop/mobile group setup, campaign reuse and admin credit/queue browser assertions.`);
}finally{await browser.close();}
