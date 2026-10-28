import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
import ts from 'typescript';
const source=readFileSync(new URL('../src/lib/contact-phone-review.ts',import.meta.url),'utf8');
const js=ts.transpileModule(source,{compilerOptions:{target:ts.ScriptTarget.ES2020,module:ts.ModuleKind.ES2020}}).outputText;
const {analyzeSouthAfricanPhone:analyze,resolvedImportPhone:resolve}=await import('data:text/javascript;base64,'+Buffer.from(js).toString('base64'));
const fixtures=['0821234567','27821234567','+27821234567','082 123 4567','(082)123-4567','821234567','+27 (0) 82 123 4567','270821234567','0027821234567','','abc0821234567','08212345','08212345678','+447700900123','123456789','9.87654E+9','0821234567 ext 2','++27821234567','278212345','0','0821234567\n','０８２１２３４５６７'];
const server=JSON.parse(execFileSync('php',['api/tests/run-contact-phone-review.php','--analyze'],{input:JSON.stringify(fixtures),encoding:'utf8'}));
assert.deepEqual(fixtures.map(analyze),server);
for (const phone of fixtures.slice(0,5)) assert.equal(analyze(phone).normalized,'+27821234567');
for (const phone of fixtures.slice(5,9)) { assert.equal(analyze(phone).normalized,null); assert.equal(analyze(phone).proposed,'0821234567'); }
for (const phone of fixtures.slice(9,20)) { assert.equal(analyze(phone).normalized,null); assert.equal(analyze(phone).proposed,''); }
assert.equal(resolve('821234567'),null);
assert.equal(resolve('821234567',{action:'accept',original:'821234567',value:'0821234567'}),'+27821234567');
assert.equal(resolve('821234567',{action:'reject',original:'821234567',value:'0821234567'}),null);
assert.equal(resolve('821234567',{action:'accept',original:'821234568',value:'0821234567'}),null);
assert.equal(resolve('821234567',{action:'accept',original:'821234567',value:'821234567'}),null);
console.log('22 frontend/backend phone fixtures agree; valid formats, explicit approval, rejection and stale decisions checked.');
