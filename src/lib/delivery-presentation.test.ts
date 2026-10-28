import {describe,expect,it} from 'vitest';
import {deliveryChartStyle,deliveryOutcome,deliveryOutcomes,recipientDelivery} from './delivery-presentation';
describe('customer sending and delivery vocabulary',()=>{
  it('has five customer delivery outcomes with sent made explicit',()=>{
    expect(deliveryOutcomes.map(o=>o.label)).toEqual(['Delivered','Sent - Awaiting Confirmation','Sent - Confirmation Unavailable','Not Delivered','Not Sent']);
  });
  it('does not turn lookup errors into authoritative unavailability',()=>{
    expect(deliveryOutcome('sent','DLR lookup: Cannot Find Message')?.label).toBe('Sent - Awaiting Confirmation');
  });
  it('keeps failed submissions out of Not Delivered and Not Sent',()=>{
    expect(deliveryOutcome('failed',undefined,false)?.label).toBe('Failed to send');
  });
  it.each([
    ['delivered','Delivered','Delivery confirmed by the mobile network.'],
    ['sent','Sent - Awaiting Confirmation','Your SMS has been successfully sent for delivery. We are awaiting confirmation from the mobile network.'],
    ['failed','Not Delivered','The mobile network reported that your SMS could not be delivered.'],
    ['skipped','Not Sent','This SMS was excluded before sending, for example because the recipient opted out.'],
  ])('derives %s Result from the latest state, not the previous provider text',(state,label,detail)=>{
    expect(recipientDelivery({state,status:'LogicSMS delivery status: UNDELIVERED'})).toMatchObject({label,detail});
  });
  it('translates old recipient API labels during a rolling release',()=>{
    expect(recipientDelivery({status:'Delivery Confirmation Pending'}).label).toBe('Sent - Awaiting Confirmation');
    expect(recipientDelivery({status:'Not Delivered'}).detail).not.toContain('LogicSMS');
    expect(recipientDelivery({state:'failed',status:'Not Delivered',technical_status:'FAILED_TO_SEND'}).label).toBe('Failed to send');
  });
  it('uses consistent report chart labels',()=>{
    for(const outcome of deliveryOutcomes)expect(deliveryChartStyle(outcome.label)).toEqual({label:outcome.label,color:`var(--delivery-${outcome.key})`});
  });
  it('explains a verified block without inferring an opt-out or exposing provider text',()=>{
    expect(recipientDelivery({state:'failed',status:'Not Delivered',reason_code:'provider_blocked'})).toMatchObject({label:'Not Delivered',detail:'Blocked by the messaging provider or recipient restrictions. The exact reason is unavailable.'});
  });
  it.each(['delivered','sent','skipped'])('ignores a stale blocked reason for %s',(state)=>{
    expect(recipientDelivery({state,status:'stale',reason_code:'provider_blocked'})).toEqual(recipientDelivery({state,status:'stale'}));
  });
  it('does not turn failed submissions or arbitrary provider text into blocked delivery',()=>{
    expect(recipientDelivery({state:'failed',status:'Failed to send',technical_status:'FAILED_TO_SEND',reason_code:'provider_blocked'}).detail).toContain('did not accept');
    expect(recipientDelivery({state:'failed',status:'LogicSMS delivery status: BLOCKED'}).detail).toBe(deliveryOutcomes.find(o=>o.key==='failed')?.detail);
    expect(recipientDelivery({state:'failed',status:'Not Delivered',reason_code:'unverified'}).detail).not.toContain('Blocked');
  });
});
