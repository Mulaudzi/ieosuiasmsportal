import {chromium} from 'playwright';
import {readFile,writeFile} from 'node:fs/promises';
import AxeBuilder from '../.sms-audit.local/seo-tools/node_modules/@axe-core/playwright/dist/index.mjs';
const base=process.argv[2]||'http://127.0.0.1:4185';
const pages=JSON.parse(await readFile(new URL('../src/seo/public-pages.json',import.meta.url),'utf8'));
const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
const results=[];
try{const context=await browser.newContext({viewport:{width:390,height:900}});const page=await context.newPage();if(!process.env.TEST_FRESH_VISITOR)await page.addInitScript(()=>localStorage.setItem('ieosuia_cookie_consent','{}'));
for(const item of pages){await page.goto(base+item.path,{waitUntil:'networkidle'});if(process.env.TEST_FRESH_VISITOR)await page.getByRole('button',{name:'Accept All',exact:true}).waitFor();const result=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();results.push({path:item.path,violations:result.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))}))});console.log(item.path+' '+result.violations.map(v=>v.id).join(','));}
}finally{await writeFile('.sms-audit.local/seo-accessibility.json',JSON.stringify(results,null,2));await browser.close();}
