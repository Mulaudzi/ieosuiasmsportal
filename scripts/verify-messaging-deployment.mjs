import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
const base='https://sms.ieosuia.com';
const release=new URL(process.argv[2]||'../.sms-audit.local/messaging-release/dist/',import.meta.url);
const expectedEntry=[...(await readFile(new URL('index.html',release),'utf8')).matchAll(/src="\/assets\/(index-[^"]+\.js)"/g)][0]?.[1];
assert.ok(expectedEntry);
const pages=JSON.parse(await readFile(new URL('../src/seo/public-pages.json',import.meta.url),'utf8'));
const hash=bytes=>createHash('sha256').update(bytes).digest('hex');
for(const page of pages){
 const response=await fetch(base+page.path+'?release_check=messaging-20261003');
 assert.equal(response.status,200,page.path);
 const remote=Buffer.from(await response.arrayBuffer());
 const relative=page.path==='/'?'index.html':'public-pages'+page.path+'.html';
 const local=await readFile(new URL(relative,release));
 assert.equal(hash(remote),hash(local),'Live HTML differs: '+page.path);
}
const user=await fetch(base+'/api/auth/user');
assert.equal(user.status,401,'Unauthenticated user endpoint remains protected');
const privateResponse=await fetch(base+'/sms-campaigns/new');
assert.equal(privateResponse.status,200);
const privateHtml=await privateResponse.text();
assert.ok(privateHtml.includes(expectedEntry));
assert.match(privateHtml,/noindex/);
const home=await readFile(new URL('index.html',release),'utf8');
const assetPaths=[...new Set([...home.matchAll(/(?:src|href)="(\/assets\/[^"]+)"/g)].map(m=>m[1]))];
for(const asset of assetPaths){
 const response=await fetch(base+asset);
 assert.equal(response.status,200,asset);
 assert.equal(hash(Buffer.from(await response.arrayBuffer())),hash(await readFile(new URL(asset.slice(1),release))),asset);
}
console.log('PASS production: 16 public pages match release hashes, entry assets match, composer shell updated/noindex, unauthenticated API returns 401');
