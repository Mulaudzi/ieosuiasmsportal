import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
const base=process.argv[2]??'http://127.0.0.1:4249';assert.ok(base.startsWith('http://127.0.0.1:'));
const out='.sms-audit.local/contact-name-fix-20261024/browser';mkdirSync(out,{recursive:true});
let checks=0;const check=(ok,label)=>{assert.ok(ok,label);checks++;};
const browser=await chromium.launch({channel:'chrome',headless:true});
try{
 for(const width of [1440,375,320]){
  const context=await browser.newContext({viewport:{width,height:1000}}),user={id:'name-group-fixture',name:'Fixture',email:'fixture@example.invalid',role:'user',email_verified:true};
  await context.addInitScript(user=>{
   localStorage.setItem('auth_user',JSON.stringify(user));localStorage.setItem('auth_token','fixture-only');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');
   localStorage.setItem(`sms-ui:${user.id}:dashboard-tour-seen`,'true');
   localStorage.setItem(`sms-campaign-draft:v1:${user.id}`,JSON.stringify({version:1,step:3,name:'Saved campaign',manual:'',groupIds:['2'],contactIds:[],excluded:[],selectedTemplate:'none',content:'Saved message',includeOptOut:false,schedule:'',submissionKey:'saved-key'}));
  },user);
  let added=false;const writes=[],errors=[];
  await context.route('**/api/**',async route=>{
   const req=route.request(),path=new URL(req.url()).pathname;let data={success:true};
   if(req.method()!=='GET'){writes.push({path,body:req.postDataJSON()});assert.ok(path.endsWith('/sms/preview')||path.endsWith('/contacts'),'Only preview or explicitly confirmed contact membership writes');}
   if(path.endsWith('/auth/user'))data={...data,user};
   else if(path.endsWith('/settings/sms'))data={...data,opt_out_text:'Reply STOP to unsubscribe'};
   else if(path.endsWith('/notifications'))data={...data,notifications:[],unread_count:0};
   else if(path.endsWith('/contact-groups'))data={...data,groups:[{id:2,name:'test',contact_count:added?5:4},{id:3,name:'Original',contact_count:1}],total_contacts:5};
   else if(path.endsWith('/contacts')&&req.method()==='POST'){assert.equal(req.postDataJSON().group_id,'2');added=true;data={...data,existing_contact_reused:true,already_in_group:false,group_membership_added:true,contact:{id:10,name:'Original name',phone:'+27638082493'}};}
   else if(path.endsWith('/contacts'))data={...data,data:[{id:10,name:'Original name',phone:'+27638082493',email:'',group_name:'Original',group_id:'3',subscription_status:'subscribed'}],meta:{total:1}};
   else if(path.endsWith('/sms/preview'))data={...data,preview:{sendable_count:4,required_credits:4,sufficient_balance:true}};
   else if(path.endsWith('/templates'))data={...data,templates:[]};
   else if(path.endsWith('/wallet'))data={...data,wallet:{balance:100,reserved:0,sms_credits:285,price_per_credit:.35}};
   else data={...data,data:[]};
   await route.fulfill({json:data});
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/sms-campaigns/new?group=2');await page.getByLabel('Name this SMS campaign').waitFor();
  check(await page.getByLabel('Name this SMS campaign').inputValue()==='Saved campaign','Saved name visible first');
  check(await page.getByRole('heading',{name:'Who should receive this SMS?'}).count()===0,'Recipients not opened automatically');
  check(await page.getByRole('button',{name:'Message',exact:true}).isDisabled(),'Message shortcut initially locked');
  await page.screenshot({path:`${out}/campaign-first-${width}.png`,fullPage:true,animations:'disabled'});
  await page.getByRole('button',{name:'Choose recipients',exact:true}).click();await page.getByRole('button',{name:'Continue to message',exact:true}).waitFor();await page.getByRole('button',{name:'Continue to message',exact:true}).click();
  check(await page.getByLabel('SMS message',{exact:true}).inputValue()==='Saved message','Draft contents preserved after naming');
  check((await page.getByRole('switch',{name:'Include opt-out text'}).getAttribute('aria-checked'))==='false','Draft opt-out-text choice preserved');
  await page.goto(base+'/contacts');await page.getByRole('button',{name:'test',exact:true}).click();await page.getByRole('button',{name:'Add Contact',exact:true}).click();
  const modal=page.getByRole('dialog',{name:'Add Contact'});await modal.getByLabel('Phone Number *').fill('0638082493');await modal.getByRole('button',{name:'Add Contact',exact:true}).click();
  await page.getByText('Existing contact added to group',{exact:true}).waitFor();
  check(await page.getByText('0638082493 has been added to test. Saved details and other groups are unchanged.',{exact:true}).isVisible(),'Reuse success is explained');
  check(writes.filter(w=>w.path.endsWith('/contacts')).length===1,'Single confirmed contact request');
  check(!writes.some(w=>w.path.endsWith('/sms/campaigns-v2')),'No SMS sent');
  check(errors.length===0,'No browser runtime errors');check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No horizontal overflow');
  await page.screenshot({path:`${out}/existing-contact-${width}.png`,fullPage:true,animations:'disabled'});await context.close();
 }
 writeFileSync(`${out}/results.json`,JSON.stringify({passed:true,checks,production_access:false,sms_sent:false},null,2));console.log(`PASS ${checks} desktop/mobile restored-name and existing-contact browser checks`);
}finally{await browser.close();}
