import {build} from 'esbuild';
import {chromium} from 'playwright';
import {createServer} from 'node:http';
import {readFile,mkdir} from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';
const directory=path.resolve('.sms-audit.local/sms-counter-test');
await mkdir(directory,{recursive:true});
await build({
 stdin:{contents:`
 import React, {useState} from 'react';
 import {createRoot} from 'react-dom/client';
 import {SmsMessageCounter} from './src/components/marketing/SmsMessageCounter';
 function App(){
   const [content,setContent]=useState('');
   const [enabled,setEnabled]=useState(false);
   return <><label>Message<textarea value={content} onChange={e=>setContent(e.target.value)}/></label>
   <label>Footer<input type="checkbox" checked={enabled} onChange={e=>setEnabled(e.target.checked)}/></label>
   <SmsMessageCounter content={content} includeFooter={enabled} footer="Reply STOP to unsubscribe" footerLoaded={true} onChange={setContent}/></>;
 }
 createRoot(document.getElementById('root')).render(<App/>);`,
 resolveDir:process.cwd(),loader:'tsx'},
 outfile:path.join(directory,'test.js'),bundle:true,jsx:'automatic',alias:{'@':path.resolve('src')},define:{'process.env.NODE_ENV':'"test"'},
});
const server=createServer(async(req,res)=>{
 if(req.url==='/test.js'){res.setHeader('Content-Type','text/javascript');res.end(await readFile(path.join(directory,'test.js')));}
 else {res.setHeader('Content-Type','text/html');res.end('<html><body><div id="root"></div><script src="/test.js"></script></body></html>');}
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,...(process.platform==='win32'?{executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'}:{})});
try{
 const page=await browser.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
 await page.goto('http://127.0.0.1:'+server.address().port);
 const input=page.getByRole('textbox',{name:'Message'});
 const credits=page.getByText('Estimated credits per recipient',{exact:true}).locator('..').locator('p').last();
 const example="The Grace, Communion & Love of God series continues this Sunday at 9AM at CE South! Come ready for an unforgettable time in God’s presence. Don’t miss it!";
 await input.fill(example);
 await page.getByRole('button',{name:'Use straight quotes',exact:true}).waitFor();
 assert.equal(await credits.innerText(),'3');
 assert.equal(await input.inputValue(),example);
 await page.getByRole('button',{name:'Use straight quotes',exact:true}).click();
 assert.equal(await credits.innerText(),'1');
 assert.ok((await input.inputValue()).includes("God's"));
 await page.getByRole('button',{name:'Undo quote change'}).click();
 assert.equal(await input.inputValue(),example);
 assert.equal(await credits.innerText(),'3');
 await page.getByRole('button',{name:'Use straight quotes',exact:true}).click();
 await page.getByRole('checkbox',{name:'Footer'}).check();
 assert.equal(await credits.innerText(),'2');
 await page.getByRole('checkbox',{name:'Footer'}).uncheck();
 await input.fill('a'.repeat(160));assert.equal(await credits.innerText(),'1');
 await input.fill('a'.repeat(161));assert.equal(await credits.innerText(),'2');
 await input.fill('😀'.repeat(36));assert.equal(await credits.innerText(),'2');
 await input.fill('');assert.equal(await credits.innerText(),'0');
 assert.deepEqual(errors,[]);
 console.log('PASS: live typing, original preserved, explicit quote replacement, undo, footer toggle, GSM boundary, emoji, empty text and no runtime exceptions');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
