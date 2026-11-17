import { readFileSync, writeFileSync, mkdirSync, existsSync, unlinkSync } from 'node:fs';
import { resolve, basename } from 'node:path';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
const folder=resolve(process.env.RAND_CREDIT_SETUP_RELEASE||'.sms-audit.local/rand-credit-setup-20261102-v2'),runner=folder+'/runner.php';
const manifest=JSON.parse(readFileSync(folder+'/manifest.json','utf8'));
const key=JSON.parse(readFileSync(folder+'/private-link.json','utf8')).url.split('#key=')[1];
const client=readFileSync(folder+'/client.js','utf8');let checks=0;
const check=(v,label)=>{assert.ok(v,label);checks++;};
function php(server,expired=false){
 const code=`$_SERVER=json_decode(getenv('SETUP_TEST_SERVER'),true); ${expired?`$s=file_get_contents(${JSON.stringify(runner)});$s=preg_replace('/const RAND_SETUP_EXPIRES=[0-9]+;/','const RAND_SETUP_EXPIRES=0;',$s);eval(substr($s,5));`:`require ${JSON.stringify(runner)};`} echo "\\nSTATUS:".http_response_code();`;
 const result=spawnSync('C:/php8/php.exe',['-r',code],{encoding:'utf8',env:{...process.env,SETUP_TEST_SERVER:JSON.stringify(server)}});assert.equal(result.status,0,result.stderr);return result.stdout;
}
const shell=php({REQUEST_METHOD:'GET',HTTP_ACCEPT:'text/html'}).split('\nSTATUS:')[0];
check(shell.includes('Rand-credit database setup'),'Anonymous access displays shell only');check(!shell.includes(key),'No private token in HTML');
check(!/<script(?![^>]*src=)[^>]*>/i.test(shell),'External script works with strict CSP');
check(php({REQUEST_METHOD:'GET',HTTP_ACCEPT:'application/json'}).includes('NOT_FOUND'),'Missing capability denied before loading database');
check(php({REQUEST_METHOD:'GET',HTTP_ACCEPT:'application/json',HTTP_X_RAND_CREDIT_SETUP_KEY:'wrong'}).includes('NOT_FOUND'),'Wrong capability denied');
check(php({REQUEST_METHOD:'POST',HTTP_X_RAND_CREDIT_SETUP_KEY:key,HTTP_ORIGIN:'https://untrusted.invalid'}).includes('ORIGIN_DENIED'),'Cross-origin apply rejected');
check(php({REQUEST_METHOD:'POST',HTTP_X_RAND_CREDIT_SETUP_KEY:key,HTTP_ORIGIN:'https://sms.ieosuia.com'}).includes('CONFIRMATION_REQUIRED'),'Unconfirmed JSON mutation rejected');
check(php({REQUEST_METHOD:'PUT',HTTP_X_RAND_CREDIT_SETUP_KEY:key}).includes('NOT_FOUND'),'Other methods rejected');
check(php({REQUEST_METHOD:'GET',HTTP_X_RAND_CREDIT_SETUP_KEY:key},true).includes('expired'),'Expired link cannot inspect or apply');
mkdirSync(folder+'/config',{recursive:true});const state=folder+'/config/'+basename(manifest.state);assert.ok(!existsSync(state),'Do not overwrite existing state');
try{
 writeFileSync(state,'<?php exit; ?>\n'+JSON.stringify({phase:'completed',result:{ok:true,code:'CASH_BALANCE_ADJUSTMENTS_READY',migration:'025_cash_balance_adjustments'}}));
 check(php({REQUEST_METHOD:'GET',HTTP_X_RAND_CREDIT_SETUP_KEY:key}).includes('CASH_BALANCE_ADJUSTMENTS_READY'),'Completed receipt loads without database connection');
}finally{unlinkSync(state);}
const browser=await chromium.launch({channel:'chrome',headless:true});
try{
 for(const width of[1440,375,320])for(const needsCash of[false,true]){
  const context=await browser.newContext({viewport:{width,height:850}}),requests=[],errors=[];let completed=false;
  await context.route('**/*',async route=>{
   const req=route.request(),url=new URL(req.url());check(url.origin==='http://127.0.0.1:4334','No external requests');
   if(url.pathname.endsWith('.js'))return route.fulfill({contentType:'text/javascript',body:client});
   if(!req.headers()['x-rand-credit-setup-key'])return route.fulfill({contentType:'text/html',headers:{'Content-Security-Policy':"default-src 'none'; script-src 'self'; style-src 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'"},body:shell});
   check(req.headers()['x-rand-credit-setup-key']===key,'Token header matches capability');requests.push(req.method());
   if(req.method()==='POST'){assert.deepEqual(req.postDataJSON(),{confirm:'025_cash_balance_adjustments',backup_confirmed:true,include_cash_prerequisite:needsCash});completed=true;}
   return route.fulfill({json:completed?{ok:true,code:'CASH_BALANCE_ADJUSTMENTS_READY',migration:'025_cash_balance_adjustments',credits_moved:false}:{ok:true,read_only:true,ready_to_apply:true,cash_prerequisite_permission_required:needsCash,partial_setup_requires_investigation:false}});
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://127.0.0.1:4334/setup.php#key='+key);await page.getByText(/Preflight passed/).waitFor();
  check(!page.url().includes('#key='),'Fragment removed from visible address');check(requests.every(m=>m==='GET'),'Opening is read-only');
  const apply=page.getByRole('button',{name:'Apply rand-credit setup',exact:true});check(await apply.isDisabled(),'Backup confirmation required');
  await page.locator('#backup').check();if(needsCash){check(await apply.isDisabled(),'Missing 017 requires separate explicit permission');await page.locator('#cash').check();}page.once('dialog',d=>d.accept());await apply.click();await page.getByText(/Database setup verified/).waitFor();check(await apply.isDisabled(),'Single apply only');
  await page.reload();await page.getByText(/Database setup verified/).waitFor();check(requests.filter(m=>m==='POST').length===1,'Reload inspects instead of applying');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Responsive page has no horizontal overflow');check(errors.length===0,errors.join('\n'));await context.close();
 }
 // Losing an Apply response must never permit a second attempt, including after inspection.
 const context=await browser.newContext(),page=await context.newPage();let posts=0;
 await context.route('**/*',async route=>{
  const req=route.request();if(new URL(req.url()).pathname.endsWith('.js'))return route.fulfill({contentType:'text/javascript',body:client});
  if(!req.headers()['x-rand-credit-setup-key'])return route.fulfill({contentType:'text/html',body:shell});
  if(req.method()==='POST'){posts++;return route.fulfill({status:503,json:{ok:false,message:'Uncertain result; inspect before continuing.'}});}
  return route.fulfill({json:{ok:true,ready_to_apply:true}});
 });
 await page.goto('http://127.0.0.1:4334/uncertain.php#key='+key);await page.getByText(/Preflight passed/).waitFor();await page.locator('#backup').check();page.once('dialog',d=>d.accept());await page.locator('#apply').click();await page.getByText('Uncertain result; inspect before continuing.',{exact:true}).waitFor();
 check(await page.locator('#apply').isDisabled(),'Uncertain Apply remains blocked');await page.locator('#inspect').click();await page.getByText(/schema needs checking/).waitFor();check(await page.locator('#apply').isDisabled()&&posts===1,'Inspection cannot re-enable uncertain mutation');await context.close();
}finally{await browser.close();}
writeFileSync(folder+'/web-tool-tests.json',JSON.stringify({passed:true,checks,runner_sha256:createHash('sha256').update(readFileSync(runner)).digest('hex'),production_requests:0,database_writes:0},null,2));
console.log('PASS',checks,'temporary-link access and responsive-browser checks; no live database access.');
