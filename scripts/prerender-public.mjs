// One allowlist and one rendered UI for visitors and crawlers. No authenticated snapshots.
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile, writeFile, mkdir, access, unlink } from 'node:fs/promises';
import path from 'node:path';
const root = path.resolve(process.argv[2] || 'dist');
const pages = JSON.parse(await readFile(new URL('../src/seo/public-pages.json', import.meta.url), 'utf8'));
const entry = await readFile(path.join(root, 'index.html'), 'utf8');
if (!entry.includes('<div id="root"></div>')) throw Error('Build fresh Vite output before prerendering');
await unlink(path.join(root, 'favicon.ico')).catch(e => { if(e.code !== 'ENOENT') throw e; });
await mkdir(path.join(root, 'public-pages'), { recursive:true });
const mime = { '.js':'text/javascript','.css':'text/css','.png':'image/png','.svg':'image/svg+xml','.html':'text/html','.json':'application/json','.woff2':'font/woff2' };
const server = createServer(async(req,res) => {
 try {
  const pathname = decodeURIComponent(new URL(req.url,'http://localhost').pathname);
  if(pathname.startsWith('/api/')) {res.writeHead(403);res.end();return;}
  if(pages.some(p=>p.path===pathname) || pathname==='/not-found-seo-check') {res.writeHead(200,{'Content-Type':'text/html'});res.end(entry);return;}
  const file = path.resolve(root,'.'+pathname);
  if(!file.startsWith(root+path.sep)) throw Error('Invalid path');
  res.writeHead(200,{'Content-Type':mime[path.extname(file)]||'application/octet-stream'});res.end(await readFile(file));
 } catch {res.writeHead(404);res.end();}
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const origin='http://127.0.0.1:'+server.address().port;
let browser;
try {
 let executablePath=process.env.PRERENDER_BROWSER;
 if(!executablePath && process.platform==='win32'){const candidate='C:/Program Files/Google/Chrome/Application/chrome.exe';try{await access(candidate);executablePath=candidate;}catch{}}
 browser=await chromium.launch({headless:true,...(executablePath?{executablePath}:{})});
 const context=await browser.newContext({viewport:{width:1440,height:1000}});
 await context.addInitScript(()=>{localStorage.setItem('ieosuia_cookie_consent','{}');window.__IEOSUIA_PUBLIC_PRERENDER__=true;});
 const page=await context.newPage();
 const failures=[]; page.on('pageerror',e=>failures.push(e.message));
 await page.route('**/*',route=>{const url=new URL(route.request().url());if(url.origin!==origin || url.pathname.startsWith('/api/')){failures.push('Unexpected request '+url.pathname);return route.abort();}return route.continue();});
 for(const item of pages){
  await page.goto(origin+item.path,{waitUntil:'networkidle'});
  await page.locator('h1').waitFor();
  await page.waitForFunction(expected=>document.title===expected,item.title);
  if(await page.locator('h1').count()!==1 || await page.locator('main').count()!==1) throw Error('Invalid landmarks '+item.path);
  if(failures.length)throw Error(failures.join('\n'));
  const content=await page.locator('#root').innerHTML();
  if(content.length<2000 || /fixture@|sourceMappingURL|lovable/i.test(content))throw Error('Invalid snapshot '+item.path);
  let head=await page.locator('head').innerHTML();
  for (const [key, name] of [['GOOGLE_SITE_VERIFICATION','google-site-verification'],['BING_SITE_VERIFICATION','msvalidate.01']]) {
   const token=process.env[key];
   if(token){if(!/^[a-zA-Z0-9_-]+$/.test(token))throw Error('Invalid verification token '+key);head+=`<meta name="${name}" content="${token}">`;}
  }
  const schema=await page.locator('script[data-public-schema]').textContent();JSON.parse(schema);
  let html=entry.replace(/<head>[\s\S]*?<\/head>/,()=>'<head>'+head+'</head>').replace('<div id="root"></div>',()=>'<div id="root">'+content+'</div>');
  // Never serialize build-machine origins into crawler HTML.
  html=html.replaceAll(origin,'https://sms.ieosuia.com');
  await writeFile(path.join(root,item.path==='/'?'index.html':'public-pages'+item.path+'.html'),html);
  console.log('PRERENDERED '+item.path+' '+Buffer.byteLength(html)+' bytes');
 }
 const shell=entry.replace(/\s*<link rel="canonical"[^>]*>/g,'').replace(/\s*<meta property="og:[^"]+"[^>]*>/g,'').replace(/\s*<meta name="twitter:[^"]+"[^>]*>/g,'').replace(/<title>.*?<\/title>/,'<title>Account | IEOSUIA SMS Portal</title>').replace('</head>','<meta name="robots" content="noindex,nofollow"></head>');
 await writeFile(path.join(root,'app.html'),shell);
 await writeFile(path.join(root,'404.html'),'<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Page not found | IEOSUIA SMS Portal</title></head><body><main><h1>Page not found</h1><p>The requested page does not exist.</p><a href="/">Return to IEOSUIA SMS Portal</a></main></body></html>');
 const sitemap='<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'+pages.map(p=>'  <url><loc>https://sms.ieosuia.com'+p.path+'</loc></url>').join('\n')+'\n</urlset>\n';
 await writeFile(path.join(root,'sitemap.xml'),sitemap);
 await writeFile(path.join(root,'llms.txt'),'# IEOSUIA SMS Portal\n\n> South African business SMS campaigns, contact groups, scheduling, delivery reporting and prepaid credits. Email Campaigns are Coming Soon.\n\n## Public product and support pages\n'+pages.map(p=>'- ['+p.name+'](https://sms.ieosuia.com'+p.path+'): '+p.description).join('\n')+'\n');
 await page.setViewportSize({width:1200,height:630});
 await page.setContent(`<html><body style="margin:0;background:#111827;color:white;font-family:Arial,sans-serif;padding:56px 64px;box-sizing:border-box;width:1200px;height:630px"><img src="${origin}/branding/ieosuia-sms-portal-navy.png" width="330" height="110" alt="IEOSUIA SMS Portal"><h1 style="font-size:62px;line-height:1.08;letter-spacing:-2px;margin:28px 0 22px">Build audiences.<br>Send campaigns.<br><span style="color:#55dcf4">Track delivery.</span></h1><p style="font-size:24px;color:#c8d6e6">Business SMS for South Africa.</p><p style="font-size:20px;color:#c8d6e6">sms.ieosuia.com</p></body></html>`);
 await page.locator('img').evaluate(img=>img.decode());
 await page.screenshot({path:path.join(root,'social-preview-communications.png')});
 console.log('Public snapshots, private noindex shell, 404, sitemap, llms.txt and branded social preview generated.');
} finally {if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
