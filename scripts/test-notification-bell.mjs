import {chromium} from 'playwright';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
const base=process.argv[2]||'http://127.0.0.1:4204';
try{
 const context=await browser.newContext({viewport:{width:375,height:812}});
 const user={id:'notification-test',name:'Test',email:'test@example.invalid',role:'user',email_verified:true};
 await context.addInitScript(user=>{localStorage.setItem('auth_user',JSON.stringify(user));localStorage.setItem('auth_token','fixture');localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('ieosuia_cookie_consent','{}');},user);
 let rows=Array.from({length:21},(_,i)=>({id:String(i+1),type:'campaign_complete',title:`Campaign ${i+1}`,message:'Sending finished, check delivery outcomes.',read:0,created_at:'2026-10-06 10:00:00',data:{campaign_id:i+1}}));let unavailable=false;
 await context.route('**/api/**',async route=>{
  const u=new URL(route.request().url()),path=u.pathname,method=route.request().method();let body={success:true};
  if(path.endsWith('/auth/user'))body={success:true,user};
  else if(path.endsWith('/notifications')&&method==='GET'){
   if(unavailable)return route.fulfill({status:503,json:{success:false,message:'Unavailable'}});
   const page=Number(u.searchParams.get('page')||1);body={success:true,notifications:rows.slice((page-1)*20,page*20),unread_count:rows.filter(r=>!r.read).length,has_more:rows.length>page*20,sync_available:true};
  }else if(path.endsWith('/notifications/read-all'))rows=rows.map(r=>({...r,read:1}));
  else if(/\/notifications\/\d+\/read$/.test(path)){const id=path.split('/').at(-2);rows=rows.map(r=>r.id===id?{...r,read:1}:r);}
  else if(method==='DELETE'&&path.includes('/notifications/'))rows=rows.filter(r=>r.id!==path.split('/').at(-1));
  else if(path.endsWith('/sms/campaigns-v2'))body={success:true,campaigns:[],stats:{total:0,sent:0,scheduled:0,credits_used:0}};
  return route.fulfill({json:body});
 });
 const page=await context.newPage();await page.goto(base+'/sms-campaigns');
 await page.getByRole('button',{name:'Notifications, 21 unread'}).click();
 await page.getByRole('button',{name:'Older',exact:true}).click();await page.getByRole('button',{name:/Campaign 21 Sending/}).waitFor();
 await page.getByRole('button',{name:'Dismiss Campaign 21',exact:true}).click();await page.getByText('No more notifications',{exact:true}).waitFor();
 await page.getByRole('button',{name:'Newer',exact:true}).click();await page.getByRole('button',{name:'Mark all read',exact:true}).click();await page.getByRole('button',{name:'Notifications, 0 unread'}).waitFor();
 await page.getByRole('button',{name:/Campaign 1 Sending/}).click();await page.waitForURL('**/sms-campaigns/1');
 unavailable=true;await page.getByRole('button',{name:'Notifications, 0 unread'}).click();await page.getByRole('alert').waitFor();
 assert.match(await page.getByRole('alert').innerText(),/Could not load/);assert.ok(await page.getByRole('button',{name:'Retry',exact:true}).isVisible());
 assert.ok(await page.getByRole('dialog').evaluate(el=>el.getBoundingClientRect().right<=innerWidth));
 console.log('PASS: mobile bell, pagination, persistent dismiss/read actions, unread count, campaign navigation and visible request failure. APIs mocked.');
}finally{await browser.close();}
