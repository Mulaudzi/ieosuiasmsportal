import {afterEach,describe,expect,it,vi} from 'vitest';
import {api} from './api';
import { ApiError, guardedReadPolling, isAccessBlocked } from './api-error';

afterEach(()=>vi.unstubAllGlobals());
describe('hosting failure responses',()=>{
  for(const status of [500,503,507]) {
    it(`never exposes an HTML ${status} error page to the user`,async()=>{
      vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response('<!DOCTYPE html><h1>Internal hosting page</h1>',{status,headers:{'content-type':'text/html'}})));
      const error=await api.get('/admin/users').catch(error=>error);
      expect(error).toBeInstanceOf(Error);
      expect(error.message).not.toContain('<');
      expect(error.message).not.toContain('Internal hosting page');
      expect(error.message).toMatch(status===507?/storage or resource limit/:status===503?/temporarily unavailable/:/server error/);
    });
  }
  it('shows a safe message for malformed JSON from an unavailable server',async()=>{
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response('<html>Bad gateway</html>',{status:503,headers:{'content-type':'application/json'}})));
    await expect(api.get('/admin/users')).rejects.toThrow('temporarily unavailable');
  });
  it('preserves successful JSON data',async()=>{
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response(JSON.stringify({success:true,users:[{id:'fixture'}]}),{headers:{'content-type':'application/json'}})));
    expect(await api.get('/admin/users')).toEqual({success:true,users:[{id:'fixture'}]});
  });
  it('passes cancellation through for obsolete section requests',async()=>{
    const fetchMock=vi.fn().mockResolvedValue(new Response('{"success":true}',{headers:{'content-type':'application/json'}}));
    vi.stubGlobal('fetch',fetchMock);
    const controller=new AbortController();
    await api.get('/admin/users',undefined,{signal:controller.signal});
    expect(fetchMock.mock.calls[0][1].signal).toBe(controller.signal);
  });
});

describe('access rejection handling', () => {
  it('identifies an Imunify360 JSON block without clearing the signed-in session', async () => {
    localStorage.setItem('auth_token','fixture-preserved');
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response(JSON.stringify({message:'Access denied by Imunify360 bot-protection. IPs used for automation should be whitelisted'}),{status:403,headers:{'content-type':'application/json'}})));
    const error=await api.get('/notifications').catch(error=>error);
    expect(error).toBeInstanceOf(ApiError);expect(error.status).toBe(403);expect(error.kind).toBe('hosting-blocked');
    expect(error.message).toContain('Hosting security');expect(localStorage.getItem('auth_token')).toBe('fixture-preserved');
    localStorage.clear();
  });
  it('identifies an HTML verification page even when hosting responds with 200', async () => {
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response('<html><title>One moment, please...</title></html>',{headers:{'content-type':'text/html'}})));
    await expect(api.get('/notifications')).rejects.toMatchObject({kind:'hosting-blocked',status:200});
  });
  it('does not treat an actual portal permission error as a hosting block', async () => {
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response('{"success":false,"message":"Unauthorized"}',{status:403,headers:{'content-type':'application/json'}})));
    await expect(api.get('/admin/operations/payments')).rejects.toMatchObject({kind:'permission',status:403});
  });
  it('does not mistake successful notification data mentioning hosting for a block', async () => {
    const payload={success:true,notifications:[{message:'Imunify360 configuration reviewed'}]};
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response(JSON.stringify(payload),{headers:{'content-type':'application/json'}})));
    expect(await api.get('/notifications')).toEqual(payload);
  });
  it('stops automatic retries, timers, focus and reconnect refetches after access rejection', () => {
    for(const error of [new ApiError('permission',403,'permission'),new ApiError('hosting',403,'hosting-blocked'),new ApiError('challenge',200,'hosting-blocked'),new ApiError('expired',401)]){
      expect(isAccessBlocked(error)).toBe(true);expect(guardedReadPolling.retry(0,error)).toBe(false);
      const query={state:{error}};
      expect(guardedReadPolling.refetchInterval(query)).toBe(false);
      expect(guardedReadPolling.refetchOnWindowFocus(query)).toBe(false);
      expect(guardedReadPolling.refetchOnReconnect(query)).toBe(false);
    }
  });
  it('keeps ordinary background refresh and transient-error retries working', () => {
    for(const error of [null,new Error('network'),new ApiError('temporary',503)]){
      const query={state:{error}};
      expect(guardedReadPolling.refetchInterval(query)).toBe(30000);
      expect(guardedReadPolling.refetchOnWindowFocus(query)).toBe(true);
      expect(guardedReadPolling.retry(0,error)).toBe(true);
    }
    expect(guardedReadPolling.retry(3,new Error('network'))).toBe(false);
  });
});
