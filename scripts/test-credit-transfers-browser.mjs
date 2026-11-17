import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdir,writeFile} from 'node:fs/promises';
const base=process.argv[2]||'http://127.0.0.1:4296';assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);
const channel=process.env.TRANSFER_BROWSER_CHANNEL||'chrome',out=`.sms-audit.local/credit-transfers-20261029/browser-${channel}`;
await mkdir(out,{recursive:true});const browser=await chromium.launch({channel,headless:true});let checks=0;
const check=(condition,label)=>{assert.ok(condition,label);checks++;};
const id='a'.repeat(32),source={id:'2',name:'Source fixture',email:'source@example.invalid'},destination={id:'3',name:'Recipient fixture with a long account name',email:'recipient.with.a.long.email.address@example.invalid'};
try{
 for(const [width,height]of[[1440,900],[768,1024],[375,667],[320,568]])for(const admin of [false,true]){
  const context=await browser.newContext({viewport:{width,height}}),writes=[],errors=[];
  const user={...source,role:admin?'admin':'user',email_verified:true,email_verified_at:'2026-01-01'};
  await context.addInitScript(u=>{localStorage.setItem('auth_user',JSON.stringify(u));localStorage.setItem('auth_token','synthetic-transfer-only');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');},user);
  await context.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
  const quote={id,source,destination:{...destination,id:admin?'3':'recipient'},credits:5,amount:'1.7500',unit_amount:'0.3500',reason:'Synthetic authorized credit allocation',source_available_credits:80,source_available_after:75,...(admin?{destination_available_after:15}:{}),expires_at:Math.floor(Date.now()/1000)+600};
  let completed=false;
  const receipt={id,reference:'TRF-'+id.toUpperCase(),credits:5,status:'completed',source_name:source.name,source_email:source.email,destination_name:destination.name,destination_email:destination.email,created_at:'2026-10-29 12:00:00',reason:quote.reason};
  await context.route('**/api/**',async route=>{
   const request=route.request(),url=new URL(request.url()),path=url.pathname,method=request.method();let data={success:true};
   if(method!=='GET')writes.push({path,body:request.postDataJSON()});
   if(path.endsWith('/auth/user'))data={...data,user};
   else if(path.endsWith('/credit-transfers/accounts'))data={...data,accounts:[source,destination].map(a=>({...a,available_credits:80,eligible:true}))};
   else if(path.endsWith('/credit-transfers/quote'))data={...data,quote};
   else if(path.endsWith('/credit-transfers/challenge'))data={...data,code_requested:true};
   else if(path.endsWith('/credit-transfers/commit')){completed=true;data={...data,transfer:receipt};}
   else if(path.endsWith('/credit-transfers'))data={...data,enabled:true,transfers:completed?(admin?[receipt]:[{...receipt,direction:'sent'}]):[],page:1,last_page:1,total:completed?1:0,security:{configured:true}};
   else if(path.endsWith('/wallet'))data={...data,wallet:{balance:35,reserved:7,sms_credits:80,available:28,price_per_credit:.35}};
   else if(path.endsWith('/wallet/stats'))data={...data,sms_credits:completed?75:80,used_this_month:0,balance:28,price_per_credit:.35};
   else if(path.endsWith('/wallet/activity'))data={...data,transactions:[],total:0,page:1,last_page:1,total_paid:0};
   else if(path.endsWith('/purchase-options'))data={...data,purchases_available:true,cash_balance:{enabled:false}};
   else if(path.endsWith('/purchase-orders'))data={...data,orders:[],total:0};
   else if(path.endsWith('/drafts'))data={...data,drafts:[]};
   else if(path.endsWith('/notifications'))data={...data,notifications:[],unread_count:0};
   else if(path.endsWith('/overview'))data={...data,users:{},campaigns:{},messages:{},wallets:{},payments:{},recent_customers:[]};
   else if(path.endsWith('/services'))data={...data,services:[]};
   else if(path.endsWith('/provider'))data={...data,available:true,credits:1000};
   else if(path.endsWith('/finance'))data={...data,payments:{},wallets:{},ledger:{}};
   else if(path.endsWith('/customers'))data={...data,customers:[],pagination:{page:1,total:0,last_page:1}};
   else if(path.endsWith('/campaigns'))data={...data,campaigns:[],pagination:{page:1,total:0,last_page:1}};
   else if(path.endsWith('/credit-approval-security'))data={...data,available:true,configured:true,is_owner:false};
   else if(path.endsWith('/operations/payments'))data={...data,payments:[],pagination:{page:1,last_page:1,total:0}};
   else if(method!=='GET')throw Error('Unexpected write: '+path);
   await route.fulfill({json:data});
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+(admin?'/guymhan?tab=finance':'/wallet'));
  await page.getByRole('button',{name:'Transfer credits',exact:true}).click();
  const dialog=page.getByRole('dialog');await dialog.waitFor();check(writes.length===0,'Opening never posts');
  if(admin){
   for(const [label,account]of[['Source account',source],['Destination account',destination]]){
    await dialog.getByLabel('Search '+label.toLowerCase()).fill(account.email);
    await dialog.getByRole('button').filter({hasText:account.email}).click();
   }
   await dialog.getByLabel('Authorization reason').fill(quote.reason);await dialog.getByRole('checkbox').check();
  }else await dialog.getByLabel('Recipient account email').fill(destination.email);
  await dialog.getByLabel('Credits to transfer').fill('5');await dialog.getByRole('button',{name:'Review transfer',exact:true}).click();
  await dialog.getByRole('heading',{name:'Review credit transfer'}).waitFor();check(writes.length===1,'Review only requests quote');
  const button=dialog.getByRole('button',{name:'Confirm transfer',exact:true});check(await button.isDisabled(),'Confirmation protected');
  const initialBox=await button.boundingBox();check(initialBox.y>=0&&initialBox.y+initialBox.height<=height,'Footer visible without body scrolling');
  check(await dialog.evaluate(e=>e.scrollWidth<=e.clientWidth+1),'Dialog does not overflow horizontally');
  await dialog.screenshot({path:`${out}/${admin?'admin':'customer'}-${width}-review.png`});
  const body=dialog.locator('.overflow-y-auto');await body.evaluate(e=>e.scrollTop=e.scrollHeight);
  await dialog.getByRole('checkbox').check();
  if(admin)await dialog.getByLabel('Finance master password').fill('synthetic-fixture-only');
  else{await dialog.getByRole('button',{name:'Email me a verification code'}).click();await dialog.getByLabel('Verification code').fill('123456');}
  const footerBox=await button.boundingBox();check(Math.abs(initialBox.y-footerBox.y)<2,'Footer remains stationary while content scrolls');
  await button.click();await dialog.getByRole('heading',{name:'Transfer completed'}).waitFor();
  check(writes.filter(w=>w.path.endsWith('/commit')).length===1,'Exactly one confirmed transfer post');check(completed,'Mock transfer completed');
  check(await page.evaluate(()=>!Object.keys(sessionStorage).some(k=>k.startsWith('sms-credit-transfer:'))&&!Object.values(sessionStorage).some(v=>v==='123456'||v==='synthetic-fixture-only')),'No code/password/pending transfer left in session storage');
  check(errors.length===0,JSON.stringify(errors));await context.close();
 }
 await writeFile(`${out}/checks.json`,JSON.stringify({passed:true,checks,channel,widths:[1440,768,375,320],api:'synthetic intercepted only',productionWrites:false,emailsSent:false},null,2));
 console.log(`PASS ${checks} ${channel} credit-transfer browser checks, admin/customer at four viewport sizes. All APIs mocked.`);
}finally{await browser.close();}
