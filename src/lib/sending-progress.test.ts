import {expect,it} from 'vitest';
import {sendingProgress} from './sending-progress';
it.each([
  ['queued',0,0,'queued',false],['pending',0,0,'queued',false],['processing',0,0,'sending',true],
  ['processing',40,0,'sending',true],['processing',40,60,'held',false],['scheduled',0,0,'scheduled',false],
  ['draft',0,0,'draft',false],['cancelled',40,0,'cancelled',false],['completed',40,0,'reconciling',false],
]as const)('presents %s with %i sent and %i held honestly',(state,sent,held,kind,active)=>{
  expect(sendingProgress({state,total:100,sent,held})).toMatchObject({kind,active,percent:sent});
});
it('keeps exclusions and failed submissions separate from provider-accepted SMS',()=>{
  expect(sendingProgress({total:100,sent:50,excluded:10,failed:5,held:15})).toMatchObject({processed:65,waiting:35,waitingToSend:20,held:15,percent:65});
});
it('never rounds an incomplete campaign up to 100%',()=>{
  expect(sendingProgress({total:10000,sent:9999})).toMatchObject({percent:99,finished:false,waiting:1});
});
it('finishes only when all submission results are accounted for',()=>{
  expect(sendingProgress({total:10,sent:8,excluded:2})).toMatchObject({kind:'complete',finished:true,percent:100});
  expect(sendingProgress({total:10,sent:8,failed:2})).toMatchObject({kind:'issues',finished:true,percent:100});
});
it('does not claim acceptance if every recipient was excluded',()=>{
  expect(sendingProgress({total:10,sent:0,excluded:10})).toMatchObject({kind:'issues',sent:0,percent:100});
});
it('bounds malformed snapshots without NaN or negative progress',()=>{
  expect(sendingProgress({total:NaN,sent:-10,held:Infinity})).toMatchObject({kind:'empty',percent:0,held:0});
  expect(sendingProgress({total:10,sent:20,held:8})).toMatchObject({percent:100,waiting:0,held:0});
});
it.each(['queued','pending','processing'])('identifies the first-result waiting phase for %s',state=>{
  expect(sendingProgress({state,total:100,sent:0})).toMatchObject({preparing:true,percent:0,sent:0});
  expect(sendingProgress({state,total:100,sent:0,excluded:4})).toMatchObject({preparing:true,percent:4,sent:0});
  expect(sendingProgress({state,total:100,sent:1})).toMatchObject({preparing:false,percent:1});
  expect(sendingProgress({state,total:100,sent:0,failed:1})).toMatchObject({preparing:false,percent:1});
});
it.each(['scheduled','draft','cancelled','completed','failed'])('never invents preparation for %s',state=>{
  expect(sendingProgress({state,total:100,sent:0}).preparing).toBe(false);
});
it('does not disguise uncertain or empty results as preparation',()=>{
  expect(sendingProgress({state:'processing',total:4,sent:0,held:4}).preparing).toBe(false);
  expect(sendingProgress({state:'processing',total:0,sent:0}).preparing).toBe(false);
});
