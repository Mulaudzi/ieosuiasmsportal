import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync,readFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
const base=process.argv[2]||'http://127.0.0.1:4326',channel=process.argv[3]||'chrome';
assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);assert.ok(['chrome','msedge'].includes(channel));
const out=`.sms-audit.local/sending-progress-ui-20261030/browser-${channel}`;mkdirSync(out,{recursive:true});
const browser=await chromium.launch({channel,headless:true});let checks=0;const check=(ok,why)=>{assert.ok(ok,why);checks++;};
try{
 for(const [width,dark]of [[1440,false],[768,false],[375,false],[320,true]]){
  const context=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce'});const errors=[],writes=[],external=[];let phase='sending';
  await context.addInitScript(({dark})=>{const u={id:'sending-progress-fixture',name:'Portal Preview',email:'fixture@example.invalid',role:'user',email_verified:true};localStorage.setItem('auth_user',JSON.stringify(u));localStorage.setItem('auth_token','synthetic-preview-only');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');localStorage.setItem(`sms-ui:${u.id}:dashboard-tour-seen`,'true');localStorage.setItem(`sms-ui:${u.id}:dark`,String(dark));sessionStorage.setItem(`sms-portal-entered:${u.id}`,'1');}, {dark});
  await context.route('**/*',route=>{if(new URL(route.request().url()).origin!==base){external.push(route.request().url());return route.abort();}return route.continue();});
  const snapshot=()=>({id:'42',name:'Sunday gathering',content:'Your weekly gathering reminder.',state:phase==='scheduled'?'scheduled':phase==='queued'?'queued':phase==='complete'?'completed':'processing',recipient_count:485,sent_count:phase==='complete'?485:phase==='held'?484:phase==='queued'||phase==='scheduled'?0:315,failed_submission_count:0,held_submission_count:phase==='held'?1:0,skipped_count:0,queued_count:phase==='complete'?0:phase==='held'?1:phase==='queued'||phase==='scheduled'?485:160,processing_count:phase==='sending'?10:0,delivered_count:120,failed_count:0,awaiting_delivery_count:phase==='complete'?365:195,dlr_unavailable_count:0,credits_used:315,total_segments:485,estimated_cost:169.75,actual_cost:110.25,created_at:'2026-10-30 10:00:00',started_at:phase==='queued'||phase==='scheduled'?null:'2026-10-30 10:01:00',scheduled_at:phase==='scheduled'?'2026-11-01 09:00:00':null,completed_at:phase==='complete'?'2026-10-30 10:02:00':null});
  await context.route('**/api/**',async route=>{
   const req=route.request(),url=new URL(req.url()),path=url.pathname;
   if(req.method()!=='GET'){writes.push(path);return route.fulfill({status:403,json:{success:false}});}
   const c=snapshot();let data={success:true};
   if(path.endsWith('/auth/user'))data={...data,user:{id:'sending-progress-fixture',name:'Portal Preview',email:'fixture@example.invalid',role:'user',email_verified:true}};
   else if(path.endsWith('/dashboard/recent-campaigns')||path.endsWith('/sms/campaigns-v2'))data={...data,campaigns:[c],stats:{total:1,sent:c.sent_count,scheduled:0,credits_used:315},status_counts:{all:1,active:1}};
   else if(path.endsWith('/sms/campaigns-v2/42'))data={...data,campaign:c};
   else if(path.endsWith('/recipient-view'))data={...data,rows:[],total:0};
   else if(path.endsWith('/dashboard/stats'))data={...data,total_recipients:485,total_sent:c.sent_count,total_delivered:120,total_failed:0,total_pending:195,total_awaiting_delivery:195,total_dlr_unavailable:0,total_skipped:0,total_waiting_to_send:170,total_failed_submissions:0,total_contacts:485,active_campaigns:1,delivery_rate:38.1};
   else if(path.endsWith('/notifications'))data={...data,notifications:[],unread_count:0};
   else if(path.endsWith('/portal/overview'))data={...data,contacts:485,bought_credits:true,sent_campaign:true,checked_delivery:true};
   else if(path.includes('/wallet'))data={...data,available_credits:850,balance:297.5,reserved:0,reserved_credits:0,credit_transfers_enabled:true};
   else data={...data,groups:[{id:1,name:'Members',contact_count:485}],contacts:[],chart:[],series:[]};
   return route.fulfill({json:data});
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  for(const path of ['/dashboard','/sms-campaigns','/sms-campaigns/42']){
   phase='sending';await page.goto(base+path,{waitUntil:'networkidle'});
   const bar=page.getByRole('progressbar',{name:path==='/sms-campaigns'?'Sending progress for Sunday gathering':'Sending progress',exact:true});
   await bar.waitFor();check(await bar.getAttribute('value')==='65',`${path} actual sending percent`);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${path} no page overflow at ${width}`);
   if(path!=='/sms-campaigns'){
    const card=page.getByRole('region',{name:'Campaign Sending Overview'});
    check(await card.getAttribute('data-motion')==='sending','Active sending presentation');
    check(await card.getByText('Sending in progress',{exact:true}).isVisible(),'Clear sending state');
    check(await card.locator('.sending-overview__symbol svg').evaluate(el=>getComputedStyle(el).animationName)==='none','Reduced motion respected');
    await card.screenshot({path:`${out}/${path==='/dashboard'?'dashboard':'campaign'}-${width}.png`});
   }else await page.screenshot({path:`${out}/campaign-list-${width}.png`,fullPage:true});
  }
  for(const [next,title,value]of [['queued','Your campaign is in the queue','0'],['scheduled','Scheduled and ready','0'],['held','Awaiting provider confirmation','99'],['complete','Your SMS campaign has been sent successfully!','100']]){
   phase=next;await page.reload({waitUntil:'networkidle'});const card=page.getByRole('region',{name:'Campaign Sending Overview'});await card.waitFor();
   check(await card.getByRole('heading',{name:title,exact:true}).isVisible(),`${next} title`);
    check(await card.getByRole('progressbar').getAttribute('value')===value,`${next} real progress`);
   check(await card.getAttribute('data-motion')==='still',`${next} is not animated as sending`);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${next} mobile layout`);
   if(next==='held'){
    if(dark){const contrast=await card.evaluate(el=>{const luminance=rgb=>{const values=rgb.match(/[\d.]+/g).slice(0,3).map(v=>Number(v)/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);return .2126*values[0]+.7152*values[1]+.0722*values[2];};const background=luminance(getComputedStyle(el).backgroundColor),text=luminance(getComputedStyle(el.querySelector('[data-metric-tone="success"] dd')).color);return (Math.max(background,text)+.05)/(Math.min(background,text)+.05);});check(contrast>=4.5,'Sending counts remain legible in portal dark mode');}
    await card.screenshot({path:`${out}/awaiting-confirmation-${width}.png`});
   }
  }
  check(writes.length===0,'UI performs no sends/retries/payments or other writes');
  check(external.length===0,'No external network requests');check(errors.length===0,errors.join('\n'));
  await context.close();
 }
 const indexHash=createHash('sha256').update(readFileSync('.sms-audit.local/sending-progress-ui-20261030/source/dist/index.html')).digest('hex');
 writeFileSync(`${out}/result.json`,JSON.stringify({passed:true,checks,channel,viewports:[1440,768,375,320],build_index_sha256:indexHash,intercepted_api:true,external_traffic:false,production_writes:false},null,2));
 console.log(`PASS: ${checks} ${channel} sending progress checks. No live requests.`);
}finally{await browser.close();}
