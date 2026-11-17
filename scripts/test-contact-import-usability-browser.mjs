import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
const base=process.argv[2]??'http://127.0.0.1:4277';assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);
const out=process.argv[3]??'.sms-audit.local/import-usability-20261029/browser';const workbook=process.argv[4];mkdirSync(out,{recursive:true});
const channel=process.env.IMPORT_BROWSER_CHANNEL??'chrome';
const browser=await chromium.launch({channel,headless:true});let checks=0;
const check=(condition,label)=>{assert.ok(condition,label);checks++;};
const csv='Church report;;\r\nName;Phone;Email\r\nCorrect;0821234567;\r\nLost zero;821234568;\r\nFooter;;\r\nEmail only;;only@example.com\r\nIncomplete;2771234567;kept@example.com\r\nBad email;0821234569;bad-email\r\nMultiple;0821234567 / 0831234567;\r\n';
try{
 for(const [width,height] of [[1440,900],[1366,640],[1024,768],[768,1024],[375,667],[320,568]]){
  const context=await browser.newContext({viewport:{width,height},locale:width===1366?'en-ZA':'en-US'});
  const user={id:'import-v2-fixture',name:'Import fixture',email:'fixture@example.invalid',role:'user',email_verified:true};
  await context.addInitScript(user=>{localStorage.setItem('auth_user',JSON.stringify(user));localStorage.setItem('auth_token','fixture-only');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');},user);
  if(width===1366)await context.addInitScript(()=>Object.defineProperty(window,'Worker',{value:undefined}));
  if(width===1024)await context.addInitScript(()=>Object.defineProperty(window,'Worker',{value:class {constructor(){throw new DOMException('Fixture worker blocked','SecurityError');}}}));
  const errors=[],imports=[];let failImport=true;
  await context.route('**/*',route=>new URL(route.request().url()).origin===base?route.continue():route.abort());
  await context.route('**/api/**',async route=>{
   const path=new URL(route.request().url()).pathname;
   let result={success:true,data:[],meta:{current_page:1,last_page:1,total:0,opted_out_total:0},groups:[]};
   if(path.endsWith('/auth/user'))result={success:true,user};
   else if(path.endsWith('/notifications'))result={success:true,notifications:[],unread_count:0,has_more:false};
   else if(path.endsWith('/wallet'))result={success:true,wallet:{balance:35,reserved:0,sms_credits:100,price_per_segment:.35}};
   else if(path.endsWith('/contact-groups'))result={success:true,total_contacts:0,groups:[{id:'1',name:'Destination',contact_count:0}]};
   else if(path.endsWith('/contacts/import')){
    imports.push(route.request().postData());
    if(failImport){failImport=false;await route.fulfill({status:400,json:{success:false,error:'Fixture failure. Try again.'}});return;}
    result={success:true,imported:5,duplicates:0,failed:0,corrected:2,rejected:1,skipped_empty:1,automatically_corrected:1,email_only:2};
   }else if(route.request().method()!=='GET')throw new Error('Unexpected write in fixture: '+path);
   await route.fulfill({json:result});
  });
  const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
  await page.goto(base+'/contacts');await page.getByRole('button',{name:'Destination',exact:true}).click();
  await page.getByRole('button',{name:'Import',exact:true}).click();const dialog=page.getByRole('dialog');
  const upload=async name=>dialog.locator('input[type=file]').setInputFiles({name,mimeType:'text/csv',buffer:Buffer.from(csv)});
  await upload('regional.csv');await dialog.getByText('7 rows found',{exact:true}).waitFor();
  check((await dialog.getByRole('combobox',{name:'Phone Column',exact:true}).innerText())==='Phone','Phone column mapped accurately');
  await dialog.getByRole('button',{name:'Continue',exact:true}).click();
  const submit=()=>dialog.getByRole('button',{name:/^Import \d+ Contacts to/});
  check((await dialog.getByRole('status',{name:'Import checks'}).innerText()).includes('3 ready · 3 need review · 1 empty'),'Only genuine issues block import');
  check(await submit().isDisabled(),'Incomplete and invalid email rows cannot bypass review');
  const actions=dialog.locator('[data-import-actions]');const scroll=dialog.locator('[data-import-scroll]');
  await scroll.evaluate(node=>node.scrollTop=0);
  await page.waitForFunction(()=>document.querySelector('[data-import-actions]')?.getAttribute('data-import-actions')==='floating');
  let box=await actions.boundingBox();check(box.y>=0&&box.y+box.height<=height,'Footer stays within viewport before scrolling');
  check(await actions.getByRole('button',{name:'Back',exact:true}).isVisible(),'Back always available');
  const primaryBox=await submit().boundingBox(),backBox=await actions.getByRole('button',{name:'Back',exact:true}).boundingBox();
  check(backBox.x<primaryBox.x,'Back left and primary action right');
  await actions.getByRole('button',{name:/Scroll down/}).click();
  await page.waitForFunction(()=>document.querySelector('[data-import-scroll]').scrollTop>0);
  check(await scroll.evaluate(node=>node.scrollTop>0),'More-content hint scrolls dialog rather than the page');
  await dialog.getByLabel('Find a contact or worksheet row').fill('Incomplete');
  check(await dialog.locator('[data-review-row]').count()===1,'Search filters issue cards');
  await dialog.getByRole('button',{name:'Keep email only',exact:true}).click();
  check((await dialog.getByRole('status',{name:'Import checks'}).innerText()).includes('4 ready · 2 need review'),'Keep email only resolves number without guessing');
  await actions.getByRole('button',{name:'Back',exact:true}).click();
  check(await dialog.getByText('7 rows found',{exact:true}).isVisible(),'Back restores mapping');
  await dialog.getByRole('button',{name:'Continue',exact:true}).click();
  check((await dialog.getByRole('status',{name:'Import checks'}).innerText()).includes('4 ready · 2 need review'),'Back and continue retain completed choices');
  await dialog.getByLabel('Find a contact or worksheet row').fill('Bad email');
  await dialog.getByLabel('Email correction for row 8',{exact:true}).fill('');
  await dialog.getByRole('button',{name:'Accept correction for row 8',exact:true}).click();
  check((await dialog.getByRole('status',{name:'Import checks'}).innerText()).includes('5 ready · 1 need review'),'Optional bad email can be removed without losing SMS contact');
  await dialog.getByLabel('Find a contact or worksheet row').fill('Multiple');
  await dialog.getByRole('checkbox',{name:'Select all 1 matching rows',exact:true}).click();
  check(await dialog.getByRole('button',{name:'Accept selected (1)',exact:true}).isDisabled(),'Bulk accept cannot choose between two phone numbers');
  await dialog.getByRole('button',{name:'Skip selected (1)',exact:true}).click();
  check(await submit().isEnabled(),'Resolved issues enable import');
  await dialog.getByLabel('Find a contact or worksheet row').fill('');
  await dialog.getByLabel('Review filter',{exact:true}).selectOption('rejected');
  check(await dialog.locator('[data-review-row="9"]').count()===1,'Rejected filter shows skipped source row');
  await dialog.getByLabel('Review filter',{exact:true}).selectOption('ready');
  check(await dialog.locator('[data-review-row]').count()===2,'Resolved filter shows corrections and email-only choice');
  await dialog.getByLabel('Contact list filter',{exact:true}).selectOption('skipped');
  check((await dialog.locator('tbody').innerText()).includes('Footer'),'Empty-contact filter explains skipped footer');
  await scroll.evaluate(node=>node.scrollTop=node.scrollHeight);
  await page.waitForFunction(()=>document.querySelector('[data-import-actions]')?.getAttribute('data-import-actions')==='inline');
  box=await actions.boundingBox();check(box.y>=0&&box.y+box.height<=height,'Footer returns to inline state at bottom');
  check(await dialog.evaluate(node=>node.scrollWidth<=node.clientWidth+1),'Dialog has no horizontal overflow');
  await page.screenshot({path:`${out}/review-${width}.png`});
  await submit().click();await actions.getByRole('button',{name:'Back',exact:true}).waitFor();
  await submit().waitFor();check(await submit().isEnabled(),'Failed import keeps review choices for retry');
  await submit().click();await dialog.getByText('5 new contacts imported',{exact:true}).waitFor();
  check(imports.length===2,'One failed submission and one explicit retry only');
  for(const body of imports){check(/name="phone_review_version"\r\n\r\n2\r\n/.test(body),'Version 2 explicitly requested');const choices=JSON.parse(body.match(/name="phone_review_decisions"\r\n\r\n([^\r\n]+)/)[1]);check(choices.length===3&&choices.every(choice=>typeof choice.originalEmail==='string'),'Only reviewed rows have row-bound decisions');check(choices.find(choice=>choice.action==='email_only').row===6,'Original worksheet row is translated to serialized CSV row');}
  await dialog.getByRole('button',{name:'Done',exact:true}).click();
  for(const [name,text] of [['headerless-phones.csv','821234567\n0821234568\n'],['headerless-emails.csv','a@example.com\nb@example.com\n']]){
   await page.getByRole('button',{name:'Import',exact:true}).click();
   await dialog.locator('input[type=file]').setInputFiles({name,mimeType:'text/csv',buffer:Buffer.from(text)});
   await dialog.getByText('2 rows found',{exact:true}).waitFor();
   check(!await dialog.getByRole('checkbox',{name:'File contains column headings',exact:true}).isChecked(),'Headerless phone/email file retains first contact');
   await dialog.getByRole('button',{name:'Continue',exact:true}).click();
   check((await dialog.getByRole('status',{name:'Import checks'}).innerText()).includes('2 ready · 0 need review'),'Headerless phone/email columns auto-map correctly');
   check(await submit().isEnabled(),'Headerless contacts are ready without unnecessary review');
   await dialog.getByRole('button',{name:'Close',exact:true}).click();
  }
  if(workbook){
   await page.getByRole('button',{name:'Import',exact:true}).click();await dialog.locator('input[type=file]').setInputFiles(workbook);
   await dialog.getByRole('button',{name:'Use this worksheet',exact:true}).click();await dialog.getByText('61 rows found',{exact:true}).waitFor();
   check((await dialog.getByRole('combobox',{name:'Column heading row'}).innerText()).startsWith('Row 2:'),'Actual workbook title/header choice is deterministic');
   await dialog.getByRole('button',{name:'Continue',exact:true}).click();
   check((await dialog.getByRole('status',{name:'Import checks'}).innerText()).includes('61 ready · 0 need review'),'All complete workbook numbers qualify without unnecessary review');
   check((await dialog.getByRole('status',{name:'Import checks'}).innerText()).includes('47 safe number fixes'),'Missing-zero fixes summarized without 47 approval cards');
   check(await submit().isEnabled(),'Actual workbook is ready without approving each zero');
   check(imports.length===2,'Private workbook is never submitted');
   await dialog.getByRole('button',{name:'Close',exact:true}).click();
  }
  check(errors.length===0,'No browser exceptions: '+errors.join('; '));await context.close();
 }
 const result={passed:true,checks,channel,browser_version:browser.version(),viewports:['1440x900','1366x640','1024x768','768x1024','375x667','320x568'],locales:['en-US','en-ZA'],worker_fallback_tested:Boolean(workbook),all_api_responses_mocked:true,private_contacts_saved:0};
 writeFileSync(out+'/results.json',JSON.stringify(result,null,2));console.log(JSON.stringify(result));
}finally{await browser.close();}
