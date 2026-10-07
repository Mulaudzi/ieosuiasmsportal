import {chromium} from 'playwright';
import assert from 'node:assert/strict';
const base=process.argv[2]||'http://127.0.0.1:4220';
const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
const user={id:'prompt-fixture',name:'Fixture',email:'fixture@example.invalid',role:'user',email_verified:true};
const campaign={id:'41',name:'AUTUMN CUSTOMER UPDATE',state:'partially_failed',status:'partially_failed',content:'A complete test message.\nThis final line must remain readable.',recipient_count:13,queued_count:3,processing_count:1,awaiting_delivery_count:1,dlr_unavailable_count:1,delivered_count:5,failed_count:1,skipped_count:1,total_segments:13,estimated_cost:4.55,actual_cost:2.8,created_at:'2026-10-03 10:00:00',started_at:'2026-10-03 10:01:00',completed_at:'2026-10-03 10:02:00',sent_count:8,credits_used:8};
const statuses=['Delivered','Not Delivered','Pending dispatch','Processing','Delivery Confirmation Pending','Delivery Status Unavailable','Skipped'];
const rows=statuses.map((status,i)=>({id:String(i+1),contact:'Contact '+(i+1),number:'+2700000000'+i,status,segments:1,sent:'3 Oct 2026, 10:01',result:status==='Not Delivered'?'invalid number':'—'}));
try{
 const context=await browser.newContext({viewport:{width:1440,height:1000}});
 await context.addInitScript(u=>{localStorage.setItem('auth_user',JSON.stringify(u));localStorage.setItem('auth_token','fixture');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');},user);
 let sent=false,confirmation=176400,emptyExport=false;const downloads=[],queries=[];let mutations=0;
 await context.route('**/api/**',async route=>{
  const url=new URL(route.request().url()),path=url.pathname;let body={success:true,notifications:[],unread_count:0,data:[]};
  if(route.request().method()!=='GET')mutations++;
  if(path.endsWith('/auth/user'))body={success:true,user};
  else if(path.endsWith('/portal/overview'))body={success:true,contacts:7,bought_credits:true,sent_campaign:sent,checked_delivery:sent};
  else if(path.endsWith('/wallet'))body={success:true,wallet:{balance:7,available:7,sms_credits:20,price_per_credit:.35}};
  else if(path.endsWith('/wallet/stats'))body={success:true,balance:7,sms_credits:20,price_per_credit:.35,used_this_month:8,total_spent:999};
  else if(path.endsWith('/wallet/activity')){queries.push(Object.fromEntries(url.searchParams));body={success:true,total_paid:10,total:1,page:1,last_page:1,transactions:url.searchParams.get('search')==='absent'?[]:[{id:'p1',created_at:'2026-10-03 10:00:00',type:'Purchase',reference:'PAY-1',detail:'PAY-1',amount:10,credits:28,status:'paid'}]};}
  else if(path.endsWith('/sms/campaigns-v2'))body={success:true,campaigns:[campaign],stats:{total:1,sent:8,scheduled:0,credits_used:8}};
  else if(path.endsWith('/sms/campaigns-v2/41'))body={success:true,campaign};
  else if(path.endsWith('/recipient-view')){const state=url.searchParams.get('state');const lookup={delivered:'Delivered',failed:'Not Delivered',pending:'Pending dispatch',processing:'Processing',sent:'Delivery Confirmation Pending'};const filtered=url.searchParams.get('search')==='absent'?[]:rows.filter(r=>!lookup[state]||r.status===lookup[state]);body={success:true,rows:filtered,total:filtered.length};}
  else if(path.endsWith('/recipient-export')){downloads.push(Object.fromEntries(url.searchParams));if(emptyExport)return route.fulfill({status:422,json:{success:false,message:'No not delivered recipients'}});const format=url.searchParams.get('format'),choice=url.searchParams.get('state');return route.fulfill({contentType:format==='pdf'?'application/pdf':'text/csv',headers:{'Content-Disposition':`attachment; filename="autumn-customer-update-${choice}.${format}"`,'Access-Control-Expose-Headers':'Content-Disposition'},body:format==='pdf'?'%PDF-1.4\nFixture':'Contact,Number,Status,Segments,Sent,Result\n'});}
  else if(path.endsWith('/confirmation-time'))body={success:true,seconds:confirmation,sample_count:confirmation===null?0:8};
  else if(path.endsWith('/reports/stats'))body={success:true,summary:{total_messages:8,delivered:5,failed:1,pending:2,avg_delivery_time:'176400s',delivery_rate:62.5},sms:{total_sent:8,delivered:5,failed:1,pending:2,awaiting_delivery:1,dlr_unavailable:1,credits_used:8,skipped:1}};
  else if(path.endsWith('/reports/chart')||path.endsWith('/dashboard/chart'))body={success:true,chart:[]};
  else if(path.endsWith('/reports/delivery'))body={success:true,breakdown:[]};
  else if(path.endsWith('/dashboard/stats'))body={success:true,total_sent:8,total_delivered:5,total_failed:1,total_pending:2,total_awaiting_delivery:1,total_dlr_unavailable:1,total_skipped:1,total_contacts:7,delivery_rate:62.5};
  else if(path.endsWith('/dashboard/recent-campaigns'))body={success:true,campaigns:[campaign]};
  return route.fulfill({json:body});
 });
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'/dashboard');
 let dialog=page.getByRole('dialog',{name:'Check your credits'});await dialog.waitFor();assert.match(await dialog.innerText(),/20 credits.*R0.35/);assert.equal(await dialog.getByRole('button',{name:'Close',exact:true}).count(),0);
 await dialog.getByRole('button',{name:'Next',exact:true}).click();dialog=page.getByRole('dialog',{name:'Add your people'});await dialog.waitFor();assert.match(await dialog.innerText(),/7 contacts/);
 await dialog.getByRole('button',{name:'Next',exact:true}).click();await page.getByRole('dialog',{name:'Send a campaign'}).getByRole('button',{name:'Next',exact:true}).click();await page.getByRole('dialog',{name:'Read the result'}).getByRole('button',{name:'Done',exact:true}).click();
 await page.getByRole('region',{name:'Get started checklist'}).waitFor();
 await page.reload();await page.getByRole('heading',{level:1,name:'Dashboard'}).waitFor();assert.equal(await page.getByRole('dialog').count(),0);
 await page.getByRole('button',{name:'Tutorial',exact:true}).click();await page.getByRole('dialog',{name:'Check your credits'}).getByRole('button',{name:'Skip tour'}).click();
 await page.goto(base+'/settings');await page.getByRole('tab',{name:'Get Started',exact:true}).click();const checklist=page.getByRole('region',{name:'Get started checklist'});await checklist.waitFor();assert.deepEqual(await checklist.getByRole('link').allTextContents(),['Add contacts','Buy credits','Send a test SMS','Check delivery']);assert.equal(await checklist.getByLabel('Completed',{exact:true}).count(),2);
 await page.goto(base+'/sms-campaigns');await page.getByText('Partially delivered',{exact:true}).waitFor();const campaignRow=page.getByRole('row').filter({hasText:'AUTUMN CUSTOMER UPDATE'});assert.ok(await campaignRow.getByText('Delivered',{exact:true}).isVisible());assert.ok(await campaignRow.getByText('Not delivered',{exact:true}).isVisible());
 await page.goto(base+'/sms-campaigns/41');await page.getByRole('heading',{level:1,name:'Autumn customer update'}).waitFor();
 assert.ok(await page.getByText('Partially delivered',{exact:true}).isVisible());
 const cards=page.locator('[aria-label="Campaign totals"]');assert.equal(await cards.locator(':scope > div').count(),4);const totals=await cards.innerText();
 await page.getByRole('combobox',{name:'Recipient status'}).click();await page.getByRole('option',{name:'Processing',exact:true}).click();await page.getByRole('cell',{name:'Processing',exact:true}).waitFor();assert.equal(await cards.innerText(),totals);
 assert.deepEqual(await page.getByRole('columnheader').allTextContents(),['Contact','Number','Status','Segments','Sent','Result']);
 await page.getByPlaceholder('Search number or contact').fill('absent');await page.getByText('No recipients in this view',{exact:true}).waitFor();assert.equal(await cards.innerText(),totals);await page.getByRole('link',{name:'Show all',exact:true}).click();await page.getByRole('cell',{name:'invalid number',exact:true}).waitFor();
 for(const format of ['CSV','PDF']){await page.getByRole('button',{name:'Export '+format,exact:true}).click();assert.deepEqual(await page.getByRole('menuitem').allTextContents(),['All recipients','Delivered','Not Delivered','Pending dispatch','Processing','Delivery Confirmation Pending']);const downloaded=page.waitForEvent('download');await page.getByRole('menuitem',{name:'Not Delivered',exact:true}).click();assert.equal((await downloaded).suggestedFilename(),`autumn-customer-update-failed.${format.toLowerCase()}`);}
 assert.deepEqual(downloads.map(d=>d.state),['failed','failed']);emptyExport=true;await page.getByRole('button',{name:'Export CSV',exact:true}).click();await page.getByRole('menuitem',{name:'Not Delivered',exact:true}).click();await page.getByText('No not delivered recipients',{exact:true}).first().waitFor();emptyExport=false;
 for(const width of [320,375,768,1440]){await page.setViewportSize({width,height:1000});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`detail overflow ${width}`);await page.screenshot({path:`.sms-audit.local/prompt-completion-release/details-${width}.png`,fullPage:true});}
 campaign.failed_count=0;await page.getByRole('button',{name:'Refresh',exact:true}).click();await page.getByRole('button',{name:'Retry undelivered messages',exact:true}).waitFor({state:'hidden'});
 await page.goto(base+'/reports');await page.getByText('Typical confirmation time',{exact:true}).waitFor();assert.ok(await page.getByText('Most messages confirmed within 2 days',{exact:true}).isVisible());assert.equal(await page.getByText('Average delivery time',{exact:true}).count(),0);confirmation=null;await page.reload();await page.getByRole('heading',{level:1,name:'Reports'}).waitFor();assert.equal(await page.getByText('Typical confirmation time',{exact:true}).count(),0);
 await page.goto(base+'/wallet');await page.getByRole('cell',{name:'PAY-1',exact:false}).waitFor();assert.ok(await page.getByText('Low balance',{exact:true}).isVisible());assert.equal(await page.getByText('R999.00',{exact:true}).count(),0);assert.ok(await page.getByText('R10.00',{exact:true}).first().isVisible());assert.equal(queries.at(-1).per_page,'5');
 await page.getByPlaceholder('Search by reference or amount').fill('absent');await page.getByText('No transactions match these filters.',{exact:true}).waitFor();await page.getByPlaceholder('Search by reference or amount').fill('PAY');
 await page.getByRole('combobox',{name:'Payment status'}).click();await page.getByRole('option',{name:'Paid',exact:true}).click();await page.getByRole('combobox',{name:'Transaction dates'}).click();await page.getByRole('option',{name:'Custom',exact:true}).click();await page.getByLabel('Start date',{exact:true}).fill('2026-10-01');await page.getByLabel('End date',{exact:true}).fill('2026-10-07');
 const response=page.waitForResponse(r=>r.url().includes('/wallet/activity')&&r.url().includes('per_page=20'));await page.getByRole('link',{name:'View all',exact:true}).click();await response;assert.equal(queries.at(-1).status,'paid');assert.equal(queries.at(-1).search,'PAY');assert.equal(queries.at(-1).start,'2026-10-01');assert.equal(queries.at(-1).end,'2026-10-07');
 sent=true;await page.goto(base+'/dashboard');await page.getByRole('heading',{level:1,name:'Dashboard'}).waitFor();assert.equal(await page.getByRole('region',{name:'Get started checklist'}).count(),0);
 await page.setViewportSize({width:375,height:812});await page.getByRole('button',{name:'Open navigation',exact:true}).click();await page.getByRole('button',{name:'Tutorial',exact:true}).click();dialog=page.getByRole('dialog',{name:'Check your credits'});await dialog.waitFor();assert.ok(await dialog.evaluate(el=>el.getBoundingClientRect().right<=innerWidth&&el.getBoundingClientRect().left>=0));await dialog.getByRole('button',{name:'Skip tour'}).click();
 assert.equal(mutations,0,'Tests must never send SMS or write financial state');assert.deepEqual(errors,[]);
 console.log('PASS: first-visit/mobile tour, checklist, fixed campaign totals, status export menus/downloads/errors, responsive detail, reliable time card, Wallet filters and View all, no mutations. APIs mocked.');
}finally{await browser.close();}
