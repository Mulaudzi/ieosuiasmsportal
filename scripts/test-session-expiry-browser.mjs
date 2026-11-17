import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
const base=process.argv[2]??'http://127.0.0.1:4246';assert.ok(base.startsWith('http://127.0.0.1:'));
const out='.sms-audit.local/session-browser';mkdirSync(out,{recursive:true});let checks=0;
const browser=await chromium.launch({channel:'chrome',headless:true});
try{
 for(const [admin,type,body] of [[false,'text/html','<h1>Expired</h1>'],[false,'application/json','invalid JSON'],[false,'application/json','{"error":"expired"}'],[true,'text/html','Expired']]){
  const context=await browser.newContext({viewport:{width:375,height:812}});
  await context.addInitScript(admin=>{
   if(sessionStorage.getItem('fixture-seeded'))return;sessionStorage.setItem('fixture-seeded','1');
   localStorage.setItem('auth_token','synthetic-only');localStorage.setItem('auth_user',JSON.stringify({id:77,name:'Fixture',email:'fixture@example.invalid',role:admin?'admin':'user',email_verified:true}));localStorage.setItem('auth_token_issued',String(Date.now()));sessionStorage.setItem('admin_session_timestamp',String(Date.now()));localStorage.setItem('sms-campaign-draft:v1:77','keep-draft');
  },admin);
  await context.route('**/api/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname.endsWith('/auth/ieosuia/start')){assert.equal(url.searchParams.get('prompt'),'login');assert.equal(url.searchParams.get('account_type'),admin?'admin':null);checks+=2;return route.fulfill({contentType:'text/html',body:'<h1>Fresh login requested</h1>'});}
   return route.fulfill({status:401,contentType:type,body});
  });
  const page=await context.newPage();await page.goto(base+(admin?'/guymhan?tab=overview':'/dashboard?range=30d'));
  await page.getByRole('heading',{name:'Fresh login requested'}).waitFor();
  const storage=await page.evaluate(()=>({token:localStorage.getItem('auth_token'),user:localStorage.getItem('auth_user'),draft:localStorage.getItem('sms-campaign-draft:v1:77'),returnTo:sessionStorage.getItem('redirectAfterLogin')}));
  assert.equal(storage.token,null);assert.equal(storage.user,null);assert.equal(storage.draft,'keep-draft');checks+=3;
  if(!admin){assert.equal(storage.returnTo,'/dashboard?range=30d');checks++;}
  await page.screenshot({path:`${out}/${admin?'admin':type.replace('/','-')}-${checks}.png`});await context.close();
 }
 writeFileSync(`${out}/results.json`,JSON.stringify({passed:true,checks,real_auth_or_sms_requests:false},null,2));console.log(`PASS ${checks} compiled-browser session checks`);
}finally{await browser.close();}
