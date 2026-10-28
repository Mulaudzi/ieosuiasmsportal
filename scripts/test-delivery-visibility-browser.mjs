import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
const base=process.argv[2]||'http://127.0.0.1:4234';
const output=process.argv[3]||'.sms-audit.local/delivery-visibility-release/browser';
const smoke=process.argv.includes('--smoke');
mkdirSync(output,{recursive:true});
const browser=await chromium.launch({headless:true,channel:'chrome'});
let checks=0;const count=value=>{assert.ok(value);checks++;};
try{
 for(const width of (smoke?[375,1440]:[320,375,768,1440])){
  const context=await browser.newContext({viewport:{width,height:950},bypassCSP:base.startsWith('http://127.0.0.1:')});
  const user={id:'delivery-visibility-fixture',name:'Fixture',email:'fixture@example.invalid',role:'user',email_verified:true};
  await context.addInitScript(user=>{
   localStorage.setItem('auth_user',JSON.stringify(user));localStorage.setItem('auth_token','fixture-not-a-real-token');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');localStorage.setItem(`sms-ui:${user.id}:dashboard-tour-seen`,'true');
  },user);
  let phase='pending';
  const fixture=()=>{
   const delivered=phase==='complete'?8:phase==='partial'||phase==='unavailable'?5:0;
   const failed=phase==='complete'?1:0;const awaiting=phase==='pending'?9:phase==='partial'?4:0;const unavailable=phase==='unavailable'?4:0;
   const queued=phase==='queued'?9:0;
   const campaign={id:'1',name:'Visibility fixture',type:'sms',state:queued?'processing':'completed',status:queued?'processing':'completed',recipient_count:10,sent_count:queued?0:9,delivered_count:delivered,failed_count:failed,awaiting_delivery_count:awaiting,dlr_unavailable_count:unavailable,queued_count:queued,processing_count:0,skipped_count:1,credits_used:9,total_segments:10,content:'A fixture SMS. No SMS is actually sent.',estimated_cost:3.5,actual_cost:3.15,created_at:'2026-10-20 09:00:00'};
   const stats={total_sent:9,total_contacts:3,active_campaigns:0,total_delivered:delivered,total_failed:failed,total_pending:awaiting+unavailable+queued,total_awaiting_delivery:awaiting,total_dlr_unavailable:unavailable,total_skipped:1,delivery_rate:phase==='complete'?88.9:0};
   return {campaign,stats,summary:{total_messages:9,delivered,failed,pending:stats.total_pending,delivery_rate:stats.delivery_rate,avg_delivery_time:'0s'},sms:{total_sent:9,delivered,failed,pending:stats.total_pending,awaiting_delivery:awaiting,dlr_unavailable:unavailable,skipped:1,credits_used:9}};
  };
  await context.route('**/api/**',async route=>{
   const url=new URL(route.request().url()),path=url.pathname;const data=fixture();let body={success:true};
   assert.equal(route.request().method(),'GET','The UI test must not mutate any API');
   if(path.endsWith('/auth/user'))body={...body,user};
   else if(path.endsWith('/wallet'))body={...body,wallet:{balance:35,sms_credits:100,price_per_credit:.35}};
   else if(path.endsWith('/notifications'))body={...body,notifications:[],unread_count:0};
   else if(path.endsWith('/portal/overview'))body={...body,contacts:3,bought_credits:true,sent_campaign:true,checked_delivery:true};
   else if(path.endsWith('/dashboard/stats'))body={...body,...data.stats};
   else if(path.endsWith('/dashboard/recent-campaigns'))body={...body,campaigns:[data.campaign]};
   else if(path.endsWith('/dashboard/chart')||path.endsWith('/reports/chart'))body={...body,chart:[]};
   else if(path.endsWith('/reports/stats'))body={...body,summary:data.summary,sms:data.sms};
   else if(path.endsWith('/reports/delivery'))body={...body,breakdown:[{status:'Delivered',count:data.stats.total_delivered},{status:'Not Delivered',count:data.stats.total_failed}]};
   else if(path.endsWith('/reports/confirmation-time'))body={...body,seconds:120};
   else if(path.endsWith('/recipient-view'))body={...body,rows:[{id:'1',contact:'Fixture contact',number:'+27821234567',status:phase==='complete'?'Delivered':'Delivery Confirmation Pending',segments:1,sent:'2026-10-20 09:00',result:phase==='complete'?'Network confirmed the SMS was delivered.':'SMS was successfully sent, but delivery confirmation is still pending.'}],total:1};
   else if(path.endsWith('/sms/campaigns-v2/1'))body={...body,campaign:data.campaign};
   else if(path.endsWith('/sms/campaigns-v2'))body={...body,campaigns:[data.campaign],stats:{total:1,sent:9,scheduled:0,credits_used:9}};
   await route.fulfill({json:body});
  });
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(const current of (smoke?['pending','complete']:['pending','partial','unavailable','queued','complete'])){
   phase=current;
   for(const path of ['/sms-campaigns','/sms-campaigns/1','/dashboard','/reports']){
    await page.goto(base+path,{waitUntil:'networkidle'});
    const complete=phase==='complete';
    if(path==='/sms-campaigns'){
     if(complete){await page.getByRole('button',{name:'Delivery Breakdown',exact:true}).waitFor();count(true);}
     else {await page.getByRole('status').filter({hasText:phase==='queued'?'Waiting for sending to finish':'SMS sent; awaiting delivery confirmations'}).waitFor();count(await page.getByRole('button',{name:'Delivery Breakdown',exact:true}).count()===0);}
    }else if(path==='/sms-campaigns/1'){
     const metrics=page.getByRole('region',{name:'Campaign totals'});const totals=page.locator('[aria-label="Campaign totals"]');
     await totals.waitFor();
     count((await totals.getByText('Delivered',{exact:true}).count())===(complete?1:0));
     if(!complete)count(await totals.getByText('Sent',{exact:true}).count()===1);
     count(await page.getByRole('table').getByText('Fixture contact').count()===1);
    }else if(path==='/dashboard'){
     await page.getByRole('heading',{name:'Recent Campaigns',exact:true}).waitFor();
     count(await page.getByText('9 sent / 10 recipients',{exact:false}).count()===(phase==='queued'?0:1));
     count(await page.getByText('88.9%',{exact:true}).count()===(complete?2:0));
     if(!complete)count(await page.getByRole('status').count()>=1);
    }else{
     await page.getByRole('heading',{name:'SMS Performance',exact:true}).waitFor();
     const performance=page.getByRole('heading',{name:'SMS Performance',exact:true}).locator('..').locator('..').locator('..');
     count(await performance.getByText('Delivered',{exact:true}).count()===(complete?1:0));
    }
    if(current==='pending'||current==='complete')await page.screenshot({path:`${output}/${current}-${path.replaceAll('/','_')}-${width}.png`,fullPage:true});
   }
  }
  count(errors.length===0);
  count(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  if(width===1440&&!smoke){
   phase='pending';await page.goto(base+'/dashboard',{waitUntil:'networkidle'});
   await page.getByRole('status').filter({hasText:'SMS sent; awaiting delivery confirmations'}).waitFor();
   phase='complete';await page.getByText('88.9%',{exact:true}).first().waitFor({timeout:35000});
   count(await page.getByRole('status').filter({hasText:'SMS sent; awaiting delivery confirmations'}).count()===0);
   count(new URL(page.url()).pathname==='/dashboard');
  }
  await context.close();
 }
 writeFileSync(`${output}/results.json`,JSON.stringify({passed:checks,failed:0,scope:'Built UI with all APIs intercepted; no real SMS, accounts, credits or emails'},null,2));
 console.log(`PASS ${checks} delivery visibility browser checks`);
}finally{await browser.close();}
