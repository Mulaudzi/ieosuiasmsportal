import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import ts from 'typescript';
const source=readFileSync(new URL('../src/lib/delivery-time.ts',import.meta.url),'utf8');
const js=ts.transpileModule(source,{compilerOptions:{module:ts.ModuleKind.ES2020}}).outputText;
const {formatDeliveryTime}=await import('data:text/javascript;base64,'+Buffer.from(js).toString('base64'));
for(const [value,expected] of [['0s','Under 1 min'],['59.9s','Under 1 min'],['60s','1 min'],['89s','1 min'],['90s','2 min'],['359s','6 min'],['N/A','N/A'],[null,'N/A']])assert.equal(formatDeliveryTime(value),expected);
console.log('PASS: average delivery time presentation boundaries; no calculation change');
