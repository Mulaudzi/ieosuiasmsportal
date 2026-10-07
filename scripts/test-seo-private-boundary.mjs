import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {readdir} from 'node:fs/promises';
const base=process.argv[2]||'http://127.0.0.1:4186';
const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
try{
 const context=await browser.newContext();
 await context.addInitScript(()=>{
  localStorage.setItem('ieosuia_cookie_consent','{}');
  localStorage.setItem('auth_token','ui-only-fixture');
  localStorage.setItem('auth_token_issued',Date.now().toString());
  localStorage.setItem('auth_user',JSON.stringify({id:'fixture',name:'UI test',role:'user',email_verified:true}));
 });
 // This test never sends credentials, customer data or API mutations to a server.
 await context.route('**/api/**',route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,data:[],notifications:[],unread_count:0})}));
 const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'/privacy-policy');await page.getByRole('link',{name:'Back to dashboard'}).waitFor();
 assert.equal(await page.locator('.public-site-header,.public-whatsapp').count(),0);
 console.log('PASS signed-in public page preserves account navigation and hides public controls');
 const files=(await readdir('.footer-release.local/source/dist/assets')).filter(name=>name.endsWith('.js'));
 for(const file of files){await page.evaluate(url=>import(url).then(()=>true),base+'/assets/'+file);}
 assert.deepEqual(errors,[]);
 console.log('PASS all '+files.length+' production JavaScript modules import, including lazy private routes');
}finally{await browser.close();}
