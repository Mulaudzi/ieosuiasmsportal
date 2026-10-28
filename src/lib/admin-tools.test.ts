import {describe, expect, it} from 'vitest';
import {adminLinkActive, adminToolTab, cronJobsFromResponse, loadAdminTasks} from './admin-tools';

const links = ['/guymhan', '/guymhan?tab=finance', '/guymhan/users', '/guymhan/tools?tab=users', '/guymhan/tools?tab=contact-emails', '/guymhan/tools?tab=settings#smtp-settings', '/guymhan/tools?tab=settings#notification-settings', '/guymhan/logs', '/guymhan/tools?tab=health'];
describe('admin tools navigation', () => {
  for (const href of [...links, '/guymhan/tools', '/guymhan/tools?tab=invalid', '/guymhan/tools?tab=activity', '/guymhan/tools?tab=scheduled']) {
    it(`has exactly one active menu entry for ${href}`, () => {
      const location = new URL(href, 'https://sms.ieosuia.com');
      expect(links.filter(link => adminLinkActive(link, location))).toHaveLength(1);
    });
  }
  it('defaults legacy and unknown tools routes to the user list', () => {
    expect(adminToolTab('')).toBe('users');
    expect(adminToolTab('?tab=not-a-tab')).toBe('users');
  });
  it('never treats another app path or a prefix as active', () => {
    expect(adminLinkActive('/guymhan/users', {pathname:'/guymhan/users-other', search:'', hash:''})).toBe(false);
    expect(adminLinkActive('/guymhan', {pathname:'/dashboard', search:'', hash:''})).toBe(false);
  });
});

describe('independent bounded admin loading', () => {
  it('applies successful sections despite another failure', async () => {
    const applied: string[] = [];
    const failed = await loadAdminTasks([
      {key:'summary', label:'Summary', run:async () => {throw new Error('Unavailable');}},
      {key:'users', label:'User Management', run:async () => {applied.push('users');}},
      {key:'third', label:'Third section', run:async () => {applied.push('third');}},
    ]);
    expect(applied).toEqual(['users','third']);
    expect(failed).toEqual([{key:'summary',label:'Summary',message:'Unavailable'}]);
  });
  it('never starts more than two section requests concurrently', async () => {
    let active=0, peak=0;
    const tasks=Array.from({length:7},(_,index)=>({key:String(index),label:'Fixture',run:async()=>{
      active++;peak=Math.max(peak,active);await new Promise(resolve=>setTimeout(resolve,5));active--;
    }}));
    expect(await loadAdminTasks(tasks)).toEqual([]);
    expect(peak).toBe(2);
    expect(active).toBe(0);
  });
});

describe('scheduler response safety', () => {
  it('ignores null or malformed entries while preserving valid heartbeats', () => {
    const scheduler = {job_name:'sms-scheduler',last_seen_at:'2026-10-21 08:00:00'};
    expect(cronJobsFromResponse([null, false, 42, {}, {job_name:null}, {job_name:' '}, scheduler])).toEqual([scheduler]);
  });
  it('accepts empty and missing job lists and legacy job names', () => {
    for (const value of [undefined, null, {}, [], 'invalid']) expect(cronJobsFromResponse(value)).toEqual([]);
    expect(cronJobsFromResponse([{job_name:'process_scheduled_campaigns',run_count:2}])).toHaveLength(1);
  });
});
