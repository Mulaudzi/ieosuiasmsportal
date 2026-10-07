import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import ts from 'typescript';

const source=readFileSync(new URL('../src/lib/delivery-presentation.ts',import.meta.url),'utf8');
const js=ts.transpileModule(source,{compilerOptions:{target:ts.ScriptTarget.ES2020,module:ts.ModuleKind.ES2020}}).outputText;
const {deliveryOutcomes,deliveryOutcome,deliveryChartStyle}=await import('data:text/javascript;base64,'+Buffer.from(js).toString('base64'));
assert.deepEqual(deliveryOutcomes.map(o=>o.label),['Delivered','Delivery Confirmation Pending','Delivery Status Unavailable','Not Delivered','Skipped']);
for(const [state,index] of [['delivered',0],['sent',1],['dlr_unavailable',2],['failed',3],['skipped',4]])assert.equal(deliveryOutcome(state),deliveryOutcomes[index]);
const oldError='DLR lookup: Cannot Find Message';
assert.equal(deliveryOutcome('sent',oldError),deliveryOutcomes[2]);
for(const [state,index] of [['delivered',0],['failed',3],['skipped',4]]){
 const current=deliveryOutcome(state,oldError);
 assert.equal(current.label,deliveryOutcomes[index].label);
 assert.equal(current.detail,deliveryOutcomes[index].detail);
}
for(const state of ['pending','queued','processing','retry'])assert.equal(deliveryOutcome(state),undefined,'Unsent messages are not delivery confirmation pending');
for(const alias of ['Confirmation Unavailable','confirmation unavailable','Delivery Status Unavailable'])assert.equal(deliveryChartStyle(alias).label,'Delivery Status Unavailable');
assert.equal(deliveryChartStyle('Awaiting Confirmation').label,'Delivery Confirmation Pending');
assert.equal(deliveryChartStyle('Failed').label,'Not Delivered');
assert.equal(deliveryChartStyle('Pending').label,'Pending','Ambiguous pending must not imply successfully sent');
console.log('PASS: five outcomes, legacy alias normalization, current-state detail transitions, unavailable is not failure, unsent states remain distinct');
