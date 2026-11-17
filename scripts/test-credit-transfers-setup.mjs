import assert from 'node:assert/strict';
import {readFileSync,writeFileSync,mkdirSync,existsSync,unlinkSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {resolve,basename} from 'node:path';
import {chromium} from 'playwright';
const folder=resolve(process.env.CREDIT_TRANSFER_SETUP_RELEASE||'.sms-audit.local/credit-transfers-setup-20261030');
const manifest=JSON.parse(readFileSync(folder+'/manifest.json','utf8'));
const key=JSON.parse(readFileSync(folder+'/private-link.json','utf8')).url.split('#key=')[1];
const runner=folder+'/runner.php',client=readFileSync(folder+'/client.js','utf8');let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
function php(server,expired=false){
 const code=`$_SERVER=json_decode(getenv('SETUP_TEST_SERVER'),true); ${expired?`$s=file_get_contents(${JSON.stringify(runner)});$s=preg_replace('/const TRANSFER_SETUP_EXPIRES = [0-9]+;/','const TRANSFER_SETUP_EXPIRES = 0;',$s);eval(substr($s,5));`:`require ${JSON.stringify(runner)};`} echo "\\nSTATUS:".http_response_code();`;
 const r=spawnSync('php',['-r',code],{encoding:'utf8',env:{...process.env,SETUP_TEST_SERVER:JSON.stringify(server)}});assert.equal(r.status,0,'PHP gate exited unexpectedly');return r.stdout;
}
const shell=php({REQUEST_METHOD:'GET',HTTP_ACCEPT:'text/html'}).split('\nSTATUS:')[0];
check(shell.includes('Credit transfers database setup'),'Anonymous request displays shell only');
check(!shell.includes(key),'Private capability never embedded in HTML');
check(!/<script(?![^>]*src=)[^>]*>/i.test(shell),'No inline JavaScript conflicts with CSP');
check(php({REQUEST_METHOD:'GET',HTTP_ACCEPT:'application/json'}).includes('NOT_FOUND'),'Missing key JSON denied');
check(php({REQUEST_METHOD:'POST',HTTP_X_CREDIT_TRANSFER_SETUP_KEY:'wrong',HTTP_ORIGIN:'https://sms.ieosuia.com'}).includes('NOT_FOUND'),'Wrong key denied before configuration');
check(php({REQUEST_METHOD:'POST',HTTP_X_CREDIT_TRANSFER_SETUP_KEY:key,HTTP_ORIGIN:'https://untrusted.invalid'}).includes('ORIGIN_DENIED'),'Wrong origin denied before configuration');
check(php({REQUEST_METHOD:'POST',HTTP_X_CREDIT_TRANSFER_SETUP_KEY:key,HTTP_ORIGIN:'https://sms.ieosuia.com'}).includes('CONFIRMATION_REQUIRED'),'Missing JSON confirmation denied before configuration');
check(php({REQUEST_METHOD:'PUT',HTTP_X_CREDIT_TRANSFER_SETUP_KEY:key}).includes('NOT_FOUND'),'Unsupported method denied');
check(php({REQUEST_METHOD:'GET',HTTP_X_CREDIT_TRANSFER_SETUP_KEY:key},true).includes('expired'),'Expired link cannot inspect or apply');
mkdirSync(folder+'/config',{recursive:true});const state=folder+'/config/'+basename(manifest.state);
if(existsSync(state))throw Error('Existing test state must be inspected, not overwritten');
try{
 writeFileSync(state,'<?php exit; ?>\n'+JSON.stringify({phase:'completed',result:{ok:true,code:'CREDIT_TRANSFERS_READY',migration:'021_credit_transfers',credits_moved:false}}));
 check(php({REQUEST_METHOD:'GET',HTTP_X_CREDIT_TRANSFER_SETUP_KEY:key}).includes('CREDIT_TRANSFERS_READY'),'Completed state returns saved receipt without opening a database');
}finally{unlinkSync(state);}
const browser=await chromium.launch({channel:'chrome',headless:true});
try{
 for(const width of [1440,375,320])for(const needsSecurity of [false,true]){
  const context=await browser.newContext({viewport:{width,height:800}}),requests=[],errors=[];let completed=false;
  await context.route('**/*',async route=>{
   const req=route.request(),url=new URL(req.url());check(url.origin==='http://127.0.0.1:4297','No external requests');
   if(url.pathname.endsWith('.js')){await route.fulfill({contentType:'text/javascript',body:client});return;}
   if(!req.headers()['x-credit-transfer-setup-key']){await route.fulfill({contentType:'text/html',headers:{'Content-Security-Policy':"default-src 'none'; script-src 'self'; style-src 'unsafe-inline'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'"},body:shell});return;}
   check(req.headers()['x-credit-transfer-setup-key']===key,'Authenticated request header matches capability');requests.push(req.method());
   if(req.method()==='POST'){assert.deepEqual(req.postDataJSON(),{confirm:'021_credit_transfers',backup_confirmed:true,include_security_prerequisite:needsSecurity});completed=true;}
   await route.fulfill({json:completed?{ok:true,code:'CREDIT_TRANSFERS_READY',migration:'021_credit_transfers',credits_moved:false,email_migration_run:false}:{ok:true,read_only:true,migration:'021_credit_transfers',ready_to_apply:true,security_prerequisite_permission_required:needsSecurity,partial_setup_requires_explicit_resume:false,problems:[]}});
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://127.0.0.1:4297/setup.php#key='+key);await page.getByText(/Preflight passed/).waitFor();
  check(requests.every(m=>m==='GET'),'Opening private link never applies');check(!page.url().includes('#key='),'Capability removed from address bar');
  const apply=page.getByRole('button',{name:'Apply credit-transfer setup',exact:true});check(await apply.isDisabled(),'Verified backup confirmation required');
  await page.locator('#backup').check();
  if(needsSecurity){check(await apply.isDisabled(),'Missing prerequisite requires separate permission');await page.locator('#security').check();}
  check(await apply.isEnabled(),'Apply available only after checks and explicit consent');
  page.once('dialog',d=>d.dismiss());await apply.click();check(!requests.includes('POST'),'Cancelling native confirmation never applies');
  page.once('dialog',d=>d.accept());await apply.click();await page.getByText(/Database setup verified/).waitFor();
  check(requests.filter(m=>m==='POST').length===1,'One confirmed apply request');check(await apply.isDisabled(),'Success cannot repeat Apply');
  await page.reload();await page.getByText(/Database setup verified/).waitFor();check(requests.filter(m=>m==='POST').length===1,'Reload inspects only');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Page fits desktop or small phone');check(!errors.length,'No JavaScript errors');
  await page.screenshot({path:`${folder}/setup-${width}-${needsSecurity?'with-prerequisite':'021-only'}.png`});await context.close();
 }
 // A failed/uncertain write cannot be clicked again in the same view.
 const context=await browser.newContext();let posts=0;
 await context.route('**/*',async route=>{
  const req=route.request();if(req.url().endsWith('.js'))return route.fulfill({contentType:'text/javascript',body:client});
  if(!req.headers()['x-credit-transfer-setup-key'])return route.fulfill({contentType:'text/html',body:shell});
  if(req.method()==='POST'){posts++;return route.fulfill({status:503,json:{ok:false,message:'Uncertain result; inspect before continuing.'}});}
  return route.fulfill({json:{ok:true,ready_to_apply:true}});
 });
 const page=await context.newPage();await page.goto('http://127.0.0.1:4297/setup.php#key='+key);await page.getByText(/Preflight passed/).waitFor();await page.locator('#backup').check();page.once('dialog',d=>d.accept());await page.locator('#apply').click();await page.getByText('Uncertain result; inspect before continuing.',{exact:true}).waitFor();check(await page.locator('#apply').isDisabled(),'Uncertain write remains held');await page.locator('#inspect').click();await page.getByText(/schema needs checking/).waitFor();check(await page.locator('#apply').isDisabled()&&posts===1,'Inspect cannot automatically repeat uncertain write');await context.close();
 writeFileSync(folder+'/web-tool-tests.json',JSON.stringify({passed:true,checks,scope:'Local PHP access gates and mocked browser confirmation; no production requests or database writes'},null,2));console.log(`PASS ${checks} private browser migration-runner checks. No live database changes.`);
}finally{await browser.close();}
