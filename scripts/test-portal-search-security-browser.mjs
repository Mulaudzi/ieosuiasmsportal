import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
const base=process.argv[2]||'http://127.0.0.1:4311',channel=process.argv[3]||'chrome';
assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);assert.ok(['chrome','msedge'].includes(channel));
const out=`.sms-audit.local/portal-experience-20261030/experience-${channel}`;mkdirSync(out,{recursive:true});
const browser=await chromium.launch({channel,headless:true});let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;};
try{
 for(const[width,height]of[[1440,900],[768,1024],[375,667],[320,568]]){
  const context=await browser.newContext({viewport:{width,height}}),errors=[],writes=[];
  const user={id:'experience-fixture',name:'Experience Fixture',email:'fixture@example.invalid',email_verified:true,role:'user',phone:''};
  await context.addInitScript(u=>{localStorage.setItem('auth_user',JSON.stringify(u));localStorage.setItem('auth_token','fixture-only');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');},user);
  await context.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
  await context.route('**/api/**',async route=>{
   const req=route.request(),url=new URL(req.url()),path=url.pathname;let data={success:true};
   if(req.method()!=='GET'){
    writes.push(path);
    if(path.endsWith('/settings/login-security/request'))data={success:true,challenge:'a'.repeat(64)};
    else return route.fulfill({status:422,json:{success:false,message:'Synthetic fixture rejects verification'}});
   }else if(path.endsWith('/auth/user'))data={success:true,user};
   else if(path.endsWith('/wallet'))data={success:true,wallet:{sms_credits:100,balance:35,reserved:0}};
   else if(path.endsWith('/portal/overview'))data={success:true,contacts:1,bought_credits:true,sent_campaign:true,checked_delivery:true};
   else if(path.endsWith('/settings/profile'))data={success:true,user,account:{company_name:'Fixture'}};
   else if(path.endsWith('/settings/login-security'))data={success:true,available:true,enrollment_enabled:true,enabled:false,recovery_codes_remaining:0};
   else if(path.endsWith('/notifications'))data={success:true,unread_count:0,notifications:[{id:'1',type:'account_security',title:'Security setting updated',message:'Review the SMS Portal Security settings.',read:true,created_at:'2026-10-30'}]};
   else if(path.endsWith('/search'))data={success:true,results:url.searchParams.get('q')?.startsWith('Sunday')?[{type:'template',id:'7',label:'Sunday reminder',search:'Sunday reminder'}]:[]};
   else if(path.endsWith('/templates'))data={success:true,templates:[{id:7,name:'Sunday reminder',type:'sms',content:'Synthetic template'}]};
   await route.fulfill({json:data});
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));await page.goto(`${base}/settings?tab=security`);
  await page.getByRole('heading',{name:'Optional extra sign-in check'}).waitFor();
  check(await page.getByText(/not your other IEOSUIA apps/).isVisible(),'Security notification destination opens the Security tab');
  check(writes.length===0,'Viewing settings sends no email or code');
  await page.getByRole('button',{name:'Search portal',exact:true}).click();const search=page.getByRole('combobox');await search.fill('Sunday');
  await page.getByRole('option').filter({hasText:'Sunday reminder'}).waitFor();
  check(await page.getByRole('dialog').isVisible(),`Search suggestions visible at ${width}px`);
  const dialog=await page.getByRole('dialog').boundingBox();check(dialog.x>=0&&dialog.x+dialog.width<=width+1,'Search dialog fits viewport');
  await page.screenshot({path:`${out}/${width}-search.png`,fullPage:true});
  await search.press('ArrowDown');await search.press('Enter');await page.waitForURL('**/templates?search=Sunday%20reminder');
  check(await page.getByPlaceholder('Search templates...').inputValue()==='Sunday reminder','Search selection applies actual template filter');
  await page.goto(`${base}/settings`);await page.getByRole('tab',{name:'Profile',exact:true}).waitFor();
  await page.getByRole('button',{name:'Notifications, 0 unread'}).click();await page.getByText('Security setting updated',{exact:true}).click();await page.waitForURL('**/settings?tab=security');
  check(await page.getByRole('heading',{name:'Optional extra sign-in check'}).isVisible(),'Security alert navigates correctly');
  await page.getByRole('button',{name:'Enable extra sign-in check',exact:true}).click();const code=page.getByRole('textbox',{name:'Security verification code'});await code.waitFor();
  check(writes.length===1&&writes[0].endsWith('/settings/login-security/request'),'Only an explicit code request invokes transactional endpoint');
  check(await code.getAttribute('autocomplete')==='one-time-code','Mobile one-time-code autofill');
  check(await page.getByRole('button',{name:'Confirm enable'}).isDisabled(),'No setting change until a complete code is entered');
  await code.fill('123456');await page.getByRole('button',{name:'Confirm enable'}).click();await page.getByRole('alert').filter({hasText:'Synthetic fixture rejects verification'}).waitFor();
  check(await page.evaluate(()=>localStorage.getItem('auth_token'))==='fixture-only','Rejected verification cannot replace sign-in token');
  await page.screenshot({path:`${out}/${width}-security.png`,fullPage:true});
  await page.goto(`${base}/auth/two-step#challenge=${'b'.repeat(64)}`);const login=page.getByRole('textbox',{name:'Sign-in verification code'});await login.waitFor();
  check(new URL(page.url()).hash==='','Login capability fragment removed');await login.fill('123456');await page.getByRole('button',{name:'Verify and sign in'}).click();await page.getByRole('alert').filter({hasText:'Synthetic fixture rejects verification'}).waitFor();
  check(await login.inputValue()==='','Rejected login clears the submitted code');
  check(writes.every(path=>path.endsWith('/settings/login-security/request')||path.endsWith('/settings/login-security/confirm')||path.endsWith('/auth/two-step/verify')),'No finance, SMS or campaign write even attempted');
  check(errors.length===0,`No runtime errors: ${errors.join(',')}`);await context.close();
 }
 writeFileSync(`${out}/result.json`,JSON.stringify({passed:true,checks,channel,synthetic_api:true,external_traffic_blocked:true},null,2));console.log(JSON.stringify({passed:true,checks,channel,out}));
}finally{await browser.close();}
