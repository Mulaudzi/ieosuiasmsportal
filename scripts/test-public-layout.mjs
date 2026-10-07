import {chromium} from 'playwright';
import assert from 'node:assert/strict';
const base=process.argv[2]||'http://127.0.0.1:4186';
const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
try{
 const context=await browser.newContext({viewport:{width:390,height:844}});
 await context.addInitScript(()=>{
  window.__seoLayoutShifts=0;
  new PerformanceObserver(list=>{for(const entry of list.getEntries())if(!entry.hadRecentInput)window.__seoLayoutShifts+=entry.value;}).observe({type:'layout-shift',buffered:true});
 });
 const page=await context.newPage();const client=await context.newCDPSession(page);
 await client.send('Emulation.setCPUThrottlingRate',{rate:4});
 for(const route of ['/','/pricing','/faq']){
  await page.goto(base+route,{waitUntil:'networkidle'});
  await page.getByRole('button',{name:'Accept All',exact:true}).waitFor();
  const value=await page.evaluate(()=>window.__seoLayoutShifts);
  console.log(route+' layout shift '+value);
  assert.ok(value<0.01,'Public auth initialisation must not move the content');
 }
}finally{await browser.close();}
