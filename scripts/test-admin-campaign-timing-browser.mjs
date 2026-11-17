import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
const base=process.argv[2]??'http://127.0.0.1:4268';
assert.ok(/^http:\/\/127\.0\.0\.1:\d+$/.test(base),'Local preview only');
const out='.sms-audit.local/admin-campaign-timing-20261028';mkdirSync(out,{recursive:true});
const timing={status:'complete',source:'accepted_timestamps',queue_wait_seconds:46,sending_seconds:1309,total_seconds:1355,elapsed_seconds:null,accepted_per_minute:22.05,accepted_count:481,outstanding_count:0,held_count:0,skipped_count:4};
const campaign={id:23,name:'Measured campaign',state:'completed',recipient_count:485,delivered_count:400,actual_cost:168.35,user_name:'Customer fixture',user_email:'fixture@example.invalid',sending_timing:timing};
const customer={id:7,name:'Customer fixture',email:'fixture@example.invalid',is_active:1,sms_credits_available:1000,sms_credits_reserved:0,contacts:485,campaigns:2,messages:970};
const user={id:'admin-fixture',name:'Fixture admin',email:'admin@example.invalid',role:'admin',email_verified:true};
let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;};
const browser=await chromium.launch({channel:'chrome',headless:true});
try{
 for(const width of [1440,768,375]){
  const context=await browser.newContext({viewport:{width,height:1000}});
  await context.route('**/*',route=>new URL(route.request().url()).hostname==='127.0.0.1'?route.continue():route.abort());
  await context.addInitScript(user=>{localStorage.setItem('auth_user',JSON.stringify(user));localStorage.setItem('auth_token','synthetic-only');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');},user);
  await context.route('**/api/**',async route=>{
   assert.equal(route.request().method(),'GET','Browser check cannot send or mutate');
   const path=new URL(route.request().url()).pathname;let data={};
   if(path.endsWith('/auth/user'))data={user};
   else if(path.endsWith('/overview'))data={users:{total:1},campaigns:{total:2},messages:{total:970},wallets:{},payments:{},recent_customers:[],customer_credits:{},dispatch:{jobs:{},messages:{},observed_at:'2026-10-28T15:00:00+02:00'}};
   else if(path.endsWith('/customers/7'))data={customer,counts:{campaigns:2,messages:970,contacts:485,contact_groups:1},campaigns:[campaign],payments:[],sending_summary:{sample_size:2,recent_campaign_count:2,average_sending_seconds:1171.5,fastest_seconds:1034,slowest_seconds:1309}};
   else if(path.endsWith('/campaigns/23'))data={campaign,messages:[]};
   else if(path.endsWith('/customers'))data={customers:[customer]};
   else if(path.endsWith('/campaigns'))data={campaigns:[campaign]};
   else if(path.endsWith('/services'))data={services:[]};
   else if(path.endsWith('/provider'))data={available:true,credits:1000};
   else if(path.endsWith('/notifications'))data={notifications:[],unread_count:0};
   await route.fulfill({json:{success:true,...data}});
  });
  const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.goto(base+'/guymhan',{waitUntil:'domcontentloaded',timeout:60000});
  try{await page.getByText('Customer fixture',{exact:true}).click({timeout:20000});}
  catch(error){await page.screenshot({path:`${out}/failed-${width}.png`,fullPage:true});console.log(JSON.stringify({url:page.url(),body:(await page.locator('body').innerText()).slice(0,1600),errors}));throw error;}
  const summary=page.getByRole('region',{name:'Customer sending performance'});await summary.waitFor();
  check((await summary.innerText()).includes('19m 32s'),'Customer recent average visible');
  await page.screenshot({path:`${out}/customer-${width}.png`,fullPage:true});
  await page.getByRole('button',{name:'View campaign Measured campaign'}).click();
  const performance=page.getByRole('region',{name:'Campaign sending performance'});await performance.waitFor();
  const text=await performance.innerText();
  for(const expected of ['21m 49s','22m 35s','46s','22.05 SMS/min','not handset delivery'])check(text.includes(expected),'Timing detail '+expected);
  await page.waitForTimeout(250);
  const bounds=await performance.boundingBox();check(bounds.x>=0&&bounds.x+bounds.width<=width+1,'Timing cards fit viewport');
  await page.getByRole('button',{name:'Refresh timing'}).click();await performance.waitFor();
  await page.screenshot({path:`${out}/campaign-${width}.png`,fullPage:true});
  check(errors.length===0,'No browser runtime errors');await context.close();
 }
 writeFileSync(`${out}/browser-results.json`,JSON.stringify({passed:true,checks,widths:[1440,768,375],requests:'mocked GET only'},null,2));
 console.log(JSON.stringify({passed:true,checks,widths:[1440,768,375]}));
}finally{await browser.close();}
