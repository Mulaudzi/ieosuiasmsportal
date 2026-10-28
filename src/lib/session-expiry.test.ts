import {afterEach,describe,expect,it,vi} from 'vitest';
import {expireSession} from './session-expiry';
import {api,downloadAuthenticated} from './api';

afterEach(()=>{vi.unstubAllGlobals();localStorage.clear();sessionStorage.clear();});
function setup(path='/sms/create',search='?group=42'){
  const replace=vi.fn();
  vi.stubGlobal('window',{location:{pathname:path,search,origin:'https://sms.ieosuia.com',replace},dispatchEvent:vi.fn()});
  localStorage.setItem('auth_token','old');localStorage.setItem('auth_user','fixture');localStorage.setItem('auth_token_issued','123');
  localStorage.setItem('sms-campaign-draft:42','preserve me');
  return replace;
}
describe('expired session recovery',()=>{
  it.each([['text/html','<h1>Expired</h1>'],['application/json','not-json'],['application/json','{"error":"expired"}']])('redirects a %s 401 before body parsing',async(type,body)=>{
    const replace=setup();vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response(body,{status:401,headers:{'content-type':type}})));
    await expect(api.get('/sms/campaigns-v2')).rejects.toMatchObject({status:401});
    expect(replace).toHaveBeenCalledExactlyOnceWith('/login');expect(localStorage.getItem('auth_token')).toBeNull();
    expect(sessionStorage.getItem('redirectAfterLogin')).toBe('/sms/create?group=42');expect(localStorage.getItem('sms-campaign-draft:42')).toBe('preserve me');
  });
  it('also redirects authenticated export failures',async()=>{
    const replace=setup();vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response('',{status:401})));
    await expect(downloadAuthenticated('/reports/export','report.csv')).rejects.toMatchObject({status:401});expect(replace).toHaveBeenCalledWith('/login');
  });
  it('keeps admin authentication separate and redirects only once',()=>{
    const replace=setup('/guymhan','?tab=campaigns');expireSession('old');expireSession('old');expect(replace).toHaveBeenCalledExactlyOnceWith('/guymhan/login');expect(sessionStorage.getItem('redirectAfterLogin')).toBeNull();
  });
  it('does not let an old failed request log out a newer session',async()=>{
    const replace=setup();vi.stubGlobal('fetch',vi.fn().mockImplementation(async()=>{localStorage.setItem('auth_token','new');return new Response('',{status:401});}));
    await expect(api.get('/wallet')).rejects.toMatchObject({status:401});expect(replace).not.toHaveBeenCalled();expect(localStorage.getItem('auth_token')).toBe('new');
  });
  it('does not log out a public unauthenticated request',async()=>{
    const replace=setup();vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response('',{status:401})));
    await expect(api.post('/public',{},false)).rejects.toMatchObject({status:401});expect(replace).not.toHaveBeenCalled();expect(localStorage.getItem('auth_token')).toBe('old');
  });
});
