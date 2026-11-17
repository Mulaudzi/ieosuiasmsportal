import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
const base=process.argv[2]||'http://127.0.0.1:4291';assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);
const channel=process.env.SMS_TAGS_BROWSER_CHANNEL||'chrome',out=`.sms-audit.local/mobile-tags-20261029/browser-${channel}`;mkdirSync(out,{recursive:true});
const browser=await chromium.launch({channel,headless:true});let checks=0;
const check=(v,label)=>{assert.ok(v,label);checks++;};
const footer='Reply STOP to unsubscribe',leaders='a'.repeat(32),customers='b'.repeat(32),youth='c'.repeat(32);
try {
  for(const[width,height]of[[1440,900],[768,1024],[375,667],[320,568]]) {
    const context=await browser.newContext({viewport:{width,height}}),errors=[],writes=[],contactQueries=[];
    const user={id:'mobile-tags-fixture',name:'Mobile tags fixture',email:'fixture@example.invalid',email_verified:true,email_verified_at:'2026-01-01',role:'user'};
    const groups=[{id:'1',name:'Members',contact_count:3},{id:'2',name:'Volunteers',contact_count:2}];
    const tags=[{id:leaders,name:'Leaders'},{id:customers,name:'Customers'}];
    const rows=[['1','Alice',['1'],[leaders]],['2','Bob',['1','2'],[leaders,customers]],['3','Carol',['1'],[customers]],['4','Dan',['2'],[]]].map(([id,name,g,t])=>({id,name,phone:`+2782000000${id}`,email:`fixture${id}@example.invalid`,group_id:g.join(','),group_name:g.map(id=>groups.find(v=>v.id===id).name).join(','),subscription_status:'subscribed',created_at:'2026-10-29',groups:g,tagIds:new Set(t)}));
    let draft=null;
    const match=f=>{
      let result=rows;let gs=String(f.group_ids||'').split(',').filter(Boolean);if(f.group_id&&f.group_id!=='all')gs=[...gs,String(f.group_id)];
      const ts=String(f.tag_ids||'').split(',').filter(Boolean);
      if(gs.length)result=result.filter(r=>r.groups.some(g=>gs.includes(g)));if(ts.length)result=result.filter(r=>[...r.tagIds].some(t=>ts.includes(t)));if(f.search)result=result.filter(r=>r.name.toLowerCase().includes(String(f.search).toLowerCase()));return result;
    };
    await context.addInitScript(u=>{localStorage.setItem('auth_user',JSON.stringify(u));localStorage.setItem('auth_token','fixture-only');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');},user);
    await context.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
    await context.route('**/api/**',async route=>{
      const request=route.request(),url=new URL(request.url()),path=url.pathname,method=request.method();let data={success:true,data:[]};const body=method==='GET'?{}:request.postDataJSON();
      if(method!=='GET')writes.push({path,body});
      if(path.endsWith('/auth/user'))data={success:true,user};
      else if(path.endsWith('/wallet'))data={success:true,wallet:{balance:35,reserved:0,sms_credits:100,price_per_segment:.35}};
      else if(path.endsWith('/notifications'))data={success:true,notifications:[],unread_count:0,has_more:false};
      else if(path.endsWith('/contact-groups'))data={success:true,total_contacts:4,groups};
      else if(path.endsWith('/contact-tags')&&method==='GET')data={success:true,tags:tags.map(t=>({...t,contact_count:rows.filter(r=>r.tagIds.has(t.id)).length}))};
      else if(path.endsWith('/contact-tags')&&method==='POST'){tags.push({id:youth,name:body.name});data={success:true,tag:tags.at(-1)};}
      else if(path.endsWith('/contact-tags/assign')){for(const r of rows.filter(r=>body.contact_ids.includes(r.id)))for(const t of body.tag_ids)body.operation==='add'?r.tagIds.add(t):r.tagIds.delete(t);data={success:true,changed:4};}
      else if(path.endsWith('/contact-tags/sms-draft')){const selected=match(body);draft={id:body.draft_id,kind:'sms',title:'Filtered contact audience',version:1,created_at:'2026-10-29',updated_at:'2026-10-29',payload:{version:1,step:1,name:'',manual:'',groupIds:[],contactIds:selected.map(r=>r.id),excluded:[],selectedTemplate:'none',content:'',includeOptOut:false,schedule:'',submissionKey:body.draft_id}};data={success:true,draft};}
      else if(path.includes('/drafts/')&&method==='GET')data={success:true,draft};
      else if(path.endsWith('/settings/sms'))data={success:true,opt_out_text:footer};
      else if(path.endsWith('/contacts')){const f=Object.fromEntries(url.searchParams);contactQueries.push(f);const selected=match(f);data={success:true,data:selected.map(r=>({...r,tags:tags.filter(t=>r.tagIds.has(t.id))})),meta:{current_page:1,per_page:50,total:selected.length,last_page:1,opted_out_total:0,recently_added_total:4}};}
      else if(path.endsWith('/sms/preview')){
        const source=body.recipients;const selected=rows.filter(r=>source.contact_ids.includes(r.id)||r.groups.some(g=>source.group_ids.includes(g)));let phones=[...new Set([...selected.map(r=>r.phone),...source.manual])].filter(p=>!source.excluded.includes(p));
        let text=body.content.trim();if(body.include_opt_out&&!text.endsWith(footer))text+='\n'+footer;
        const unicode=/[^\x00-\x7f]/.test(text),units=unicode?text.length:Array.from(text).reduce((n,c)=>n+('^{}\\[~]|'.includes(c)?2:1),0),parts=units===0?0:units<=(unicode?70:160)?1:Math.ceil(units/(unicode?67:153));
        data={success:true,preview:{final_content:text,include_opt_out:body.include_opt_out,entered_count:phones.length,valid_count:phones.length,invalid_count:0,duplicate_count:0,opted_out_count:0,excluded_count:0,sendable_count:phones.length,recipients:phones,encoding:unicode?'ucs2':'gsm7',character_count:Array.from(text).length,unit_count:units,segment_count:parts,total_segments:parts*phones.length,required_credits:parts*phones.length,price_per_segment:.35,estimated_charge:parts*phones.length*.35,wallet_balance:35,available_balance:35,available_credits:100,sufficient_balance:true,invalid:[]}};
      }else if(path.endsWith('/templates'))data={success:true,templates:[]};
      else if(method!=='GET')throw new Error('Unexpected write: '+path);
      await route.fulfill({json:data});
    });
    const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
    await page.goto(base+'/sms-campaigns/new');await page.getByRole('button',{name:'Next: Recipients',exact:true}).click();
    await page.getByRole('button',{name:'Members 3 contacts',exact:true}).click();await page.getByRole('button',{name:'Next: Message',exact:true}).click();
    const input=page.getByRole('textbox',{name:'SMS message',exact:true});await input.fill('a'.repeat(160));
    await page.locator('[data-sms-total-credits]').filter({hasText:'3'}).waitFor();
    check(await page.locator('[data-sms-character-count]').innerText()==='160','Live 160 character count');check(await page.locator('[data-sms-part-count]').innerText()==='1','One part per recipient at 160 GSM units');
    await input.fill('a'.repeat(161));check(await page.locator('[data-sms-part-count]').innerText()==='2','161 GSM units increases segments immediately');check(await page.locator('[data-sms-total-credits]').innerText()==='6','Total estimate includes all selected recipients');
    await page.getByRole('switch',{name:'Include opt-out text',exact:true}).check();await input.fill('a'.repeat(150));check(await page.locator('[data-sms-character-count]').innerText()===String(151+footer.length),'Enabled footer is counted');await page.getByRole('switch',{name:'Include opt-out text',exact:true}).uncheck();
    await input.fill('😀'.repeat(36));check(await page.locator('[data-sms-character-count]').innerText()==='36','Emoji character count remains human-readable');check(await page.locator('[data-sms-part-count]').innerText()==='2','Emoji uses two UTF-16 units per character for billing');
    const bar=page.locator('[data-wizard-actions]');await page.getByRole('heading',{name:'Message',exact:true}).scrollIntoViewIfNeeded();await page.waitForFunction(()=>document.querySelector('[data-wizard-actions]')?.getAttribute('data-wizard-actions')==='floating');
    let box=await bar.boundingBox();check(box.y>=0&&box.y+box.height<=height,'Floating counters and next action stay inside viewport');
    if(width<1024){const nav=await page.getByRole('navigation',{name:'Mobile dashboard navigation'}).boundingBox();check(box.y+box.height<=nav.y,'Floating actions clear mobile navigation');const cells=await page.locator('[aria-label="Live SMS totals"] dl>div').evaluateAll(nodes=>nodes.map(n=>n.getBoundingClientRect().y));check(Math.max(...cells)-Math.min(...cells)<2,'All three live figures stay in one mobile row');check(await input.evaluate(n=>parseFloat(getComputedStyle(n).fontSize))>=16,'Mobile composer avoids small input text/automatic focus zoom');
      await page.evaluate(()=>{window.__originalViewport=Object.getOwnPropertyDescriptor(window,'visualViewport');const v=new EventTarget();Object.assign(v,{height:260,width:innerWidth,offsetTop:0,offsetLeft:0});Object.defineProperty(window,'visualViewport',{configurable:true,value:v});window.dispatchEvent(new Event('resize'));});
      await page.waitForFunction(()=>document.querySelector('[data-wizard-actions]')?.getAttribute('data-keyboard')==='true');box=await bar.boundingBox();check(box.y>=0&&box.y+box.height<=261,'Counters and controls remain above simulated on-screen keyboard');await page.screenshot({path:`${out}/sms-keyboard-${width}.png`});
      await page.evaluate(()=>{Object.defineProperty(window,'visualViewport',window.__originalViewport);window.dispatchEvent(new Event('resize'));});
    }
    await page.screenshot({path:`${out}/sms-floating-${width}.png`});
    await bar.locator('..').scrollIntoViewIfNeeded();await page.waitForFunction(()=>document.querySelector('[data-wizard-actions]')?.getAttribute('data-wizard-actions')==='inline');check(true,'Actions return to natural position');
    await page.getByRole('button',{name:'Hide tips',exact:true}).click();await page.reload();await page.getByRole('button',{name:'Show tips',exact:true}).waitFor();check(true,'Tip dismissal survives reload');
    await page.goto(base+'/contacts');await page.getByRole('button',{name:/^Contact Filters\/Tags/}).click();await page.getByLabel('New tag',{exact:true}).fill('Youth');await page.getByRole('button',{name:'Create tag',exact:true}).click();
    const panel=page.locator('section[aria-label="Contact filters and tags"]');await panel.getByRole('checkbox',{name:/^Youth/}).waitFor();check(tags.some(t=>t.name==='Youth'),'Customer can create a custom tag');
    await panel.getByRole('checkbox',{name:/^Leaders/}).check();await page.getByText('Showing 1-2 of 2 contacts',{exact:false}).waitFor();
    await panel.locator('summary').filter({hasText:'Within groups'}).click();await panel.getByRole('checkbox',{name:'Members',exact:true}).check();await panel.getByRole('checkbox',{name:'Volunteers',exact:true}).check();await page.getByRole('heading',{name:'2 selected groups',exact:true}).waitFor();
    check(contactQueries.some(q=>q.tag_ids===leaders&&q.group_ids==='1,2'),'Tag plus multi-group filters reach server together');
    await page.getByRole('checkbox',{name:'Select all contacts on this page',exact:true}).check();await page.getByRole('button',{name:'Assign tags',exact:true}).click();const dialog=page.getByRole('dialog');await dialog.getByRole('checkbox',{name:'Customers',exact:true}).check();await dialog.getByRole('checkbox',{name:'Youth',exact:true}).check();await dialog.getByRole('button',{name:'Add chosen tags',exact:true}).click();await dialog.waitFor({state:'hidden'});
    const assigned=writes.find(w=>w.path.endsWith('/contact-tags/assign'));check(assigned.body.contact_ids.length===2&&assigned.body.tag_ids.length===2&&assigned.body.operation==='add','Two tags assigned to two contacts in one explicit bulk action');
    await page.screenshot({path:`${out}/contacts-tags-${width}.png`,fullPage:true});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No page-level horizontal overflow');
    await page.getByRole('button',{name:'Prepare filtered SMS',exact:true}).click();await page.waitForURL(/\/sms-campaigns\/new\?draft=/);await page.getByRole('button',{name:'Next: Recipients',exact:true}).waitFor();check(draft.payload.contactIds.length===2&&draft.payload.groupIds.length===0,'Filtered audience enters a separate exact-contact draft, never a broad group');check(await page.getByRole('textbox',{name:'Campaign name (optional)',exact:true}).isVisible(),'Filtered handoff still starts at campaign name');
    check(writes.every(w=>!w.path.includes('/campaigns-v2')&&!w.path.includes('/email/')),'No send/payment/email API called');check(errors.length===0,'No browser exceptions: '+errors.join('; '));await context.close();
  }
  writeFileSync(`${out}/result.json`,JSON.stringify({passed:true,checks,channel,scope:'Local build, mocked APIs, synthetic contacts. Keyboard viewport simulated; no real device or provider claim.'},null,2));console.log(JSON.stringify({passed:true,checks,channel}));
}finally{await browser.close();}
