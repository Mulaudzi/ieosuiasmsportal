import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { CustomerCreditTransfer } from './CustomerCreditTransfer';
import { CreditTransferPanel } from './CreditTransferPanel';
import { CreditTransferHistory } from './CreditTransferHistory';
import { ApiError } from '@/lib/api-error';
const mocks=vi.hoisted(()=>({get:vi.fn(),post:vi.fn()}));
vi.mock('@/lib/api',()=>({api:{get:mocks.get,post:mocks.post}}));
vi.mock('@/hooks/useAuth',()=>({useAuth:()=>({user:{id:'2',name:'Source',email:'source@example.invalid'}})}));
vi.mock('@/hooks/use-toast',()=>({toast:vi.fn()}));
const id='a'.repeat(32);
const quote={id,source:{id:'2',name:'Source',email:'source@example.invalid'},destination:{id:'recipient',name:'Recipient',email:'recipient@example.invalid'},credits:5,amount:'1.7500',unit_amount:'0.3500',currency:'ZAR',reason:'Customer-authorized credit transfer',source_available_credits:80,source_available_after:75,expires_at:Math.floor(Date.now()/1000)+600};
const receipt={id,reference:'TRF-'+id.toUpperCase(),credits:5,source_name:'Source',source_email:'source@example.invalid',destination_name:'Recipient',destination_email:'recipient@example.invalid',status:'completed',created_at:'2026-10-29 12:00:00'};
function wrap(node:React.ReactNode){return render(<QueryClientProvider client={new QueryClient({defaultOptions:{queries:{retry:false},mutations:{retry:false}}})}>{node}</QueryClientProvider>);}
beforeEach(()=>{
  vi.clearAllMocks();sessionStorage.clear();
  vi.stubGlobal('ResizeObserver',class {observe=vi.fn();unobserve=vi.fn();disconnect=vi.fn();});
  mocks.get.mockImplementation(async(path:string)=>({success:true,data:path.endsWith('/'+id)?{status:'not_completed',quote,new_review_allowed:false}:{enabled:true,transfers:[],total:0,page:1,last_page:1,security:{configured:true}}}));
  mocks.post.mockImplementation(async(path:string)=>({success:true,data:path.endsWith('/quote')?{quote}:path.endsWith('/commit')?{transfer:receipt}:{code_requested:true}}));
});
afterEach(()=>{cleanup();vi.unstubAllGlobals();});
async function reviewCustomer(){
  wrap(<CustomerCreditTransfer availableCredits={80}/>);
  const start=await screen.findByRole('button',{name:'Transfer credits'});await waitFor(()=>expect(start).toBeEnabled());fireEvent.click(start);
  fireEvent.change(screen.getByLabelText('Recipient account email'),{target:{value:'recipient@example.invalid'}});
  fireEvent.change(screen.getByLabelText('Credits to transfer'),{target:{value:'5'}});
  fireEvent.click(screen.getByRole('button',{name:'Review transfer'}));
  await screen.findByRole('heading',{name:'Review credit transfer'});
}
it('opening a transfer is read-only; invalid and fractional counts cannot continue',async()=>{
  wrap(<CustomerCreditTransfer availableCredits={80}/>);const start=await screen.findByRole('button',{name:'Transfer credits'});await waitFor(()=>expect(start).toBeEnabled());fireEvent.click(start);
  expect(mocks.post).not.toHaveBeenCalled();fireEvent.change(screen.getByLabelText('Recipient account email'),{target:{value:'recipient@example.invalid'}});
  for(const value of ['0','-1','1.5','1e2','1000001']){fireEvent.change(screen.getByLabelText('Credits to transfer'),{target:{value}});expect(screen.getByRole('button',{name:'Review transfer'})).toBeDisabled();}
});
it('reviews only the sender intent and never exposes a destination balance',async()=>{
  await reviewCustomer();expect(mocks.post).toHaveBeenCalledTimes(1);
  expect(mocks.post).toHaveBeenCalledWith('/wallet/credit-transfers/quote',{recipient_email:'recipient@example.invalid',credits:5,authorization_confirmed:true,request_id:expect.any(String)});
  expect(screen.queryByText(/Destination available/)).not.toBeInTheDocument();expect(screen.getByRole('button',{name:'Confirm transfer'})).toBeDisabled();
  expect(screen.getByRole('button',{name:'Back'})).toBeEnabled();
});
it('sends a code only on request, confirms with that code, and clears it on success',async()=>{
  await reviewCustomer();fireEvent.click(screen.getByRole('button',{name:'Email me a verification code'}));
  await screen.findByRole('button',{name:'Code requested — check your email'});expect(mocks.post).toHaveBeenCalledWith('/wallet/credit-transfers/challenge',{quote_id:id});
  fireEvent.change(screen.getByLabelText('Verification code'),{target:{value:'123456'}});expect(screen.getByRole('button',{name:'Confirm transfer'})).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox'));fireEvent.click(screen.getByRole('button',{name:'Confirm transfer'}));await screen.findByRole('heading',{name:'Transfer completed'});
  expect(mocks.post).toHaveBeenCalledWith('/wallet/credit-transfers/commit',{quote_id:id,verification_code:'123456',confirmed:true});expect(sessionStorage.length).toBe(0);expect(screen.queryByLabelText('Verification code')).not.toBeInTheDocument();
});
it('holds an uncertain result, blocks a new intent and restores the exact review after reopening',async()=>{
  mocks.post.mockImplementation(async(path:string)=>{if(path.endsWith('/commit'))throw new ApiError('Connection interrupted',503);return {success:true,data:{quote}};});
  await reviewCustomer();fireEvent.click(screen.getByRole('checkbox'));fireEvent.change(screen.getByLabelText('Verification code'),{target:{value:'123456'}});fireEvent.click(screen.getByRole('button',{name:'Confirm transfer'}));
  await screen.findByText('Connection interrupted');expect(screen.getByRole('button',{name:'Back'})).toBeDisabled();expect(screen.getByLabelText('Verification code')).toHaveValue('');
  expect(sessionStorage.getItem('sms-credit-transfer:pending:customer:2')).toBe(id);expect(JSON.stringify(sessionStorage)).not.toContain('123456');
  fireEvent.click(screen.getByRole('button',{name:'Close'}));fireEvent.click(screen.getByRole('button',{name:'Transfer credits'}));
  await screen.findByText(/No completed result/);expect(mocks.get).toHaveBeenCalledWith('/wallet/credit-transfers/'+id);
  expect(mocks.post.mock.calls.filter(([path])=>path.endsWith('/commit'))).toHaveLength(1);
});
it('recovers a completed receipt from pending storage without posting anything',async()=>{
  sessionStorage.setItem('sms-credit-transfer:pending:customer:2',id);
  mocks.get.mockImplementation(async(path:string)=>({success:true,data:path.endsWith('/'+id)?{status:'completed',transfer:receipt}:{enabled:true,transfers:[],page:1,last_page:1}}));
  wrap(<CustomerCreditTransfer availableCredits={75}/>);const start=await screen.findByRole('button',{name:'Transfer credits'});await waitFor(()=>expect(start).toBeEnabled());fireEvent.click(start);
  await screen.findByRole('heading',{name:'Transfer completed'});expect(mocks.post).not.toHaveBeenCalled();expect(sessionStorage.length).toBe(0);
});
it('allows a fresh review only after an incomplete pending review has expired',async()=>{
  sessionStorage.setItem('sms-credit-transfer:pending:customer:2',id);
  mocks.get.mockImplementation(async(path:string)=>({success:true,data:path.endsWith('/'+id)?{status:'not_completed',quote:{...quote,expires_at:0},new_review_allowed:true}:{enabled:true,transfers:[],page:1,last_page:1}}));
  wrap(<CustomerCreditTransfer availableCredits={80}/>);const start=await screen.findByRole('button',{name:'Transfer credits'});await waitFor(()=>expect(start).toBeEnabled());fireEvent.click(start);
  await screen.findByText(/expired without completing/);expect(screen.getByRole('button',{name:'Back'})).toBeEnabled();expect(screen.getByRole('button',{name:'Confirm transfer'})).toBeDisabled();expect(mocks.post).not.toHaveBeenCalled();
});
it('admin controls require configured master-password security',async()=>{
  mocks.get.mockResolvedValue({success:true,data:{enabled:true,transfers:[],total:0,page:1,last_page:1,security:{configured:false}}});
  wrap(<CreditTransferPanel/>);await screen.findByText(/master password must be configured/);expect(screen.getByRole('button',{name:'Transfer credits'})).toBeDisabled();expect(mocks.post).not.toHaveBeenCalled();
});
it('customer history presents real own transfers with no counterparty details',async()=>{
  mocks.get.mockResolvedValue({success:true,data:{enabled:true,transfers:[{id,reference:receipt.reference,credits:5,direction:'received',status:'completed',created_at:receipt.created_at}],page:1,last_page:1}});
  wrap(<CreditTransferHistory/>);const region=await screen.findByRole('region',{name:'Credit transfer history'});expect(await within(region).findByText('+5 credits received')).toBeInTheDocument();expect(region).not.toHaveTextContent('recipient@example.invalid');
});
