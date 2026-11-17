// Optional private workbook regression. Prints aggregates only; never writes contacts.
import {readFileSync,mkdirSync,writeFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {execFileSync} from 'node:child_process';
import assert from 'node:assert/strict';
import ts from 'typescript';
import readExcelFile from 'read-excel-file/universal';
const path=process.argv[2];assert.ok(path,'Pass a local .xlsx path; the source workbook stays unchanged.');
async function source(name){const raw=readFileSync(new URL('../src/lib/'+name+'.ts',import.meta.url),'utf8');const js=ts.transpileModule(raw,{compilerOptions:{target:ts.ScriptTarget.ES2020,module:ts.ModuleKind.ES2020}}).outputText;return import('data:text/javascript;base64,'+Buffer.from(js).toString('base64'));}
const {prepareContactSheet,contactSheetHasHeaders,contactHeaderField}=await source('contact-import-file');
const {analyzeSouthAfricanPhone,resolvedImportPhone}=await source('contact-phone-review');
const original=readFileSync(path);const sheets=await readExcelFile(Uint8Array.from(original).buffer,{parseNumber:value=>value});
const summaries=[];
for(const sheet of sheets){
 const prepared=prepareContactSheet(sheet.data,contactSheetHasHeaders(sheet.data));
 const column=prepared.headers.findIndex(header=>contactHeaderField(header)==='phone');
 assert.ok(column>=0,'Contact column detected');assert.equal(prepared.headerRowNumber,2,'Title row is not mistaken for headings');
 const phones=prepared.data.map(row=>row[column]);const decisions=[];let normalized=0,proposed=0,needsManual=0;
 phones.forEach((phone,index)=>{const analysis=analyzeSouthAfricanPhone(phone);if(analysis.normalized){normalized++;return;}
  if(analysis.proposed&&analyzeSouthAfricanPhone(analysis.proposed).normalized){proposed++;decisions.push({row:index+2,original:phone,action:'accept',value:analysis.proposed});}
  else{needsManual++;decisions.push({row:index+2,original:phone,action:'reject'});}
 });
 const backend=JSON.parse(execFileSync('php',['api/tests/run-contact-phone-review.php','--prepare'],{input:JSON.stringify({headers:prepared.headers.map(header=>header.toLowerCase()),rows:prepared.data,phoneColumn:prepared.headers[column],decisions}),encoding:'utf8',windowsHide:true}));
 const resolved=phones.map((phone,index)=>resolvedImportPhone(phone,decisions.find(decision=>decision.row===index+2)));
 resolved.forEach((phone,index)=>assert.equal(backend[index+2]?.phone??null,phone,'Original worksheet/CSV row decisions match PHP'));
 summaries.push({sheet:sheet.sheet,heading_row:prepared.headerRowNumber,source_rows:prepared.data.length,already_valid:normalized,safe_proposals:proposed,manual_or_reject:needsManual,unique_after_approved_proposals:new Set(resolved.filter(Boolean)).size});
}
assert.deepEqual(readFileSync(path),original,'Original workbook bytes preserved');
if(sheets.some(sheet=>sheet.sheet==='Main DB')){
 const main=summaries.find(sheet=>sheet.sheet==='Main DB');assert.equal(main.source_rows,61);assert.equal(main.safe_proposals,47);assert.equal(main.already_valid,14);assert.equal(main.manual_or_reject,0);
}
const result={passed:true,source_unchanged:true,source_sha256:createHash('sha256').update(original).digest('hex'),contacts_saved:0,sheets:summaries};
const output='.sms-audit.local/etwatwa-import-20261029';mkdirSync(output,{recursive:true});writeFileSync(output+'/workbook-results.json',JSON.stringify(result,null,2));
console.log(JSON.stringify(result,null,2));
