import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';

const base=process.argv[2]??'http://127.0.0.1:4338';
assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);
const out=process.argv[3]||'.sms-audit.local/customer-health-fixes-20261105/browser';mkdirSync(out,{recursive:true});
const browser=await chromium.launch({channel:'chrome',headless:true});
let checks=0;const results=[];
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
const user={id:'scheduling-browser-fixture',name:'Scheduling fixture',email:'fixture@example.invalid',role:'user',email_verified:true};
try{
  const cases=[...['Africa/Johannesburg','America/New_York'].flatMap(zone=>[1440,768,390,320].flatMap(width=>[2,485].map(count=>({zone,width,clear:false,count})))),{zone:'Africa/Johannesburg',width:320,clear:true,count:485}];
  for(const {zone,width,clear,count}of cases){
    const preview={final_content:'Synthetic scheduling fixture',encoding:'gsm7',character_count:28,segment_count:1,total_segments:count,required_credits:count,available_credits:1000,price_per_segment:.35,estimated_charge:count*.35,sufficient_balance:true,recipients:Array.from({length:count},(_,i)=>String(27800000000+i)),sendable_count:count,entered_count:count,invalid_count:0,duplicate_count:0,opted_out_count:0,excluded_count:0};
    const context=await browser.newContext({timezoneId:zone,viewport:{width,height:width<500?844:1000},isMobile:width<500});
    const submitted=[],errors=[],consoleErrors=[],failedRequests=[];
    await context.addInitScript(({user})=>{
      localStorage.setItem('auth_token','synthetic-only');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('auth_user',JSON.stringify(user));
      localStorage.setItem(`sms-ui:${user.id}:dashboard-tour-seen`,'true');sessionStorage.setItem(`sms-portal-entered:${user.id}`,'1');localStorage.setItem('ieosuia_cookie_consent','{}');
      localStorage.setItem(`sms-campaign-draft:v1:${user.id}`,JSON.stringify({version:1,step:5,name:'Scheduling fixture',manual:'',groupIds:['8'],contactIds:[],excluded:[],selectedTemplate:'none',content:'Synthetic scheduling fixture',includeOptOut:false,schedule:'2099-01-01T11:00',submissionKey:'browser-fixture-key'}));
    },{user});
    await context.route('**/*',async route=>{
      const request=route.request(),url=new URL(request.url());
      // API hosts can be configured separately from the local UI. Fulfil all
      // API calls synthetically before allowing only local static resources.
      if(!url.pathname.startsWith('/api/'))return url.origin===base?route.continue():route.abort();
      let body={success:true,data:[]};
      if(request.method()==='POST'&&url.pathname.endsWith('/sms/preview'))body={success:true,preview};
      else if(request.method()==='POST'&&url.pathname.endsWith('/sms/campaigns-v2')){submitted.push(request.postDataJSON());body={success:true,campaign:{id:999,state:submitted.at(-1).send_now?'queued':'scheduled'}};}
      else{
        assert.equal(request.method(),'GET','No unrelated mutation is permitted: '+url.pathname);
        if(url.pathname.endsWith('/auth/me')||url.pathname.endsWith('/auth/user'))body={success:true,user};
        else if(url.pathname.endsWith('/settings/sms'))body={success:true,opt_out_text:'Reply STOP to unsubscribe'};
        else if(url.pathname.endsWith('/contact-groups'))body={success:true,groups:[{id:'8',name:'Scheduling fixture group',contact_count:count}]};
        else if(url.pathname.endsWith('/notifications'))body={success:true,notifications:[],unread_count:0};
        else if(url.pathname.includes('/wallet'))body={success:true,wallet:{balance:3.5,reserved:0,available:3.5,sms_credits:10,price_per_credit:.35,currency:'ZAR'}};
        else if(url.pathname.endsWith('/dashboard/stats'))body={success:true,total_recipients:1,total_sent:0,total_delivered:0,total_failed:0,total_pending:1,total_awaiting_delivery:0,total_contacts:1,active_campaigns:1};
        else if(url.pathname.endsWith('/dashboard/recent-campaigns'))body={success:true,campaigns:[]};
        else if(url.pathname.endsWith('/dashboard/chart'))body={success:true,chart:[]};
        else if(url.pathname.endsWith('/portal/overview'))body={success:true,contacts:1,bought_credits:true,sent_campaign:true,checked_delivery:false};
        else body={success:true,data:[],drafts:[],templates:[],contacts:[]};
      }
      await route.fulfill({json:body});
    });
    const page=await context.newPage();page.setDefaultTimeout(60000);page.on('pageerror',error=>errors.push(error.message));
    page.on('console',message=>{if(message.type()==='error')consoleErrors.push(message.text().slice(0,300));});
    page.on('requestfailed',request=>failedRequests.push({path:new URL(request.url()).pathname,error:request.failure()?.errorText}));
    try{
      await page.goto(base+'/sms-campaigns/new',{waitUntil:'domcontentloaded',timeout:60000});
      await page.getByLabel('Campaign name (optional)').waitFor();
      check(submitted.length===0,'Restoring a final-step draft never submits automatically');
      await page.getByRole('button',{name:'Next: Recipients',exact:true}).click();
      await page.getByRole('button',{name:'Next: Message',exact:true}).click();
      await page.getByRole('button',{name:'Next: Review',exact:true}).click();
      await page.getByRole('button',{name:'Next: Send',exact:true}).click();
      const schedule=page.getByLabel('Schedule time (optional)');
      await schedule.waitFor();check(await schedule.inputValue()==='2099-01-01T11:00','Draft schedule survives all five steps');
      check(await page.getByRole('button',{name:'Send SMS',exact:true}).count()===0,'No immediate-send action beside a selected schedule');
      check(await page.locator('#sms-schedule-zone').innerText().then(text=>text.includes(zone)),'Device timezone is explicitly displayed');
      await page.evaluate(()=>window.scrollTo(0,0));await page.waitForTimeout(120);
      const action=page.getByRole('button',{name:'Schedule SMS',exact:true});const box=await action.boundingBox();
      check(!!box&&box.y>=0&&box.y+box.height<=(width<500?844:1000)+1,'Primary action remains visible before scrolling to its natural position');
      check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No horizontal page overflow');
      const suffix=`${width}-${zone.replaceAll('/','-')}-${count}-${clear?'clear':'schedule'}`;
      await page.screenshot({path:`${out}/${suffix}.png`,fullPage:true});
      if(clear){
        await page.getByRole('button',{name:'Clear schedule',exact:true}).click();
        check(submitted.length===0,'Clearing a schedule does not submit');
        await page.getByRole('button',{name:'Send SMS',exact:true}).click();
      }else await action.click();
      await page.waitForURL('**/dashboard');
      check(submitted.length===1,'One click creates exactly one request');
      check(submitted[0].send_now===clear,'Chosen action is preserved in the API request');
      const expected=clear?null:await page.evaluate(()=>new Date('2099-01-01T11:00').toISOString());
      check(submitted[0].scheduled_at===expected,'API preserves the device-local instant with an explicit timezone');
      check(submitted[0].include_opt_out===false,'Opt-out preference is not changed by scheduling');
      check(errors.length===0,'No browser application errors');
      results.push({width,timezone:zone,recipients:count,cleared:clear,passed:true});
    }catch(error){writeFileSync(`${out}/failure-${width}.json`,JSON.stringify({url:page.url(),application_errors:errors,console_errors:consoleErrors,failed_requests:failedRequests},null,2));await page.screenshot({path:`${out}/failure-${width}.png`,fullPage:true});throw error;}
    finally{await context.close();}
  }
  const result={passed:true,checks,cases:results,synthetic_api_only:true,production_access:false,real_sms_sent:false};
  writeFileSync(`${out}/result.json`,JSON.stringify(result,null,2));console.log(JSON.stringify(result));
}finally{await browser.close();}
