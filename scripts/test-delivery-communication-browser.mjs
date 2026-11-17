import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync,readFileSync} from 'node:fs';
const base=process.argv[2]||'http://127.0.0.1:4235';
const out=process.argv[3]||'.sms-audit.local/delivery-communication-review-20261020/browser';
mkdirSync(out,{recursive:true});let checks=0;
function check(value,message){assert.ok(value,message);checks++;}
const labels={delivered:'Delivered',sent:'Sent - Awaiting Confirmation',dlr_unavailable:'Sent - Confirmation Unavailable',failed:'Not Delivered',skipped:'Not Sent',pending:'Waiting to send',processing:'Sending',failed_to_send:'Failed to send'};
const details={delivered:'Delivery confirmed by the mobile network.',sent:'Your SMS has been successfully sent for delivery. We are awaiting confirmation from the mobile network.',dlr_unavailable:'Your SMS was successfully sent for delivery, but a final delivery confirmation is unavailable. This does not necessarily mean that the message was not delivered.',failed:'The mobile network reported that your SMS could not be delivered.',skipped:'This SMS was excluded before sending, for example because the recipient opted out.',failed_to_send:'The SMS provider did not accept this message for delivery. It was not successfully sent.'};
const blockedDetail='Blocked by the messaging provider or recipient restrictions. The exact reason is unavailable.';
const browser=await chromium.launch({channel:'chrome',headless:true});
let currentPage;const browserErrors=[];
try{
 for(const [width,dark] of [[1440,false],[375,false],[375,true]]){
  const context=await browser.newContext({viewport:{width,height:1000},acceptDownloads:true});
  const requests=[],errors=[];let phase='mixed';
  await context.addInitScript(({dark})=>{
   localStorage.setItem('auth_token','synthetic-only-not-a-real-token');localStorage.setItem('auth_token_issued',String(Date.now()));
   localStorage.setItem('auth_user',JSON.stringify({id:'fixture-user',name:'Fixture user',email:'fixture@example.invalid',role:'user',email_verified:true}));
   localStorage.setItem('sms-ui:fixture-user:dark',String(dark));localStorage.setItem('sms-ui:fixture-user:dashboard-tour-seen','true');sessionStorage.setItem('sms-portal-entered:fixture-user','1');
   localStorage.setItem('ieosuia_cookie_consent','{}');
  },{dark});
  function snapshot(){
   const keys=phase==='accepted'?['delivered','sent','sent','sent']:['delivered',phase==='updated'?'delivered':'sent',phase==='updated'?'delivered':'dlr_unavailable','failed','skipped','failed_to_send','pending','processing'];
   const count=key=>keys.filter(value=>value===key).length;
   const campaign={id:'1',name:'Fixture campaign',content:'Fixture SMS content',state:phase==='accepted'?'completed':'processing',recipient_count:keys.length,sent_count:4,failed_submission_count:count('failed_to_send'),delivered_count:count('delivered'),failed_count:count('failed'),skipped_count:count('skipped'),awaiting_delivery_count:count('sent'),dlr_unavailable_count:count('dlr_unavailable'),queued_count:count('pending'),processing_count:count('processing'),credits_used:8,total_segments:keys.length*2,estimated_cost:5.6,actual_cost:2.8,created_at:'2026-10-20 08:00:00',started_at:'2026-10-20 08:01:00',completed_at:phase==='accepted'?'2026-10-20 08:02:00':null};
   const rows=keys.map((key,index)=>({id:String(index+1),contact:`Fixture contact ${index+1}`,number:`+2763000000${index+1}`,state:key==='failed_to_send'?'failed':key,technical_status:key.toUpperCase(),status:labels[key],segments:2,sent:['pending','processing','skipped','failed_to_send'].includes(key)?'':'20 Oct 2026, 08:01',result:key==='failed'?'LogicSMS delivery status: UNDELIVERED':details[key]??'Waiting for submission.'}));
   if(phase==='blocked')rows[3].reason_code='provider_blocked';
   return {campaign,rows,keys,count};
  }
  await context.route('**/api/**',async route=>{
   const request=route.request(),url=new URL(request.url()),path=url.pathname;requests.push(path);
   assert.equal(request.method(),'GET','Delivery refresh must never send SMS, retry submissions or alter credits');
   const {campaign:c,rows,keys,count}=snapshot();let result={success:true};
   if(path.endsWith('/recipient-export')){
    const filter=url.searchParams.get('state');const chosen=rows.filter((row,index)=>filter==='all'||keys[index]===filter);
    const lines=['Contact,Number,Status,Segments,Sent,Result',...chosen.map((row,index)=>[row.contact,row.number,row.status,row.segments,row.sent,row.reason_code==='provider_blocked'?blockedDetail:details[keys[Number(row.id)-1]]??'Waiting for submission.'].map(value=>JSON.stringify(String(value))).join(','))];
    return route.fulfill({headers:{'content-type':'text/csv','content-disposition':'attachment; filename="fixture-campaign-all-recipients.csv"','access-control-expose-headers':'Content-Disposition'},body:lines.join('\n')});
   }
   if(path.endsWith('/recipient-view')){
    const filter=url.searchParams.get('state')??'all';const filtered=rows.filter((row,index)=>filter==='all'||keys[index]===filter);
    result={...result,rows:filtered,total:filtered.length};
   }else if(path.endsWith('/sms/campaigns-v2/1'))result={...result,campaign:c};
   else if(path.endsWith('/sms/campaigns-v2'))result={...result,campaigns:[{...c,status:c.state}],stats:{total:1,sent:c.sent_count,scheduled:0,credits_used:8}};
   else if(path.endsWith('/dashboard/stats'))result={...result,total_recipients:c.recipient_count,total_sent:4,total_delivered:c.delivered_count,total_failed:c.failed_count,total_pending:c.awaiting_delivery_count+c.dlr_unavailable_count,total_awaiting_delivery:c.awaiting_delivery_count,total_dlr_unavailable:c.dlr_unavailable_count,total_skipped:c.skipped_count,total_waiting_to_send:c.queued_count+c.processing_count,total_failed_submissions:c.failed_submission_count,total_contacts:8,active_campaigns:1,delivery_rate:c.delivered_count*25};
   else if(path.endsWith('/dashboard/recent-campaigns'))result={...result,campaigns:[{...c,status:c.state}]};
   else if(path.endsWith('/reports/stats'))result={...result,summary:{total_messages:4,delivered:c.delivered_count,failed:c.failed_count,pending:c.awaiting_delivery_count+c.dlr_unavailable_count,avg_delivery_time:'Under 1 min',delivery_rate:c.delivered_count*25},sms:{total_recipients:c.recipient_count,total_sent:4,delivered:c.delivered_count,failed:c.failed_count,pending:c.awaiting_delivery_count+c.dlr_unavailable_count,awaiting_delivery:c.awaiting_delivery_count,dlr_unavailable:c.dlr_unavailable_count,skipped:c.skipped_count,failed_submissions:c.failed_submission_count,waiting_to_send:c.queued_count+c.processing_count,credits_used:8}};
   else if(path.endsWith('/reports/delivery'))result={...result,breakdown:Object.entries(labels).map(([key,status])=>({status,count:count(key)}))};
   else if(path.endsWith('/portal/overview'))result={...result,contacts:8,bought_credits:true,sent_campaign:true,checked_delivery:true};
   else if(path.endsWith('/notifications'))result={...result,notifications:[],unread_count:0};
   else if(path.endsWith('/reports/confirmation-time'))result={...result,seconds:null};
   else if(path.includes('wallet'))result={...result,balance:137,available_credits:137,reserved_credits:0};
   else result={...result,chart:[],groups:[],contacts:[],balance:137};
   return route.fulfill({json:result});
  });
  const page=await context.newPage();currentPage=page;page.on('pageerror',error=>{errors.push(error.message);browserErrors.push(error.message);});
  page.on('console',message=>{if(message.type()==='error')browserErrors.push(message.text());});
  page.on('requestfailed',request=>browserErrors.push(`${new URL(request.url()).pathname}: ${request.failure()?.errorText}`));
  async function noOverflow(){check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`No viewport overflow at ${width}, dark=${dark}`);}
  await page.goto(base+'/sms-campaigns/1',{waitUntil:'networkidle'});
  await page.getByRole('heading',{name:'Recipient delivery details'}).waitFor();
  check(await page.getByRole('region',{name:'Campaign Sending Overview'}).count()===1,'Sending overview is primary and present');
  const failed=page.getByRole('row').filter({hasText:'Fixture contact 4'});
  check((await failed.locator('td').nth(5).innerText())===details.failed,'Result uses the network failure meaning, never raw LogicSMS output');
  const unsent=page.getByRole('row').filter({hasText:'Fixture contact 6'});
  check((await unsent.locator('td').nth(2).innerText())==='Failed to send','Failed submission distinct from deliberate exclusion and network failure');
  check(!(await page.locator('body').innerText()).includes('LogicSMS delivery status'),'No raw provider diagnostics anywhere in customer page');
  await page.getByRole('button',{name:'Delivery Breakdown',exact:true}).click();
  for(const label of ['Delivered','Sent - Awaiting Confirmation','Sent - Confirmation Unavailable','Not Delivered','Not Sent'])check(await page.getByText(label,{exact:true}).count()>0,`Partial breakdown exposes ${label} immediately`);
  check(await page.getByText('Failed submissions',{exact:true}).count()>0,'Unsent failure total remains outside five delivery outcomes');
  await page.keyboard.press('Escape');await noOverflow();
  const csvButton=page.getByRole('button',{name:'Export CSV',exact:true});await csvButton.click();
  const [download]=await Promise.all([page.waitForEvent('download'),page.getByRole('menuitem',{name:'All recipients',exact:true}).click()]);
  const csv=readFileSync(await download.path(),'utf8');
  check(download.suggestedFilename()==='fixture-campaign-all-recipients.csv','CSV belongs to selected campaign');
  check(csv.startsWith('Contact,Number,Status,Segments,Sent,Result')&&!csv.includes('LogicSMS delivery status'),'CSV headers and safe results match the portal');
  check(csv.split('\n').length===9,'Export includes all eight selected-campaign recipients');
  phase='blocked';await page.reload({waitUntil:'networkidle'});
  const blockedRow=page.getByRole('row').filter({hasText:'Fixture contact 4'});
  check((await blockedRow.locator('td').nth(2).innerText())==='Not Delivered','Blocked stays in the established delivery category');
  check((await blockedRow.locator('td').nth(5).innerText())===blockedDetail,'Blocked explanation does not invent an opt-out or network attribution');
  await page.getByRole('button',{name:'Export CSV',exact:true}).click();
  const [blockedDownload]=await Promise.all([page.waitForEvent('download'),page.getByRole('menuitem',{name:'All recipients',exact:true}).click()]);
  check(readFileSync(await blockedDownload.path(),'utf8').includes(blockedDetail),'Blocked explanation matches downloaded fixture CSV');
  await page.screenshot({path:`${out}/blocked-${width}-${dark?'dark':'light'}.png`,fullPage:true});
  phase='updated';
  await page.evaluate(()=>dispatchEvent(new Event('focus')));
  await page.waitForFunction(()=>Array.from(document.querySelectorAll('tr')).some(row=>row.textContent.includes('Fixture contact 2')&&row.querySelectorAll('td')[2]?.textContent==='Delivered'));
  const updated=page.getByRole('row').filter({hasText:'Fixture contact 2'});
  check((await updated.locator('td').nth(5).innerText())===details.delivered,'State and Result update together after a new confirmation');
  check(await page.getByRole('button',{name:'Retry failed submissions',exact:true}).count()===1,'Only genuine failed submissions have a retry action');
  await page.screenshot({path:`${out}/campaign-${width}-${dark?'dark':'light'}.png`,fullPage:true});
  await page.goto(base+'/dashboard',{waitUntil:'networkidle'});
  check(await page.getByRole('heading',{name:'Delivery Confirmations',exact:true}).count()===1,'Dashboard shows available delivery confirmations immediately');
  check(await page.getByRole('region',{name:'Campaign Sending Overview'}).count()===1,'Dashboard separates sending overview from delivery outcomes');
  await noOverflow();
  await page.goto(base+'/reports',{waitUntil:'networkidle'});
  await page.getByRole('heading',{name:'SMS Performance',exact:true}).waitFor();
  check(await page.getByText('Sent - Confirmation Unavailable',{exact:true}).count()>0,'Reports use consistent unavailable terminology');
  check(await page.getByText('Not Sent',{exact:true}).count()>0,'Reports show excluded messages separately');
  await noOverflow();
  await page.goto(base+'/sms-campaigns',{waitUntil:'networkidle'});
  await page.getByRole('button',{name:'Delivery Breakdown',exact:true}).waitFor();
  check(await page.getByRole('button',{name:'Delivery Breakdown',exact:true}).count()===1,'Campaign history exposes partial confirmations');
  await noOverflow();
  phase='accepted';await page.goto(base+'/sms-campaigns/1',{waitUntil:'networkidle'});
  check(await page.getByText('Your SMS campaign has been sent successfully!',{exact:true}).count()===1,'Acceptance is successful before delivery confirmations finish');
  check(await page.getByRole('button',{name:'Retry failed submissions',exact:true}).count()===0,'Missing postbacks never offer resending accepted messages');
  check(requests.every(path=>!path.includes('querysms')&&!path.endsWith('/retry')),'Only internal read refreshes, no provider lookup or duplicate sending');
  check(errors.length===0,`No uncaught JavaScript errors: ${errors.join(', ')}`);
  await context.close();
 }
 writeFileSync(`${out}/results.json`,JSON.stringify({passed:true,checks,widths:[1440,375],darkModeChecked:true,allApiRequestsIntercepted:true,productionWrites:false,smsSent:0,emailSent:0},null,2));
 console.log(`PASS: ${checks} delivery communication browser assertions; fixtures only, no production writes.`);
}catch(error){
 if(currentPage&&!currentPage.isClosed()){
  await currentPage.screenshot({path:`${out}/failure.png`,fullPage:true});
  writeFileSync(`${out}/failure.json`,JSON.stringify({error:String(error),url:currentPage.url(),body:await currentPage.locator('body').innerText(),browserErrors},null,2));
 }
 throw error;
}finally{await browser.close();}
