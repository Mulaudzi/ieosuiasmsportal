import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
const base=process.argv[2]||'http://127.0.0.1:4288';assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);
const channel=process.env.EMAIL_BROWSER_CHANNEL||'chrome';const out=process.argv[3]||`.sms-audit.local/email-campaigns-20261029/browser-${channel}`;mkdirSync(out,{recursive:true});
const browser=await chromium.launch({channel,headless:true});let checks=0;
const check=(condition,label)=>{assert.ok(condition,label);checks++;};
const sender={user_id:1,business_name:'Example Community',postal_address:'100 Example Street, Example City',reply_to:'owner@example.org',state:'approved',daily_limit:20,version:2};
const campaign={id:'a'.repeat(32),user_id:1,name:'Community email',subject:'Requested community update',body:'Your requested community update.',state:'awaiting_review',recipient_count:2,reserved_cents:0,charged_cents:0,created_at:'2026-10-29',fingerprint:'f'.repeat(64),consent_evidence:'Signed member email signup forms, reference October 2026.',sender,recipients:[{id:'b'.repeat(32),email:'one@example.org',state:'pending',delivery_state:'unknown',attempts:0}]};
try {
  for(const [width,height]of[[1440,900],[768,1024],[375,667],[320,568]]) {
    for(const admin of[false,true]) {
      const context=await browser.newContext({viewport:{width,height}});const errors=[],writes=[];
      const user={id:admin?'90':'1',name:admin?'Review fixture':'Customer fixture',email:admin?'reviewer@example.org':'owner@example.org',role:admin?'admin':'user',email_verified:true,email_verified_at:'2026-01-01'};
      await context.addInitScript(user=>{localStorage.setItem('auth_user',JSON.stringify(user));localStorage.setItem('auth_token','fixture-only-not-live');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');},user);
      await context.route('**/*',route=>new URL(route.request().url()).origin===base?route.continue():route.abort());
      await context.route('**/api/**',async route=>{
        const path=new URL(route.request().url()).pathname,method=route.request().method();let result={success:true,data:[]};
        if(method!=='GET')writes.push(path);
        if(path.endsWith('/auth/user'))result={success:true,user};
        else if(path.endsWith('/wallet'))result={success:true,wallet:{balance:35,reserved:0,sms_credits:100,price_per_segment:.35}};
        else if(path.endsWith('/notifications'))result={success:true,notifications:[],unread_count:0,has_more:false};
        else if(path.endsWith('/email/limits'))result={success:true,enabled:true,emails_per_credit:5,readiness:{ready:false,reasons:['Written hosting permission and provider feedback acceptance are required.']},max_recipients:1000};
        else if(path.endsWith('/email/sender'))result={success:true,sender};
        else if(path.endsWith('/contact-groups'))result={success:true,groups:[{id:'4',name:'Consenting members',contact_count:2}]};
        else if(path.endsWith('/email/quote'))result={success:true,quote:{entered:2,invalid:0,duplicates:0,opted_out:0,sendable:2,credits_required:'0.4',amount:'0.1400',available_balance:'35.0000',sufficient_balance:true,recipients:['one@example.org','two@example.org']}};
        else if(path.endsWith('/admin/email-campaigns'))result={success:true,senders:[sender],campaigns:[campaign]};
        else if(path.endsWith('/email/campaigns')&&method==='GET')result={success:true,campaigns:[]};
        else if(path.endsWith('/email/campaigns')&&method==='POST')result={success:true,campaign};
        else if(path.endsWith('/admin/email-campaigns/'+campaign.id))result={success:true,campaign};
        else if(method!=='GET')throw new Error('Unexpected fixture write: '+path);
        await route.fulfill({json:result});
      });
      const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
      await page.goto(base+(admin?'/guymhan/email-campaigns':'/email-campaigns'));
      if(admin) {
        await page.getByRole('heading',{name:'Sender identities',exact:true}).waitFor();
        await page.getByRole('button',{name:/Example Community/}).click();
        check(await page.getByRole('button',{name:'Approve sender',exact:true}).isDisabled(),'Unreviewed sender cannot be approved');
        await page.screenshot({path:`${out}/admin-sender-${width}.png`,fullPage:true});
        await page.getByRole('button',{name:/Community email/}).click();
        await page.getByText('one@example.org',{exact:true}).waitFor();
        check(await page.getByRole('button',{name:'Approve campaign',exact:true}).isDisabled(),'Campaign requires independent content and consent review');
        check(writes.length===0,'Opening admin review does not write or send');
      } else {
        await page.getByLabel('Subject',{exact:true}).fill('Requested community update');
        await page.getByLabel('Email message',{exact:true}).fill('Here is the community update you asked us to send.');
        await page.getByLabel('Email addresses',{exact:true}).fill('one@example.org, two@example.org');
        await page.getByLabel('Consent source and evidence reference',{exact:true}).fill('Signed signup forms, recipient consent reference October 2026.');
        check(await page.getByRole('button',{name:'Submit for review',exact:true}).isDisabled(),'No review submission without consent and quote');
        await page.getByLabel(/Every recipient explicitly agreed/).check();
        await page.getByRole('button',{name:'Preview recipients & credits',exact:true}).click();
        await page.getByText(/Estimated charge:/).waitFor();
        check(await page.getByRole('button',{name:'Submit for review',exact:true}).isEnabled(),'Current quote and consent enable review, not sending');
        await page.getByRole('button',{name:'Submit for review',exact:true}).scrollIntoViewIfNeeded();
        const action=await page.getByRole('button',{name:'Submit for review',exact:true}).boundingBox();
        if(width<1024) {const nav=await page.getByRole('navigation',{name:'Mobile dashboard navigation'}).boundingBox();await page.screenshot({path:`${out}/mobile-action-debug-${width}.png`});const style=await page.locator('[data-email-actions]').evaluate(e=>({position:getComputedStyle(e).position,bottom:getComputedStyle(e).bottom,class:e.className}));check(action.y+action.height<=nav.y,'Email actions stay above the mobile navigation '+JSON.stringify({width,action,nav,style}));}
        await page.screenshot({path:`${out}/customer-composer-${width}.png`,fullPage:true});
        await page.getByRole('button',{name:'Submit for review',exact:true}).click();
        await page.getByRole('heading',{name:'Community email',exact:true}).waitFor();
        check(writes.filter(p=>p.endsWith('/email/campaigns')).length===1,'Exactly one review submission');
        check(!writes.some(p=>p.endsWith('/send')),'No provider submission from review');
      }
      check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'No page-level horizontal overflow at '+width);
      check(errors.length===0,'No browser exceptions: '+errors.join('; '));
      await page.screenshot({path:`${out}/${admin?'admin-review':'customer-review'}-${width}.png`,fullPage:true});await context.close();
    }
  }
  const page=await browser.newPage({viewport:{width:375,height:800}});
  await page.route('**/*',route=>new URL(route.request().url()).origin===base?route.continue():route.abort());
  await page.goto(base+'/email-fixture-preview.html');
  check(await page.getByText('Example Community via IEOSUIA',{exact:true}).isVisible(),'Generated HTML identifies the sender');
  check(await page.getByRole('link',{name:'Unsubscribe',exact:true}).isVisible(),'Generated HTML has a visible unsubscribe link');
  check(await page.getByRole('link',{name:'Report abuse',exact:true}).isVisible(),'Generated HTML has a visible abuse-report link');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'Generated HTML fits a narrow phone');
  await page.screenshot({path:`${out}/generated-email-mobile.png`,fullPage:true});
  writeFileSync(`${out}/result.json`,JSON.stringify({passed:true,checks,channel,scope:'Local build with mocked APIs, generated MIME/HTML fixture. No live sends or provider delivery claims.'},null,2));
  console.log(JSON.stringify({passed:true,checks,channel}));
} finally {await browser.close();}
