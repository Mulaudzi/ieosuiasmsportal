import {describe,expect,it} from 'vitest';
import {smsSubmissionTiming} from './sms-schedule';

describe('SMS schedule intent',()=>{
  it.each(['','   '])('sends immediately only when the schedule is blank',value=>{
    expect(smsSubmissionTiming(value)).toEqual({send_now:true,scheduled_at:null});
  });
  it('preserves the selected local instant as offset-aware ISO',()=>{
    const value='2027-01-01T11:00';
    expect(smsSubmissionTiming(value,Date.parse('2026-01-01T00:00:00Z'))).toEqual({send_now:false,scheduled_at:new Date(value).toISOString()});
  });
  it.each(['tomorrow','2027-02-30T11:00','2027-01-01T25:00','2027-01-01T11:60','2027-01-01','2027-01-01T11:00+02:00'])('rejects invalid picker values rather than falling back to immediate sending: %s',value=>{
    expect(()=>smsSubmissionTiming(value,0)).toThrow('valid schedule');
  });
  it('rejects elapsed times and the exact current instant',()=>{
    const value='2027-01-01T11:00';const now=new Date(value).getTime();
    expect(()=>smsSubmissionTiming(value,now)).toThrow('future');
    expect(()=>smsSubmissionTiming(value,now+1)).toThrow('future');
    expect(smsSubmissionTiming(value,now-1).send_now).toBe(false);
  });
  it('preserves leap-day dates and seconds',()=>{
    const value='2028-02-29T11:00:59';
    expect(smsSubmissionTiming(value,0).scheduled_at).toBe(new Date(value).toISOString());
  });
});
