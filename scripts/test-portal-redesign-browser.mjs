import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync,readFileSync} from 'node:fs';
const base=process.argv[2]??'http://127.0.0.1:4276';
const out='.sms-audit.local/portal-redesign-20261021/browser';mkdirSync(out,{recursive:true});
let checks=0;const check=(value,message)=>{assert.ok(value,message);checks++;};
const browser=await chromium.launch({channel:'chrome',headless:true});
let currentPage;
const campaign={id:'1',name:'CE South Campaign — Fixture',state:'partially_failed',status:'partially_failed',content:'Real application fields, synthetic test data only.',recipient_count:403,sent_count:403,delivered_count:275,failed_count:127,failed_submission_count:0,awaiting_delivery_count:1,dlr_unavailable_count:0,skipped_count:0,queued_count:0,processing_count:0,credits_used:403,total_segments:403,actual_cost:141.05,estimated_cost:141.05,created_at:'2026-10-03 18:46:00',started_at:'2026-10-03 18:47:00',completed_at:'2026-10-06 00:12:00'};
try{
 for(const width of [1440,1024,768,375,320])for(const dark of [false,true]){
  const context=await browser.newContext({viewport:{width,height:1000},acceptDownloads:true});
  const requests=[],errors=[];let updated=false;
  await context.addInitScript(({dark})=>{
   localStorage.setItem('auth_token','synthetic-only');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('auth_user',JSON.stringify({id:'fixture-user',name:'Fixture User',email:'fixture@example.invalid',role:'user',email_verified:true}));
   localStorage.setItem('sms-ui:fixture-user:dark',String(dark));localStorage.setItem('sms-ui:fixture-user:dashboard-tour-seen','true');sessionStorage.setItem('sms-portal-entered:fixture-user','1');localStorage.setItem('ieosuia_cookie_consent','{}');
  },{dark});
  await context.route('**/api/**',async route=>{
   const req=route.request(),url=new URL(req.url()),path=url.pathname;requests.push(url);assert.equal(req.method(),'GET','UI reads must not submit campaigns, charge or mutate');
   let result={success:true};const c={...campaign,delivered_count:updated?276:275,awaiting_delivery_count:updated?0:1};
   if(path.endsWith('/recipient-export')){
    const pdf=url.searchParams.get('format')==='pdf';return route.fulfill({contentType:pdf?'application/pdf':'text/csv',headers:{'content-disposition':`attachment; filename="fixture.${pdf?'pdf':'csv'}"`},body:pdf?'%PDF-1.4\nsynthetic browser download fixture':'Contact,Number,Status,Segments,Sent,Result\nFixture,+27000000000,Delivered,1,3 Oct 2026,Delivery confirmed by the mobile network.'});
   }
   if(path.endsWith('/recipient-view')){
    let rows=Array.from({length:60},(_,i)=>({id:String(i+1),contact:`Fixture contact ${i+1}`,number:`+27630000${String(i).padStart(3,'0')}`,state:updated||i!==0?'delivered':'sent',technical_status:updated||i!==0?'DELIVERED':'SENT',status:updated||i!==0?'Delivered':'Sent - Awaiting Confirmation',segments:i%2+1,sent:'3 Oct 2026, 18:47'}));
    const search=url.searchParams.get('search');if(search)rows=rows.filter(r=>r.contact.toLowerCase().includes(search.toLowerCase()));
    const segments=url.searchParams.get('segments');if(segments==='1')rows=rows.filter(r=>r.segments===1);if(segments==='multi')rows=rows.filter(r=>r.segments>1);
    result={...result,rows:rows.slice((Number(url.searchParams.get('page')??1)-1)*50,Number(url.searchParams.get('page')??1)*50),total:rows.length};
   }else if(path.endsWith('/sms/campaigns-v2/1'))result={...result,campaign:c};
   else if(path.endsWith('/sms/campaigns-v2'))result={...result,campaigns:[c],stats:{total:1,sent:403,scheduled:0,credits_used:403},status_counts:{all:1,completed:1,active:0,scheduled:0,draft:0}};
   else if(path.endsWith('/dashboard/stats')){const seven=url.searchParams.get('range')==='7d';result={...result,total_recipients:seven?70:403,total_sent:seven?70:403,total_delivered:seven?70:c.delivered_count,total_failed:seven?0:127,total_pending:seven?0:c.awaiting_delivery_count,total_awaiting_delivery:seven?0:c.awaiting_delivery_count,total_dlr_unavailable:0,total_skipped:0,total_failed_submissions:0,total_contacts:690,active_campaigns:0,delivery_rate:seven?100:68.2};}
   else if(path.endsWith('/dashboard/recent-campaigns'))result={...result,campaigns:url.searchParams.get('range')==='7d'?[]:[c]};
   else if(path.endsWith('/dashboard/chart'))result={...result,chart:[{date:'2026-10-03',sent:403}],reporting_start:'2026-10-01 00:00:00',reporting_end:'2026-10-21 23:59:59'};
   else if(path.endsWith('/portal/overview'))result={...result,contacts:690,bought_credits:true,sent_campaign:true,checked_delivery:true};
   else if(path.endsWith('/notifications'))result={...result,notifications:[],unread_count:0};
   else if(path.includes('/wallet'))result={...result,wallet:{balance:137,available:137,reserved:0,sms_credits:403,price_per_credit:.35,currency:'ZAR'}};
   else result={...result,drafts:[],chart:[],groups:[],contacts:[],balance:137};
   await route.fulfill({json:result});
  });
  const page=await context.newPage();currentPage=page;page.on('pageerror',error=>errors.push(error.message));
  async function layout(name){
   await page.evaluate(()=>window.scrollTo(0,0));
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${name}: no viewport overflow at ${width}/${dark}`);
   check(await page.locator('html').getAttribute('data-portal-dark')!==null===dark,`${name}: dark preference preserved`);
   await page.screenshot({path:`${out}/${name}-${width}-${dark?'dark':'light'}.png`,fullPage:true});
  }
  await page.goto(base+'/dashboard',{waitUntil:'networkidle'});
  await page.locator('#metrics-section').getByText('403',{exact:true}).waitFor();
  check(requests.filter(u=>u.pathname.includes('/dashboard/')).every(u=>u.searchParams.get('range')==='30d'),'All dashboard requests default to 30d');
  check(await page.getByText('Completed',{exact:true}).count()>=1,'Legacy network failures display Completed');
  check(!(await page.locator('main').innerText()).includes('unsuccessful submissions'),'No misleading legacy sending error');
  check(!(await page.getByRole('region',{name:'Message Volume'}).innerText()).includes('Email'),'No unsupported Email chart');
  if((width===1440||width===320)&&!dark){
   await page.getByRole('combobox',{name:'Date range',exact:true}).click();await page.getByRole('option',{name:'Last 7 days',exact:true}).click();await page.locator('#metrics-section').getByText('70',{exact:true}).waitFor();
   check(requests.some(u=>u.pathname.endsWith('/dashboard/chart')&&u.searchParams.get('range')==='7d'),'Seven-day selection reaches chart');
   const before=requests.length;
   await page.getByRole('combobox',{name:'Date range',exact:true}).click();await page.getByRole('option',{name:'Custom dates',exact:true}).click();
   await page.getByLabel('Start date',{exact:true}).fill('2026-10-21');await page.getByLabel('End date',{exact:true}).fill('2026-10-01');await page.getByRole('button',{name:'Apply dates'}).click();
   await page.getByRole('alert').filter({hasText:'end date must'}).waitFor();
   check(!requests.slice(before).some(u=>u.searchParams.get('range')==='custom'),'Invalid custom range makes no request');
   await page.getByLabel('Start date',{exact:true}).fill('2026-10-01');await page.getByLabel('End date',{exact:true}).fill('2026-10-21');await page.getByRole('button',{name:'Apply dates'}).click();await page.locator('#metrics-section').getByText('403',{exact:true}).waitFor();
   for(const endpoint of ['stats','chart','recent-campaigns'])check(requests.some(u=>u.pathname.endsWith(`/dashboard/${endpoint}`)&&u.searchParams.get('range')==='custom'&&u.searchParams.get('from')==='2026-10-01'&&u.searchParams.get('to')==='2026-10-21'),`Applied dates reach ${endpoint}`);
  }
  await layout('dashboard');
  await page.goto(base+'/sms-campaigns?search=CE&sort=oldest',{waitUntil:'networkidle'});
  await page.getByRole('region',{name:'Campaign list'}).getByText(campaign.name,{exact:true}).waitFor();
  check(await page.getByRole('textbox',{name:'Search campaigns by name'}).inputValue()==='CE','Campaign search restored from URL');
  check(requests.some(u=>u.pathname.endsWith('/sms/campaigns-v2')&&u.searchParams.get('sort')==='oldest'),'Server sort restored from URL');
  check(await page.getByRole('region',{name:'Campaign list'}).getByText('Completed',{exact:true}).count()===1,'Campaign list truthful badge');
  if(width===1440&&!dark){await page.getByRole('button',{name:'Completed (1)',exact:true}).click();await page.waitForResponse(r=>r.url().includes('status=completed'));check(new URL(page.url()).searchParams.get('status')==='completed','Status tabs update URL');}
  await layout('campaigns');
  await page.goto(base+'/sms-campaigns/1',{waitUntil:'networkidle'});await page.getByRole('heading',{name:'Recipient delivery details',exact:true}).waitFor();
  check(await page.locator('tbody tr').count()===50,'Recipient table renders one 50-row page');
  check(await page.getByRole('region',{name:'Campaign Sending Overview'}).getByRole('progressbar').getAttribute('value')==='100','Network failures do not reduce sending progress');
  check(await page.getByText('68.2%',{exact:true}).count()>=1,'Correct confirmed/accepted delivery rate');
  if(width===1440&&!dark){
   for(const format of ['CSV','PDF']){await page.getByRole('button',{name:`Export ${format}`,exact:true}).click();const [download]=await Promise.all([page.waitForEvent('download'),page.getByRole('menuitem',{name:'All recipients',exact:true}).click()]);check(download.suggestedFilename()===`fixture.${format.toLowerCase()}`,`${format} download preserved`);check(readFileSync(await download.path(),'utf8').startsWith(format==='PDF'?'%PDF-':'Contact,Number'),`${format} downloaded response bytes`);}
   updated=true;await page.evaluate(()=>dispatchEvent(new Event('focus')));await page.getByRole('row').filter({hasText:'Fixture contact 1'}).first().getByText('Delivered',{exact:true}).waitFor();check(true,'Postback-style refreshed confirmation updates recipient badge');
   await page.getByRole('combobox',{name:'Recipient segments',exact:true}).click();await page.getByRole('option',{name:'Multiple segments',exact:true}).click();await page.waitForResponse(r=>r.url().includes('segments=multi'));await page.waitForFunction(()=>document.querySelectorAll('tbody tr').length===30);check(true,'Segments filter uses server filtered page');
  }
  await layout('details');check(errors.length===0,`No page errors: ${errors.join(', ')}`);await context.close();
 }
 writeFileSync(`${out}/results.json`,JSON.stringify({checks,synthetic:true,liveAcceptance:false},null,2));console.log(`PASS: ${checks} portal redesign browser checks; screenshots in ${out}`);
}catch(error){await currentPage?.screenshot({path:`${out}/failure.png`,fullPage:true});writeFileSync(`${out}/failure.txt`,String(error));throw error;}finally{await browser.close();}
