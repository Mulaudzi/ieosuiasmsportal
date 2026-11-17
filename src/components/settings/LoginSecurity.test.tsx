import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { LoginSecurity } from './LoginSecurity';
const mocks=vi.hoisted(()=>({get:vi.fn(),post:vi.fn()}));
vi.mock('@/lib/api',()=>({api:mocks}));vi.mock('@/hooks/useAuth',()=>({useAuth:()=>({user:{id:'fixture'}})}));
const clients:QueryClient[]=[];const ready={success:true,available:true,enrollment_enabled:true,enabled:false,recovery_codes_remaining:0};
const mount=()=>{const client=new QueryClient({defaultOptions:{queries:{retry:false}}});clients.push(client);render(<QueryClientProvider client={client}><LoginSecurity/></QueryClientProvider>);};
beforeEach(()=>{mocks.get.mockReset();mocks.post.mockReset();mocks.get.mockResolvedValue(ready);});afterEach(()=>{cleanup();clients.forEach(client=>client.clear());clients.length=0;});
it('makes the app-only scope and email-code limitation clear',async()=>{mount();await screen.findByRole('button',{name:'Enable extra sign-in check'});expect(screen.getByText(/not your other IEOSUIA apps/)).toBeVisible();expect(screen.getByText(/not phishing-resistant/)).toBeVisible();expect(mocks.post).not.toHaveBeenCalled();});
it('shows pending setup honestly when enrollment is disabled',async()=>{mocks.get.mockResolvedValue({...ready,enrollment_enabled:false});mount();expect(await screen.findByText(/awaiting secure setup/)).toBeVisible();expect(screen.queryByRole('button',{name:'Enable extra sign-in check'})).toBeNull();});
it('requires explicit email confirmation before enabling, then shows recovery codes once',async()=>{
 mocks.post.mockResolvedValueOnce({success:true,challenge:'a'.repeat(64)}).mockResolvedValueOnce({success:true,token:'fixture-new-token',user:{id:'fixture'},recovery_codes:['aaaa-bbbb-cccc-dddd-eeee']});
 mount();fireEvent.click(await screen.findByRole('button',{name:'Enable extra sign-in check'}));const input=await screen.findByRole('textbox',{name:'Security verification code'});
 expect(screen.getByRole('button',{name:'Confirm enable'})).toBeDisabled();fireEvent.change(input,{target:{value:'123456'}});fireEvent.click(screen.getByRole('button',{name:'Confirm enable'}));
 expect(await screen.findByText('aaaa-bbbb-cccc-dddd-eeee')).toBeVisible();expect(screen.getByRole('button',{name:'Done'})).toBeDisabled();
 expect(mocks.post).toHaveBeenLastCalledWith('/settings/login-security/confirm',{purpose:'enable',challenge:'a'.repeat(64),code:'123456'});expect(localStorage.getItem('auth_token')).toBe('fixture-new-token');
 fireEvent.click(screen.getByRole('checkbox'));expect(screen.getByRole('button',{name:'Done'})).toBeEnabled();
});
it('lets enabled customers request disabling even when enrollment is closed',async()=>{
 mocks.get.mockResolvedValue({...ready,enrollment_enabled:false,enabled:true,recovery_codes_remaining:7});mocks.post.mockResolvedValue({success:true,challenge:'b'.repeat(64)});
 mount();fireEvent.click(await screen.findByRole('button',{name:'Disable extra sign-in check'}));await screen.findByRole('textbox',{name:'Security verification code'});
 expect(mocks.post).toHaveBeenCalledWith('/settings/login-security/request',{purpose:'disable'});
});
