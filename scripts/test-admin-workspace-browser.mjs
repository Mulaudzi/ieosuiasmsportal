import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import { chromium } from 'playwright';
const out=path.resolve(process.argv[3]||'.sms-audit.local/admin-settings-visual-20261105'),dist=path.resolve(process.argv[2]||path.join(out,'frontend'));
await fs.mkdir(out,{recursive:true});
const mime={'.html':'text/html','.js':'text/javascript','.css':'text/css','.png':'image/png','.svg':'image/svg+xml','.json':'application/json'};
const server=createServer(async(req,res)=>{try{const u=new URL(req.url,'http://localhost'),file=path.extname(u.pathname)?path.resolve(dist,'.'+u.pathname):path.join(dist,'index.html');assert.ok(file.startsWith(dist+path.sep));const raw=await fs.readFile(file);res.writeHead(200,{'Content-Type':mime[path.extname(file)]||'application/octet-stream'});res.end(raw)}catch{res.writeHead(404);res.end()}});
await new Promise(r=>server.listen(0,'127.0.0.1',r));const liveBase=process.argv[4];if(liveBase&&liveBase!=='https://sms.ieosuia.com')throw Error('Unapproved live smoke-check origin');const base=liveBase||'http://127.0.0.1:'+server.address().port;
const browser=await chromium.launch({channel:'chrome',headless:true});
const receipt={passed:false,checks:0,cases:[],production_access:!!liveBase,synthetic_identity:true,all_api_calls_intercepted:true,backend_writes:0};const check=(value,message)=>{assert.ok(value,message);receipt.checks++};
const user={id:'admin-ui-fixture',name:'Admin Fixture',email:'admin@example.invalid',role:'admin',email_verified:true,email_verified_at:'2026-11-01'};
const customer={id:7,name:'Customer fixture',email:'customer@example.invalid',is_active:1,sms_credits_available:482,sms_credits_reserved:4,contacts:10,campaigns:2,messages:20};
const campaign={id:23,name:'Measured campaign',state:'completed',recipient_count:20,delivered_count:19,actual_cost:7,user_name:customer.name,user_email:customer.email,created_at:'2026-11-01 10:00:00',sending_timing:{status:'complete',sending_seconds:15,queue_wait_seconds:2,total_seconds:17,source:'accepted_timestamps',accepted_count:20}};
let activePage, activeErrors = [];
try{
 for(const width of (liveBase?[1440,390]:[1440,1024,768,390,320])){
  const context=await browser.newContext({viewport:{width,height:960}}),errors=[],calls=[];
  await context.addInitScript(user=>{localStorage.setItem('auth_user',JSON.stringify(user));localStorage.setItem('auth_token','synthetic-only');localStorage.setItem('auth_token_issued',String(Date.now()));sessionStorage.setItem('admin_session_timestamp',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}')},user);
  await context.route('**/*',async route=>{
   const req=route.request(),url=new URL(req.url()),p=url.pathname;
   if(p.startsWith('/api/')){
    assert.equal(req.method(),'GET','No real or simulated financial/security writes in this browser test');calls.push(p);let data={};
    if(p.endsWith('/auth/user'))data={user};
    else if(p.endsWith('/overview'))data={users:{total:2,active:2,verified:2},campaigns:{total:2,active:1,scheduled:0},messages:{total:20,sent:1,delivered:19},payments:{received:100,completed:1},customer_credits:{sms_credits_available:482,sms_credits_reserved:4,sms_credits_total:486},dispatch:{jobs:{active:1,queued:0,processing:1},messages:{waiting:10,processing:5,held:0},observed_at:'2026-11-05T12:00:00+02:00'}};
    else if(p.endsWith('/customers'))data={customers:[customer]};
    else if(p.endsWith('/campaigns'))data={campaigns:[campaign]};
    else if(p.endsWith('/services'))data={services:[{service_name:'sms-worker',healthy:true,age_seconds:5,last_seen_at:'2026-11-05 12:00:00'}],delivery_reporting:{available:true,status:'healthy',problems:[]}};
    else if(p.endsWith('/provider'))data={available:true,credits:5000,low:false,threshold:1000};
    else if(p.endsWith('/credit-approval-security'))data={available:true,configured:true,is_owner:false};
    else if(p.endsWith('/purchase-options'))data={cash_balance:{enabled:true}};
    else if(p.endsWith('/purchase-orders'))data={orders:[],total:0};
    else if(p.endsWith('/cash-adjustments'))data={enabled:true,adjustments:[],total:0,cash_balance:null};
    else if(p.endsWith('/credit-transfers'))data={enabled:true,transfers:[],total:0,page:1,last_page:1,security:{configured:true}};
    else if(p.endsWith('/payments'))data={payments:[],pagination:{page:1,last_page:1,total:0}};
    else if(p.endsWith('/users'))data={users:[{...customer,role:'user',created_at:'2026-11-01 10:00:00',email_verified_at:'2026-11-01'}]};
    else if(p.endsWith('/stats'))data={total_users:2,active_users:2,pending_sender_ids:0,total_campaigns:2,total_messages:20,total_revenue:100};
    else if(p.endsWith('/system-health')){const health={status:'healthy',message:'Fixture only'};data={health:{database:health,smtp:health,api:health,storage:health,overall:'healthy'}};}
    else if(p.endsWith('/activity-heatmap'))data={heatmap:null};
    else if(p.endsWith('/notifications'))data={notifications:[],unread_count:0};
    else data={jobs:[],campaigns:[],logs:[],settings:[],recipients:[],emails:[],trends:[],notifications:[],unread_count:0};
    await route.fulfill({json:{success:true,...data}});return;
   }
   if(url.origin!==base){await route.abort();return}await route.continue();
  });
  const page=await context.newPage();activePage=page;activeErrors=errors;page.on('pageerror',error=>errors.push(error.message));
  const fit=async(label)=>check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${width}: ${label} fits screen`);
  async function section(value,label,kind='Operations sections'){
   if(width<768)await page.getByLabel('Workspace section').selectOption(value);
   else await page.getByRole('navigation',{name:kind}).getByRole('button',{name:label,exact:true}).click();
   await page.getByRole('heading',{level:2,name:label,exact:true}).waitFor();
  }
  await page.goto(base+'/guymhan',{waitUntil:'networkidle'});await page.getByRole('heading',{level:2,name:'Overview',exact:true}).waitFor();
  check(await page.getByRole('region',{name:'Operations workspaces'}).isVisible(),`${width}: overview has clear workspace destinations`);await fit('overview');
  await page.screenshot({path:path.join(out,`admin-overview-${width}.png`),fullPage:true});
  await section('customers','Customers');await page.getByRole('cell',{name:'Customer fixture customer@example.invalid'}).waitFor();
  check(await page.getByText('Production services',{exact:true}).count()===0,`${width}: customer table not buried below service panels`);await fit('customers');
  check((await page.getByPlaceholder('Search name or email').boundingBox()).y<500,`${width}: customer search immediately reachable`);
  await section('campaigns','Campaigns');await page.getByText('Measured campaign',{exact:true}).waitFor();check(await page.getByRole('columnheader',{name:'Sending time'}).isVisible(),`${width}: campaign timing retained`);await fit('campaigns');
  await section('finance','Finance');await page.getByRole('region',{name:'Finance workspace'}).waitFor();
  const finance=page.getByRole('navigation',{name:'Finance sections'});
  for(const label of ['Rand balances','Credit transfers','Payment history','Approval security','Payment review']){await finance.getByRole('button',{name:label,exact:true}).click();check(await finance.getByRole('button',{name:label,exact:true}).getAttribute('aria-current')==='page',`${width}: ${label} reachable`);await fit(label);}
  check(await page.getByRole('region',{name:'Credit approval security'}).isVisible()===false,`${width}: financial tasks separated`);
  await page.screenshot({path:path.join(out,`admin-finance-${width}.png`),fullPage:true});
  await section('monitoring','Service monitoring');await page.getByText('Production services',{exact:true}).waitFor();check(await page.getByLabel('Delivery reporting health').isVisible(),`${width}: real delivery reporting retained`);await fit('monitoring');
  await page.goto(base+'/guymhan/tools?tab=users',{waitUntil:'networkidle'});await page.getByRole('heading',{level:2,name:'User access',exact:true}).waitFor();
  check(!(await page.locator('.admin-workspace-snapshot').evaluate(el=>el.open)),`${width}: repeated platform snapshot collapsed`);
  check(await page.getByText('Customer fixture',{exact:true}).count()>0,`${width}: existing user management retained`);await fit('user tools');
  await page.locator('.admin-workspace-snapshot>summary').click();check(await page.getByText('Quick Navigation',{exact:true}).isVisible(),`${width}: original shortcuts still available`);await page.locator('.admin-workspace-snapshot>summary').click();
  await section('settings','Configuration','Administration sections');await page.locator('#smtp-settings').waitFor();check(await page.locator('#notification-settings').count()===1,`${width}: email and notification configuration retained`);await fit('configuration');
  await section('scheduled','Scheduled messages','Administration sections');await page.getByText('Scheduled Campaign Processor',{exact:true}).waitFor();await fit('scheduler');
  await section('activity','Activity log','Administration sections');await fit('activity');
  await section('contact-emails','Support inbox','Administration sections');await fit('support');
  await section('health','System health','Administration sections');await fit('health');
  if(width<1024)await page.getByRole('button',{name:'Open operations menu'}).click();
  const nav=width<1024?page.getByRole('dialog').getByRole('navigation',{name:'Admin navigation'}):page.getByRole('navigation',{name:'Admin navigation'});
  check(await nav.locator('a[aria-current=page]').count()===1,`${width}: exactly one active sidebar destination`);
  if(width<1024){await page.keyboard.press('Escape');await page.getByRole('dialog').waitFor({state:'detached'});}
  check(errors.length===0,`${width}: no browser errors: ${errors.join('; ')}`);
  check(calls.length>0,`${width}: existing reads exercised without writes`);receipt.cases.push({width,operations_sections:5,finance_sections:5,tools_sections:6});await context.close();
 }
 receipt.passed=true;
}catch(error){receipt.error=error.stack||String(error);receipt.browser_errors=activeErrors;process.exitCode=1;if(activePage&&!activePage.isClosed()){receipt.url=activePage.url();receipt.text=(await activePage.locator('body').innerText()).slice(0,5000);await activePage.screenshot({path:path.join(out,'admin-failure.png'),fullPage:true})}}
finally{await fs.writeFile(path.join(out,'admin-browser-verification.json'),JSON.stringify(receipt,null,2));await browser.close();await new Promise(r=>server.close(r));}
console.log(JSON.stringify(receipt));
