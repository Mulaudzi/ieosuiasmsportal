import { describe, expect, it } from 'vitest';
import { dashboardDelivery, deliveryVisibility } from './delivery-visibility';

describe('delivery summary visibility',()=>{
  it.each([
    [[0,10,0,0,0],10],[[7,3,0,0,0],10],[[7,0,3,0,0],10],
    [[3,2,2,1,1],10],[[3,0,0,1,1],10],[[0,0,0,0,0],10],
  ] as [number[],number][])('never calls incomplete outcomes final: %j', (counts,total)=>{
    expect(deliveryVisibility(counts,total).complete).toBe(false);
  });
  it.each([
    [[10,0,0,0,0],10],[[6,0,0,3,1],10],[[0,0,0,10,0],10],[[0,0,0,0,10],10],
  ] as [number[],number][])('reveals final outcomes: %j',(counts,total)=>{
    expect(deliveryVisibility(counts,total).complete).toBe(true);
  });
  it.each([
    [[10,0,0,0,0],9],[[10,0,0,0,0],11],[[1,0,0,-1,0],0],
    [[NaN,0,0,0,0],1],[[1,0,0,0],1],[[1.5,0,0,0,0],1.5],
  ] as [number[],number][])('fails closed on inconsistent or missing counters: %j',(counts,total)=>{
    expect(deliveryVisibility(counts,total).complete).toBe(false);
  });
  it('does not present unavailable as a final failure',()=>{
    const result=deliveryVisibility([0,0,10,0,0],10);
    expect(result.complete).toBe(false);expect(result.unconfirmed).toBe(10);
    expect(result.message).toContain('does not necessarily mean that the message was not delivered');
    expect(result.available).toBe(true);
  });
  it('does not say queued messages have been sent',()=>{
    const result=deliveryVisibility([0,0,0,0,0],10);
    expect(result.waitingToSend).toBe(10);expect(result.title).toBe('Waiting for sending to finish');
  });
  it('does not display a breakdown for an empty period',()=>{
    expect(deliveryVisibility([0,0,0,0,0],0)).toMatchObject({complete:false,empty:true});
  });
  it('includes skipped recipients without changing the canonical sent denominator',()=>{
    const summary={total_sent:9,total_delivered:6,total_failed:3,total_pending:0,total_awaiting_delivery:0,total_dlr_unavailable:0,total_skipped:1,delivery_rate:66.7};
    expect(dashboardDelivery(summary)).toMatchObject({complete:true,total:10});
    expect(summary.total_sent).toBe(9);expect(summary.delivery_rate).toBe(66.7);
  });
  it('reveals a confirmed result immediately while other messages remain pending',()=>{
    expect(deliveryVisibility([1,9,0,0,0],10)).toMatchObject({available:true,complete:false,unconfirmed:9});
  });
  it('keeps failed submissions separate from waiting and delivery failures',()=>{
    expect(deliveryVisibility([1,0,0,0,1],3,1)).toMatchObject({available:true,complete:true,waitingToSend:0});
  });
});
