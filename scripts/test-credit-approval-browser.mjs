import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdir,writeFile} from 'node:fs/promises';
const base=process.argv[2]||'http://127.0.0.1:4250',out=process.env.APPROVAL_CHECK_OUTPUT||'.sms-audit.local/credit-approval-validation-20261019';await mkdir(out,{recursive:true});
const liveAssets=base.startsWith('https://sms.ieosuia.com');
const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',...(liveAssets?{args:['--disable-blink-features=AutomationControlled']}:{})});let checks=0;
try{
 for(const width of [1440,375,320])for(const owner of [true,false]){
  const context=await browser.newContext({viewport:{width,height:950},...(liveAssets?{storageState:'.sms-audit.local/credit-purchases-release-20261019/release-browser-state.json',userAgent:'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'}:{})});
  const user={id:'approval-fixture',name:'Fixture admin',email:owner?'lufuno@ieosuia.com':'admin@example.invalid',role:'admin',email_verified:true};
  await context.addInitScript(user=>{localStorage.setItem('auth_user',JSON.stringify(user));localStorage.setItem('auth_token','fixture-only');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');},user);
  let configured=!owner,completed=false;const writes=[];const errors=[];
  const order={id:1,reference:'SMS-APPROVAL-FIXTURE',credits:1000,unit_cents:30,total_cents:29700,discount_cents:300,status:'awaiting_verification',customer_name:'Fixture customer',customer_email:'customer@example.invalid',proofs:[{id:1,mime_type:'application/pdf'}]};
  await context.route('**/api/**',async route=>{
   const request=route.request(),url=new URL(request.url()),path=url.pathname;let data={success:true},status=200;
   if(path.endsWith('/auth/user'))data={...data,user};
   else if(path.endsWith('/credit-approval-security')){
    if(request.method()==='PUT'){assert.equal(owner,true);writes.push({kind:'configure',body:request.postDataJSON()});configured=true;}
    data={...data,available:true,configured,is_owner:owner,owner_email:'lufuno@ieosuia.com',updated_at:null};
   }else if(path.endsWith('/wallet/purchase-orders')){const filter=url.searchParams.get('status');data={...data,orders:completed&&filter==='pending'?[]:[{...order,status:completed?'completed':order.status,...(completed?{reviewed_at:'2026-10-19',reviewer_name:'Fixture admin',bank_reference:'BANK-FIXTURE-1'}:{})}],total:1};}
   else if(path.endsWith('/review')){const body=request.postDataJSON();writes.push({kind:'review',body});if(body.master_password!=='fixture-master-approval-123'){status=422;data={success:false,message:'The master password is incorrect. No credits have been added.'};}else completed=true;}
   else if(path.endsWith('/operations/payments'))data={...data,payments:[{id:1,customer_name:'Fixture customer',customer_email:'customer@example.invalid',merchant_reference:order.reference,gateway:'eft',status:completed?'completed':'pending',amount:297,currency:'ZAR',credits_added:completed?1000:0,credits_ordered:1000,order_id:1,created_at:'2026-10-19'}],pagination:{page:1,last_page:1,total:1}};
   else if(path.endsWith('/overview'))data={...data,users:{},campaigns:{},messages:{},wallets:{},payments:{},recent_customers:[]};
   else if(path.endsWith('/services'))data={...data,services:[]};
   else if(path.endsWith('/provider'))data={...data,available:true,credits:10000};
   else if(path.endsWith('/finance'))data={...data,payments:{},ledger:{},wallets:{},recent_payments:[]};
   else if(path.endsWith('/customers'))data={...data,customers:[],pagination:{page:1,total:0,last_page:1}};
   else if(path.endsWith('/campaigns'))data={...data,campaigns:[],pagination:{page:1,total:0,last_page:1}};
   else if(path.endsWith('/notifications'))data={...data,data:[],unread_count:0};
   await route.fulfill({status,json:data});
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/guymhan');await page.getByRole('tab',{name:'Finance',exact:true}).click();
  const security=page.getByRole('region',{name:'Credit approval security'});await security.getByRole('heading',{name:'Credit approval security'}).waitFor();checks++;
  if(owner){
   await security.getByRole('button',{name:'Set master password',exact:true}).click();
   const save=security.getByRole('button',{name:'Save master password'});assert.equal(await save.isDisabled(),true);checks++;
   await security.getByLabel('New master password',{exact:true}).fill('fixture-master-approval-123');await security.getByLabel('Confirm new master password').fill('fixture-master-approval-123');await save.click();
   await security.getByRole('button',{name:'Change master password',exact:true}).waitFor();assert.equal(writes.filter(w=>w.kind==='configure').length,1);checks++;
  }else{assert.equal(await security.getByRole('button',{name:'Change master password',exact:true}).count(),0);checks++;}
  const history=page.getByRole('region',{name:'Credit payment history'});await history.getByRole('button',{name:'View transfer'}).click();
  const review=page.getByRole('region',{name:'Bank transfer verification'});
  // The transfer selection is applied by a React effect after the click completes.
  await page.waitForFunction(reference=>document.querySelector('input[aria-label="Search bank transfers"]')?.value===reference,order.reference);
  assert.equal(await review.getByRole('textbox',{name:'Search bank transfers'}).inputValue(),order.reference);checks++;
  await review.getByRole('button',{name:'Review payment',exact:true}).click();
  const approve=review.getByRole('button',{name:'Approve and add 1,000 credits'});assert.equal(await approve.isDisabled(),true);checks++;
  await review.getByLabel('Amount cleared in FNB (ZAR)').fill('297.00');await review.getByLabel('Unique bank transaction ID').fill('BANK-FIXTURE-1');await review.getByLabel('Review note / reason for rejection').fill('Verified cleared funds in fixture');await review.getByRole('checkbox').check();
  assert.equal(await approve.isDisabled(),true);checks++;
  const password=review.getByLabel('Master password',{exact:true});assert.equal(await password.getAttribute('type'),'password');checks++;
  await password.fill('wrong-fixture-password');await approve.click();await page.getByText('The master password is incorrect. No credits have been added.',{exact:true}).waitFor();
  await password.waitFor();assert.equal(await password.inputValue(),'');assert.equal(completed,false);checks+=2;
  await password.fill('fixture-master-approval-123');await approve.click();await review.getByText(/Reviewed by Fixture admin/).waitFor();
  assert.equal(completed,true);assert.equal(writes.filter(w=>w.kind==='review').length,2);checks+=2;
  assert.equal(await review.getByRole('button',{name:'Review payment',exact:true}).count(),0);checks++;
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);checks++;
  assert.deepEqual(errors,[]);checks++;
  await security.screenshot({path:`${out}/security-${owner?'owner':'admin'}-${width}.png`});
  await context.close();
 }
 await writeFile(`${out}/browser-checks.json`,JSON.stringify({passed:true,checks,widths:[1440,375,320],ownerAndAdmin:true,assets:liveAssets?'actual deployed HTTPS assets':'local production build',api:'synthetic intercepted fixtures',productionWrites:false},null,2));
 console.log(`PASS ${checks} owner/admin payment-approval browser checks at desktop, 375px and 320px; all API responses mocked.`);
}finally{await browser.close();}
