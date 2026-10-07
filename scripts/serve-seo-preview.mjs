import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
const root=path.resolve(process.argv[2]||'.footer-release.local/source/dist');
const pages=JSON.parse(await readFile(new URL('../src/seo/public-pages.json',import.meta.url),'utf8'));
const mime={'.html':'text/html','.js':'text/javascript','.css':'text/css','.png':'image/png','.svg':'image/svg+xml','.xml':'application/xml','.txt':'text/plain','.woff2':'font/woff2'};
const csp="default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' https://payos.ieosuia.com";
createServer(async(req,res)=>{
 try {
  const pathname=decodeURIComponent(new URL(req.url,'http://localhost').pathname);
  if(pathname==='/landing'){res.writeHead(301,{Location:'/'});res.end();return;}
  if(pathname.startsWith('/api/')){res.writeHead(401,{'Content-Type':'application/json'});res.end('{"success":false}');return;}
  const publicPage=pages.find(p=>p.path===pathname);
  const privatePage=/^\/(dashboard|sms-campaigns|email-campaigns|contacts|templates|wallet|payment|reports|settings|profile|guymhan|auth|verify-email|verify-email-reminder|forgot-password)(\/|$)/.test(pathname);
  const relative=publicPage?(pathname==='/'?'index.html':'public-pages'+pathname+'.html'):privatePage?'app.html':pathname.slice(1);
  const file=path.resolve(root,relative);if(!file.startsWith(root+path.sep))throw Error('Invalid path');
  const data=await readFile(file);res.writeHead(200,{'Content-Type':mime[path.extname(file)]||'application/octet-stream','Content-Security-Policy':csp,...(privatePage?{'X-Robots-Tag':'noindex, nofollow'}:{})});res.end(data);
 }catch{res.writeHead(404,{'Content-Type':'text/html','X-Robots-Tag':'noindex'});res.end(await readFile(path.join(root,'404.html')));}
}).listen(Number(process.env.PORT || 4185),'127.0.0.1',()=>console.log('SEO preview port '+(process.env.PORT || 4185)));
