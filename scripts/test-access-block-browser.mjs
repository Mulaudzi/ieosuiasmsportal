import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdir,writeFile} from 'node:fs/promises';
const base=process.argv[2]||'http://127.0.0.1:4272';
assert.ok(/^http:\/\/127\.0\.0\.1:\d+$/.test(base),'Local fixtures only.');
const out='.sms-audit.local/access-provider-fix-20261021/browser';await mkdir(out,{recursive:true});
const browser=await chromium.launch({channel:'chrome',headless:true});let checks=0;
try{
 for(const width of [1440,375]){
  const context=await browser.newContext({viewport:{width,height:950}});
  const user={id:'access-fixture',name:'Fixture admin',email:'admin@example.invalid',role:'admin',email_verified:true};
  await context.addInitScript(user=>{localStorage.setItem('auth_user',JSON.stringify(user));localStorage.setItem('auth_token','fixture-only');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');},user);
  const watched=['/api/notifications','/api/admin/operations/credit-approval-security','/api/admin/operations/payments','/api/wallet/purchase-orders'];
  const counts=Object.fromEntries(watched.map(path=>[path,0]));let blocked=true;const errors=[];
  await context.route('**/api/**',async route=>{
   const request=route.request(),path=new URL(request.url()).pathname;assert.equal(request.method(),'GET','This regression must not approve payments or mutate accounts.');let data={success:true};
   if(watched.includes(path)){counts[path]++;if(blocked)return route.fulfill({status:403,json:{message:'Access denied by Imunify360 bot-protection. IPs used for automation should be whitelisted'}});}
   if(path.endsWith('/auth/user'))data={...data,user};
   else if(path.endsWith('/credit-approval-security'))data={...data,available:true,configured:true,is_owner:false,owner_email:'lufuno@ieosuia.com'};
   else if(path.endsWith('/wallet/purchase-orders'))data={...data,orders:[],total:0};
   else if(path.endsWith('/operations/payments'))data={...data,payments:[],pagination:{page:1,last_page:1,total:0}};
   else if(path.endsWith('/overview'))data={...data,users:{},campaigns:{},messages:{},wallets:{},payments:{},recent_customers:[]};
   else if(path.endsWith('/services'))data={...data,services:[]};
   else if(path.endsWith('/provider'))data={...data,available:true,credits:10775};
   else if(path.endsWith('/finance'))data={...data,payments:{},ledger:{},wallets:{}};
   else if(path.endsWith('/customers'))data={...data,customers:[]};
   else if(path.endsWith('/notifications'))data={...data,notifications:[],unread_count:0};
   else data={...data,campaigns:[]};
   return route.fulfill({json:data});
  });
  const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
  await page.clock.install();await page.goto(base+'/guymhan?tab=finance');
  await page.getByRole('region',{name:'Credit approval security'}).getByText(/Hosting security blocked/).waitFor();
  await page.getByRole('region',{name:'Bank transfer verification'}).getByText(/Hosting security blocked/).waitFor();
  await page.getByRole('region',{name:'Credit payment history'}).getByText(/Hosting security blocked/).waitFor();checks+=3;
  await page.clock.fastForward(90000);
  await page.evaluate(()=>{window.dispatchEvent(new Event('focus'));window.dispatchEvent(new Event('online'));document.dispatchEvent(new Event('visibilitychange'));});
  for(const path of watched){assert.equal(counts[path],1,`${path} must not keep retrying or polling after a 403`);checks++;}
  assert.equal(await page.evaluate(()=>localStorage.getItem('auth_token')),'fixture-only');checks++;
  assert.equal(new URL(page.url()).pathname,'/guymhan');checks++;
  await page.getByRole('button',{name:'Notifications, 0 unread'}).click();
  await page.getByRole('alert').filter({hasText:'Hosting security blocked'}).last().waitFor();
  assert.equal(counts['/api/notifications'],1);checks++;
  blocked=false;
  await page.getByRole('button',{name:'Retry',exact:true}).last().click();
  await page.getByText('No notifications yet',{exact:true}).waitFor();checks++;
  await page.keyboard.press('Escape');
  await page.getByRole('region',{name:'Credit approval security'}).getByRole('button',{name:'Retry',exact:true}).click();
  await page.getByText('Master password configured.',{exact:true}).waitFor();checks++;
  await page.getByRole('region',{name:'Bank transfer verification'}).getByRole('button',{name:'Retry',exact:true}).click();
  await page.getByText('No bank transfers match these filters.',{exact:true}).waitFor();checks++;
  await page.getByRole('button',{name:'Refresh payments',exact:true}).click();
  await page.getByText('No payments match these filters.',{exact:true}).waitFor();checks++;
  await page.clock.fastForward(31000);
  // Interval callbacks start fetches asynchronously; let intercepted requests finish.
  await new Promise(resolve=>setTimeout(resolve,300));
  for(const path of watched){assert.ok(counts[path]>=3,`${path} polling must recover after manual retry`);checks++;}
  assert.deepEqual(errors,[]);checks++;
  await page.screenshot({path:`${out}/recovered-${width}.png`,fullPage:true});await context.close();
 }
 await writeFile(`${out}/results.json`,JSON.stringify({passed:true,checks,widths:[1440,375],allApiIntercepted:true,financialWrites:0},null,2));
 console.log(`PASS: ${checks} hosting-block pause/recovery browser checks; no production requests or financial writes.`);
}finally{await browser.close();}
