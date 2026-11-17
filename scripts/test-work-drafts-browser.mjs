import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
const base=process.argv[2]||'http://127.0.0.1:4247';
const out=process.argv[3]||'.sms-audit.local/work-drafts-review/browser';mkdirSync(out,{recursive:true});
let checks=0;const check=(v,label)=>{assert.ok(v,label);checks++;};
const browser=await chromium.launch({channel:'chrome',headless:true});
try{
 for(const [width,dark] of [[1440,false],[375,true],[320,false]]){
  const drafts=new Map(),requests=[],errors=[];const contexts=[];
  const contextFor=async(owner='draft-owner')=>{
   const user={id:owner,name:'Draft fixture',email:'fixture@example.invalid',role:'user',email_verified:true};
   const context=await browser.newContext({viewport:{width,height:1000}});contexts.push(context);
   await context.addInitScript(({user,dark})=>{
    localStorage.setItem('auth_user',JSON.stringify(user));localStorage.setItem('auth_token','fixture-only-not-a-real-token');localStorage.setItem('auth_token_issued',String(Date.now()));
    localStorage.setItem(`sms-ui:${user.id}:dark`,String(dark));localStorage.setItem('ieosuia_cookie_consent','{}');sessionStorage.setItem(`sms-portal-entered:${user.id}`,'1');
   },{user,dark});
   await context.route('**/api/**',async route=>{
    const req=route.request(),url=new URL(req.url()),path=url.pathname,method=req.method();requests.push({path,method});let result={success:true};let status=200;
    if(method!=='GET')assert.ok(path.startsWith('/api/drafts/')||path==='/api/sms/preview','Draft actions must never send SMS, create orders, pay, email or upload proofs');
    const match=path.match(/\/drafts\/([^/]+)$/);
    if(path.endsWith('/drafts'))result.drafts=[...drafts.values()].filter(d=>d.owner===owner&&d.kind===url.searchParams.get('kind')).map(({owner,payload,...d})=>d);
    else if(match){
     const id=match[1],old=drafts.get(id);
     if(old&&old.owner!==owner||!old&&method!=='PUT'){status=404;result={success:false,message:'Draft not found'};}
     else if(method==='GET'){const {owner,...d}=old;result.draft=d;}
     else if(method==='PUT'){
      const input=req.postDataJSON();assert.equal(input.expected_version,old?.version??0);const d={id,owner,kind:input.kind,title:input.title,payload:input.payload,version:(old?.version??0)+1,created_at:'2026-10-20 12:00:00',updated_at:'2026-10-20 12:01:00'};drafts.set(id,d);const {owner:_,...publicDraft}=d;result.draft=publicDraft;
     }else if(method==='DELETE'){assert.equal(req.postDataJSON().expected_version,old.version);drafts.delete(id);result.deleted=true;}
    }else if(path.endsWith('/auth/user'))result={...result,user};
    else if(path.endsWith('/settings/sms'))result={...result,opt_out_text:'Reply STOP to unsubscribe'};
    else if(path.endsWith('/sms/preview')){const input=req.postDataJSON();result.preview={final_content:input.content,encoding:'gsm7',character_count:input.content.length,segment_count:1,required_credits:1,available_credits:100,sufficient_balance:true,price_per_segment:.35,recipients:['27638082493'],sendable_count:1,entered_count:1,invalid_count:0,duplicate_count:0,opted_out_count:0,excluded_count:0};}
    else if(path.endsWith('/sms/campaigns-v2'))result={...result,campaigns:[],stats:{total:0,sent:0,scheduled:0,credits_used:0}};
    else if(path.endsWith('/wallet/purchase-options'))result={...result,purchases_available:true,direct_transfer_available:true};
    else if(path.endsWith('/wallet/purchase-orders'))result={...result,orders:[],total:0};
    else if(path.endsWith('/wallet/activity'))result={...result,transactions:[],total:0,page:1,last_page:1,total_paid:0};
    else if(path.endsWith('/wallet/stats'))result={...result,sms_credits:100,price_per_credit:.35,used_this_month:0};
    else if(path.endsWith('/wallet'))result={...result,wallet:{sms_credits:100,price_per_credit:.35}};
    else if(path.endsWith('/notifications'))result={...result,notifications:[],unread_count:0};
    else if(path.endsWith('/portal/overview'))result={...result,contacts:1,bought_credits:true,sent_campaign:true,checked_delivery:true};
    else if(/\/(contacts|contact-groups|templates)$/.test(path))result={...result,data:[],groups:[],templates:[]};
    await route.fulfill({status,json:result});
   });return context;
  };
  const first=await contextFor();const page=await first.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/sms-campaigns/new');await page.getByRole('main').getByRole('textbox').fill('Sunday follow-up');await page.getByRole('button',{name:'Next',exact:true}).click();
  await page.getByRole('main').getByRole('textbox').fill('0638082493');await page.getByRole('button',{name:'Next',exact:true}).click();
  await page.getByRole('textbox',{name:'SMS message'}).fill('Keep this SMS for later');await page.getByRole('switch',{name:'Include opt-out text'}).click();
  await page.getByRole('button',{name:'Save draft',exact:true}).click();let dialog=page.getByRole('dialog',{name:'Save for later'});
  await dialog.getByLabel('Draft name').fill('Saved Sunday SMS');check(await dialog.evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Save dialog fits viewport');
  await dialog.getByRole('button',{name:'Save and exit'}).click();await page.waitForURL('**/sms-campaigns');
  await page.getByRole('region',{name:'Saved SMS drafts'}).getByText('Saved Sunday SMS',{exact:true}).waitFor();
  const sms=[...drafts.values()].find(d=>d.kind==='sms');check(!!sms,'Named SMS draft persisted');check(sms.payload.includeOptOut===false,'Footer choice saved');check(sms.payload.manual==='0638082493','Recipients saved');check(!('required_credits' in sms.payload),'No stale credit quote saved');
  check(await page.getByRole('link',{name:'Resume',exact:true}).getAttribute('href')==='/sms-campaigns/new?draft='+sms.id,'Resume targets chosen draft');
  const second=await contextFor();const resumed=await second.newPage();resumed.on('pageerror',e=>errors.push(e.message));
  await resumed.goto(base+'/sms-campaigns/new?draft='+sms.id);await resumed.getByRole('textbox',{name:'SMS message'}).waitFor();
  check(await resumed.getByRole('textbox',{name:'SMS message'}).inputValue()==='Keep this SMS for later','Fresh browser resumes account message');check(await resumed.getByRole('switch',{name:'Include opt-out text'}).getAttribute('aria-checked')==='false','Fresh browser restores footer');check(await resumed.getByRole('button',{name:'Queue now',exact:true}).count()===0,'Resume requires fresh review');
  await resumed.getByRole('textbox',{name:'SMS message'}).fill('Browser recovery keeps this edit');await resumed.reload();await resumed.getByRole('textbox',{name:'SMS message'}).waitFor();
  check(await resumed.getByRole('textbox',{name:'SMS message'}).inputValue()==='Browser recovery keeps this edit','Unsaved browser edits survive refresh');await resumed.screenshot({path:`${out}/sms-${width}.png`,fullPage:true});
  await resumed.goto(base+'/wallet');await resumed.getByRole('button',{name:'Buy Credits',exact:true}).click();let buy=resumed.getByRole('dialog',{name:'Buy SMS Credits'});
  await buy.getByRole('spinbutton').fill('1000');await buy.getByRole('button',{name:'Choose payment method'}).click();await buy.getByRole('button',{name:/Pay directly/}).click();
  await buy.getByRole('button',{name:'Save draft',exact:true}).click();dialog=resumed.getByRole('dialog',{name:'Save for later'});await dialog.getByLabel('Draft name').fill('Next credit purchase');await dialog.getByRole('button',{name:'Save and exit'}).click();
  await resumed.getByRole('region',{name:'Saved purchase drafts'}).getByText('Next credit purchase',{exact:true}).waitFor();
  const purchase=[...drafts.values()].find(d=>d.kind==='purchase');check(purchase.payload.credits===1000&&purchase.payload.method==='eft','Purchase quantity and method saved');check(!('total_cents' in purchase.payload)&&!('bank_details' in purchase.payload),'No prices or bank/payment details saved');
  const third=await contextFor();const credit=await third.newPage();credit.on('pageerror',e=>errors.push(e.message));await credit.goto(base+'/wallet?draft='+purchase.id);buy=credit.getByRole('dialog',{name:'Buy SMS Credits'});
  await buy.getByRole('button',{name:/Pay directly/}).waitFor();check(await buy.getByRole('button',{name:/Pay directly/}).getAttribute('aria-pressed')==='true','Fresh browser restores purchase selection');check((await buy.innerText()).includes('R306.90'),'Current pricing recomputed');
  check(await buy.evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Purchase form fits viewport');await credit.screenshot({path:`${out}/purchase-${width}.png`,fullPage:true});
  await credit.reload();buy=credit.getByRole('dialog',{name:'Buy SMS Credits'});await buy.getByRole('button',{name:'Create bank transfer order',exact:true}).waitFor();check(!requests.some(r=>r.path.endsWith('/wallet/buy')),'Refreshing purchase never checks out');
  await buy.getByRole('button',{name:'Close',exact:true}).click();credit.on('dialog',d=>d.accept());await credit.getByRole('region',{name:'Saved purchase drafts'}).getByRole('button',{name:'Delete',exact:true}).click();await credit.getByText(/No saved drafts yet/).waitFor();check(!drafts.has(purchase.id),'Explicit delete removes selected draft only');check(drafts.has(sms.id),'Purchase deletion leaves SMS draft intact');
  const other=await contextFor('other-owner');const denied=await other.newPage();denied.on('pageerror',e=>errors.push(e.message));await denied.goto(base+'/sms-campaigns/new?draft='+sms.id);await denied.getByRole('alert').filter({hasText:'could not be opened'}).waitFor();check(!await denied.getByText('Keep this SMS for later',{exact:true}).count(),'Another account cannot read a draft');
  check(errors.length===0,'No browser exceptions: '+errors.join(';'));check(requests.every(r=>r.method==='GET'||r.path.startsWith('/api/drafts/')||r.path==='/api/sms/preview'),'No sending/payment writes');
  for(const ctx of contexts)await ctx.close();
 }
 writeFileSync(out+'/results.json',JSON.stringify({passed:true,checks,widths:[1440,375,320],darkModeChecked:true,allApiRequestsIntercepted:true,productionSmsSent:0,paymentsStarted:0,productionWrites:false},null,2));console.log('PASS: '+checks+' desktop/mobile draft browser assertions; isolated fixtures only.');
}finally{await browser.close();}
