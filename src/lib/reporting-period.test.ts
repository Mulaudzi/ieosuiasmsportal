import { describe, expect, it } from 'vitest';
import { defaultDashboardPeriod, periodLabel, validateCustomPeriod } from './reporting-period';
import { campaignStatus } from './campaign-status';

describe('dashboard reporting period',()=>{
 it('starts with last 30 days',()=>expect(defaultDashboardPeriod).toEqual({range:'30d'}));
 it('supports same-day and leap-day ranges',()=>{
  expect(validateCustomPeriod('2026-10-21','2026-10-21')).toBeNull();
  expect(validateCustomPeriod('2024-02-29','2024-02-29')).toBeNull();
  expect(validateCustomPeriod('2024-01-01','2024-12-31')).toBeNull();
 });
 it.each([['','2026-10-21'],['2026-02-29','2026-03-01'],['2026-02-30','2026-03-01'],['2026-10-22','2026-10-21'],['2024-01-01','2025-01-01'],['10/01/2026','2026-10-21']])('rejects invalid dates %s through %s',(from,to)=>expect(validateCustomPeriod(from,to)).not.toBeNull());
 it('labels applied dates explicitly',()=>expect(periodLabel({range:'custom',from:'2026-10-01',to:'2026-10-21'})).toBe('2026-10-01 – 2026-10-21'));
});
describe('campaign status derives submission success',()=>{
 it('does not call legacy network failures failed submissions',()=>{
  expect(campaignStatus({state:'partially_failed',recipient_count:403,sent_count:403,failed_submission_count:0}).label).toBe('Completed');
 });
 it('only flags explicit failed submissions',()=>{
  expect(campaignStatus({state:'completed',recipient_count:403,sent_count:402,failed_submission_count:1}).label).toBe('Submission issues');
 });
 it('does not infer acceptance from a legacy status alone',()=>expect(campaignStatus({state:'partially_failed'}).label).toBe('Sending finished'));
 it('preserves active and cancelled lifecycle states',()=>{
  expect(campaignStatus({state:'processing',recipient_count:403,sent_count:403,failed_submission_count:0}).label).toBe('Processing');
  expect(campaignStatus({state:'cancelled'}).label).toBe('Cancelled');
 });
});
