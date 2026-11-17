import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
const base=process.argv[2]??'http://127.0.0.1:4241';
assert.ok(base.startsWith('http://127.0.0.1:'));
const out=process.argv[3]??'.sms-audit.local/contact-cash-release-20261023/browser';mkdirSync(out,{recursive:true});
const browser=await chromium.launch({channel:'chrome',headless:true});let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
try {
 for(const width of [1440,375,320]) {
  const context=await browser.newContext({viewport:{width,height:1000}});
  const user={id:'fixture',name:'Contact fixture',email:'fixture@example.invalid',role:'user',email_verified:true};
  await context.addInitScript(user=>{localStorage.setItem('auth_user',JSON.stringify(user));localStorage.setItem('auth_token','fixture');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');},user);
  const transfers=[],sends=[],purchases=[],quotes=[];let grouped=false;const errors=[];
  const groups=()=>[{id:'uncategorized',name:'Uncategorized',is_virtual:true,contact_count:grouped?0:2},{id:'1',name:'Church members',contact_count:grouped?77:75},{id:'2',name:'Empty group',contact_count:0}];
  await context.route('**/api/**',async route=>{
   const path=new URL(route.request().url()).pathname;let result={success:true};
   if(path.endsWith('/auth/user'))result={...result,user};
   else if(path.endsWith('/notifications'))result={...result,notifications:[],unread_count:0,has_more:false};
   else if(path.endsWith('/wallet/purchase-options'))result={...result,purchases_available:true,direct_transfer_available:true,proof_email:'sms@ieosuia.com',cash_balance:{enabled:true,balance_cents:300,reserved_cents:0,available_cents:300}};
   else if(path.endsWith('/wallet/purchase-quote')){const q=route.request().postDataJSON();quotes.push(q);const eft=q.payment_method==='eft',total=eft?3400:3500;result={...result,quote:{credits:q.balance_choice==='keep_payment'?110:100,unit_cents:35,subtotal_cents:3500,discount_cents:eft?100:0,total_cents:q.balance_choice==='keep_payment'?total:total-300,sms_total_cents:q.balance_choice==='keep_payment'?total+250:total,cash_applied_cents:300},cash_balance:{enabled:true,balance_cents:300,reserved_cents:0,available_cents:300}};}
   else if(path.endsWith('/wallet/buy')){purchases.push(route.request().postDataJSON());result={...result,order:{id:1,credits:100,reference:'SMS-FIXTURE',total_cents:3100,discount_cents:100,status:'awaiting_payment',payment_method:'eft',proofs:[],bank_details:{bank_name:'Fixture',account_name:'Fixture',account_number:'123',branch_code:'123'},proof_email:'sms@ieosuia.com'}};}
   else if(path.endsWith('/contact-groups'))result={...result,groups:groups(),total_contacts:77};
   else if(path.endsWith('/contacts/transfer-group')){transfers.push(route.request().postDataJSON());grouped=true;result={...result,matched:2,added:2,already_present:0,removed_from_source:0};}
   else if(path.endsWith('/contacts'))result={...result,data:[{id:'1',name:'Ada',phone:'+27820000001',email:'',group_id:grouped?'1':'',group_name:grouped?'Church members':'',subscription_status:'subscribed',created_at:'2026-10-01'}],meta:{total:77}};
   else if(path.endsWith('/settings/sms'))result={...result,opt_out_text:'Reply STOP to unsubscribe'};
   else if(path.endsWith('/sms/preview')){const input=route.request().postDataJSON();result={...result,preview:{sendable_count:77,entered_count:77,invalid_count:0,duplicate_count:0,opted_out_count:0,excluded_count:0,encoding:'gsm7',character_count:input.content.length,segment_count:1,required_credits:77,available_credits:1000,sufficient_balance:true,final_content:input.content,price_per_segment:.35,recipients:['+27820000001']}};}
   else if(path.endsWith('/sms/campaigns-v2')&&route.request().method()==='POST'){sends.push(route.request().postDataJSON());await new Promise(r=>setTimeout(r,100));result={...result,campaign:{id:999}};}
   else if(path.endsWith('/wallet/purchase-orders'))result={...result,orders:[],total:0};
   else if(path.endsWith('/wallet/activity'))result={...result,transactions:[],total:0,page:1,last_page:1,total_paid:0};
   else if(path.endsWith('/wallet/stats'))result={...result,balance:350,sms_credits:1000,used_this_month:0,total_spent:0,price_per_credit:.35};
   else if(path.endsWith('/wallet'))result={...result,wallet:{balance:350,reserved:0,sms_credits:1000,price_per_segment:.35}};
   else if(path.endsWith('/templates'))result={...result,templates:[],data:[]};
   else result={...result,data:[],orders:[],transactions:[],pagination:{total:0},total_paid:0};
   await route.fulfill({json:result});
  });
  const page=await context.newPage();page.on('pageerror',e=>{errors.push(e.message);console.error('FIXTURE RUNTIME',page.url(),e.message);});
  await page.goto(base+'/contacts');await page.getByRole('button',{name:'Add uncategorized contacts to a group',exact:true}).click();
  await page.getByRole('button',{name:/Church members 75 contacts/}).click();await page.getByRole('button',{name:'Add to group',exact:true}).click();
  await page.getByRole('link',{name:'Send SMS to this group'}).waitFor();
  check(transfers.length===1&&transfers[0].scope==='group'&&transfers[0].source_group_id==='uncategorized'&&!transfers[0].ids,'Whole uncategorized operation explicitly includes all pages');
  await page.screenshot({path:`${out}/contacts-${width}.png`,fullPage:true});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Contacts has no horizontal page overflow');
  await page.getByRole('link',{name:'Send SMS to this group'}).click();await page.getByLabel('Name this SMS campaign').fill('Fixture SMS');await page.getByRole('button',{name:'Choose recipients',exact:true}).click();
  const card=page.getByRole('button',{name:/Church members 77 contacts/});await card.waitFor();check(await card.getAttribute('aria-pressed')==='true','Contact group preselected from Send SMS action');
  check(await page.getByRole('button',{name:/Empty group 0 contacts/}).isDisabled(),'Empty groups cannot be chosen as recipients');
  check(await page.getByRole('button',{name:/Uncategorized/}).count()===0,'Virtual uncategorized group is not submitted as a real group ID');
  await page.getByRole('button',{name:'Continue to message',exact:true}).waitFor();await page.getByRole('button',{name:'Continue to message',exact:true}).click();await page.getByLabel('SMS message',{exact:true}).fill('Fixture only.');await page.getByRole('button',{name:'Review SMS',exact:true}).click();await page.getByRole('button',{name:'Continue',exact:true}).click();
  await page.screenshot({path:`${out}/sms-${width}.png`,fullPage:true});
  check(sends.length===0,'No SMS submitted before the final Send SMS action');
  await page.getByRole('button',{name:'Send SMS',exact:true}).click();await page.waitForURL('**/sms-campaigns/999');
  check(sends.length===1&&sends[0].recipients.group_ids[0]==='1','Final Send SMS preserves selected group and submits once');
  await page.goto(base+'/wallet',{waitUntil:'networkidle'});await page.screenshot({path:`${out}/wallet-before-${width}.png`,fullPage:true});await page.getByRole('button',{name:'Buy Credits',exact:true}).click();await page.getByRole('button',{name:'Choose payment method',exact:true}).click();
  const online=page.getByRole('button',{name:'Continue to secure checkout',exact:true});check(await online.isDisabled(),'Customer must choose how to use nonzero cash balance');
  await page.getByLabel(/Keep my 100 SMS credits/).check();await page.getByText('You will receive 100 SMS credits and pay R32.00.').waitFor();
  await page.getByLabel(/Keep my R35.00 payment budget/).check();await page.getByText('You will receive 110 SMS credits and pay R35.00.').waitFor();
  check(purchases.length===0,'Changing cash choices only quotes, never creates a purchase');
  await page.getByLabel(/Keep my 100 SMS credits/).check();await page.getByRole('button',{name:/Pay directly to our FNB account/}).click();await page.getByText('You will receive 100 SMS credits and pay R31.00.').waitFor();
  await page.screenshot({path:`${out}/cash-${width}.png`,fullPage:true});
  await page.getByRole('button',{name:'Create bank transfer order',exact:true}).click();await page.getByText('SMS-FIXTURE',{exact:true}).first().waitFor();
  check(purchases.length===1&&purchases[0].balance_choice==='keep_credits'&&purchases[0].quoted_total_cents===3100&&purchases[0].quoted_cash_applied_cents===300,'Cash choice and server quote are submitted explicitly');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No horizontal page overflow');check(errors.length===0,`No runtime errors: ${errors.join('; ')}`);
  await context.close();
 }
 writeFileSync(out+'/results.json',JSON.stringify({passed:true,checks,fixture_only:true,widths:[1440,375,320]},null,2));console.log(`PASS ${checks} contact/SMS/cash browser checks`);
}finally{await browser.close();}
