import assert from 'node:assert/strict';
import {readFileSync,writeFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {resolve} from 'node:path';
import {chromium} from 'playwright';
const folder=resolve(process.env.CONTACT_TAGS_SETUP_RELEASE||'.sms-audit.local/contact-tags-setup-20261029-v2');
const manifest=JSON.parse(readFileSync(folder+'/manifest.json','utf8'));
const privateLink=JSON.parse(readFileSync(folder+'/private-link.json','utf8')).url;
const key=privateLink.split('#key=')[1];
const runner=folder+'/runner.php';let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;};
function php(server,expired=false){
  const code=`$_SERVER=json_decode(getenv('SETUP_TEST_SERVER'),true); ${expired?`$source=file_get_contents(${JSON.stringify(runner)});$source=preg_replace('/const TAG_SETUP_EXPIRES = [0-9]+;/','const TAG_SETUP_EXPIRES = 0;',$source);eval(substr($source,5));`:`require ${JSON.stringify(runner)};`} echo "\\nSTATUS:".http_response_code();`;
  const r=spawnSync('php',['-r',code],{encoding:'utf8',env:{...process.env,SETUP_TEST_SERVER:JSON.stringify(server)}});
  assert.equal(r.status,0,r.stderr);return r.stdout;
}
const html=php({REQUEST_METHOD:'GET',HTTP_ACCEPT:'text/html'}).split('\nSTATUS:')[0];
check(html.includes('Contact tags database setup'),'Anonymous GET shows only the confirmation shell');
check(!html.includes(key),'HTML never embeds the capability key');
check(!/<script(?![^>]*src=)[^>]*>/i.test(html),'No inline script violates the portal CSP');
check(php({REQUEST_METHOD:'GET',HTTP_ACCEPT:'application/json'}).includes('NOT_FOUND'),'Unauthenticated JSON is denied before loading configuration');
check(php({REQUEST_METHOD:'POST',HTTP_X_CONTACT_TAGS_SETUP_KEY:'wrong',HTTP_ORIGIN:'https://sms.ieosuia.com'}).includes('NOT_FOUND'),'Incorrect key cannot apply');
check(php({REQUEST_METHOD:'POST',HTTP_X_CONTACT_TAGS_SETUP_KEY:key,HTTP_ORIGIN:'https://untrusted.invalid'}).includes('ORIGIN_DENIED'),'Cross-origin apply is rejected before DB configuration');
check(php({REQUEST_METHOD:'GET',HTTP_X_CONTACT_TAGS_SETUP_KEY:key},true).includes('expired'),'Expired capability cannot inspect or apply');
const client=readFileSync('scripts/contact-tags-setup/client.js','utf8');
const browser=await chromium.launch({channel:'chrome',headless:true});
try{
  for(const width of [1440,375]){
    const context=await browser.newContext({viewport:{width,height:800}}),requests=[];let complete=false;
    await context.route('**/*',async route=>{
      const req=route.request(),url=new URL(req.url());
      assert.equal(url.origin,'http://127.0.0.1:4297','No external requests');
      if(url.pathname.endsWith('.js')){await route.fulfill({contentType:'text/javascript',body:client});return;}
      if(!req.headers()['x-contact-tags-setup-key']){await route.fulfill({contentType:'text/html',headers:{'Content-Security-Policy':"default-src 'none'; script-src 'self'; style-src 'unsafe-inline'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'"},body:html});return;}
      assert.equal(req.headers()['x-contact-tags-setup-key'],key);requests.push(req.method());
      if(req.method()==='POST'){assert.deepEqual(req.postDataJSON(),{confirm:'016_work_drafts+020_contact_tags'});complete=true;}
      await route.fulfill({json:complete?{ok:true,code:'CONTACT_TAGS_READY',migration:'020_contact_tags',schema_only:true,email_migration_run:false}:{ok:true,read_only:true,migration:'020_contact_tags',ready_to_apply:true,problems:[]}});
    });
    const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.goto('http://127.0.0.1:4297/setup.php#key='+key);
    await page.getByText('Preflight passed. You can now click Apply contact-tags setup.').waitFor();
    check(requests.every(m=>m==='GET'),'Opening the private link does not apply a migration');
    check(!page.url().includes('#key='),'Capability removed from the visible address after reading');
    const apply=page.getByRole('button',{name:'Apply contact-tags setup',exact:true});check(await apply.isEnabled(),'Apply enabled only after successful inspect');
    page.once('dialog',d=>d.dismiss());await apply.click();check(!requests.includes('POST'),'Cancelling confirmation does not apply');
    page.once('dialog',d=>d.accept());await apply.click();await page.getByText(/Database setup verified/).waitFor();
    check(requests.filter(m=>m==='POST').length===1,'Exactly one explicit apply request');check(await apply.isDisabled(),'Apply disabled after successful setup');
    await page.reload();await page.getByText(/Database setup verified/).waitFor();check(requests.filter(m=>m==='POST').length===1,'Reload uses stored session key and inspects, never reapplies');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Setup page fits the viewport');check(!errors.length,'No browser JavaScript errors');
    await context.close();
  }
  writeFileSync(folder+'/web-tool-tests.json',JSON.stringify({passed:true,checks,scope:'Local PHP access checks and mocked browser confirmation flow; no production access'},null,2));
  console.log(JSON.stringify({passed:true,checks}));
}finally{await browser.close();}
