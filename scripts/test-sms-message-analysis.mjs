import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import ts from 'typescript';

const source=readFileSync(new URL('../src/lib/sms-message-analysis.ts',import.meta.url),'utf8');
const js=ts.transpileModule(source,{compilerOptions:{target:ts.ScriptTarget.ES2020,module:ts.ModuleKind.ES2020}}).outputText;
const {analyzeSmsMessage,composeSmsMessage,useStraightQuotes}=await import('data:text/javascript;base64,'+Buffer.from(js).toString('base64'));
const example="The Grace, Communion & Love of God series continues this Sunday at 9AM at CE South! Come ready for an unforgettable time in God’s presence. Don’t miss it!";
const cases=[
  ['',0,'gsm7'],['a'.repeat(160),1,'gsm7'],['a'.repeat(161),2,'gsm7'],
  ['a'.repeat(306),2,'gsm7'],['a'.repeat(307),3,'gsm7'],
  ['^'.repeat(80),1,'gsm7'],['^'.repeat(81),2,'gsm7'],
  ['漢'.repeat(70),1,'ucs2'],['漢'.repeat(71),2,'ucs2'],
  ['漢'.repeat(134),2,'ucs2'],['漢'.repeat(135),3,'ucs2'],
  ['😀'.repeat(35),1,'ucs2'],['😀'.repeat(36),2,'ucs2'],
  [example,3,'ucs2'],[useStraightQuotes(example),1,'gsm7'],
  [composeSmsMessage(useStraightQuotes(example),true,'Reply STOP to unsubscribe'),2,'gsm7'],
  ['a'.repeat(159)+'€',2,'gsm7'],
  ['Hello\u00a0world',1,'ucs2'],
];
for(const [message,segments,encoding] of cases){
  const result=analyzeSmsMessage(message);
  assert.equal(result.segment_count,segments);
  assert.equal(result.encoding,encoding);
}
assert.equal(analyzeSmsMessage(example).character_count,154);
assert.equal(analyzeSmsMessage(example).segment_count*403,1209);
assert.equal(analyzeSmsMessage(useStraightQuotes(example)).segment_count*403,403);
assert.deepEqual(analyzeSmsMessage(example).unicodeCharacters,['’']);
assert.equal(useStraightQuotes('“Hi” ‘friend’ 😀'),'"Hi" \'friend\' 😀');
assert.equal(composeSmsMessage(' Hello ',true,' STOP '),'Hello\nSTOP');
assert.equal(composeSmsMessage('Hello\nSTOP',true,'STOP'),'Hello\nSTOP');
assert.equal(composeSmsMessage('',true,'STOP'),'');
assert.equal(composeSmsMessage('Hello',false,'STOP'),'Hello');
assert.equal(composeSmsMessage('\u00a0Hello\u00a0',false,''),'\u00a0Hello\u00a0');

const php=spawnSync('php',['-r', "require 'api/domain/SmsSegmentCalculator.php'; $rows=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo json_encode(array_map(fn($text)=>SmsSegmentCalculator::analyze($text),$rows));"],{input:JSON.stringify(cases.map(row=>row[0])),encoding:'utf8'});
assert.equal(php.status,0,php.stderr);
const serverResults=JSON.parse(php.stdout);
cases.forEach(([message],i)=>{
 const {encoding,character_count,unit_count,segment_count}=analyzeSmsMessage(message);
 assert.deepEqual({encoding,character_count,unit_count,segment_count},serverResults[i]);
});
console.log('PASS: 18 frontend/backend parity cases, screenshot 154 characters = 3 credits, straight quotes = 1 credit, footer = 2 credits; Unicode, emoji, extended symbols and segment boundaries');
