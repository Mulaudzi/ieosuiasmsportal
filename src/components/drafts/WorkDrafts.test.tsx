import {cleanup, fireEvent, render, screen, waitFor, within} from '@testing-library/react';
import {afterEach, beforeEach, expect, it, vi} from 'vitest';
import {MemoryRouter} from 'react-router-dom';
import {QueryClient, QueryClientProvider} from '@tanstack/react-query';
import CreateSmsCampaign from '@/pages/CreateSmsCampaign';
import {BuyCreditsModal} from '@/components/wallet/BuyCreditsModal';
import {DraftList} from './DraftList';
import {WorkDraft} from '@/lib/work-drafts';
const m=vi.hoisted(()=>({get:vi.fn(),put:vi.fn(),post:vi.fn(),delete:vi.fn(),create:vi.fn(),preview:vi.fn(),toast:vi.fn(),user:'owner'}));
vi.mock('@/hooks/useAuth',()=>({useAuth:()=>({user:{id:m.user}})}));
vi.mock('@/hooks/use-toast',()=>({toast:m.toast}));
vi.mock('@/components/layout/DashboardLayout',()=>({DashboardLayout:({children}:{children:React.ReactNode})=><div>{children}</div>}));
vi.mock('@/components/wallet/BankTransferOrder',()=>({BankTransferOrder:()=> <p>Existing transfer order</p>}));
vi.mock('@/lib/api',()=>({api:{get:m.get,put:m.put,post:m.post,delete:m.delete},handleApiError:vi.fn(),getTemplates:async()=>({data:[]}),previewSmsCampaign:m.preview,createSmsCampaign:m.create}));
const id='10000000-0000-4000-8000-000000000001';
const sms:WorkDraft<'sms'>={id,title:'Sunday SMS',kind:'sms',version:2,created_at:'2026-10-20 12:00:00',updated_at:'2026-10-20 12:00:00',payload:{version:1,step:5,name:'Sunday campaign',manual:'0638082493',groupIds:['8'],contactIds:['4'],excluded:['27630000000'],selectedTemplate:'none',content:'Saved SMS body',includeOptOut:false,schedule:'2027-01-01T12:00',submissionKey:'saved-campaign-key'}};
const purchase:WorkDraft<'purchase'>={...sms,kind:'purchase',title:'Future credits',payload:{version:1,step:'payment',credits:1000,method:'eft',requestKey:'saved-payment-key'}};
function wrap(node:React.ReactNode,path='/') {return render(<QueryClientProvider client={new QueryClient({defaultOptions:{queries:{retry:false}}})}><MemoryRouter initialEntries={[path]}>{node}</MemoryRouter></QueryClientProvider>);}
beforeEach(()=>{
 vi.clearAllMocks();localStorage.clear();m.user='owner';
 m.get.mockImplementation(async(path:string)=>path.startsWith('/drafts/')?{draft:sms}:path==='/drafts'?{drafts:[{...sms,payload:undefined}]}:path==='/settings/sms'?{opt_out_text:'Reply STOP to unsubscribe'}:path==='/wallet/purchase-options'?{purchases_available:true,direct_transfer_available:true}:{data:[]});
 m.put.mockImplementation(async(path:string,body:any)=>({draft:{...sms,id:path.split('/').pop(),kind:body.kind,title:body.title,version:body.expected_version+1,payload:body.payload}}));
 m.delete.mockResolvedValue({deleted:true});m.post.mockResolvedValue({order:{id:1}});m.create.mockResolvedValue({campaign:{id:42}});
 m.preview.mockImplementation(async(input:any)=>({preview:{final_content:input.content,encoding:'gsm7',character_count:input.content.length,segment_count:1,required_credits:1,available_credits:10,price_per_segment:.35,sufficient_balance:true,recipients:['27638082493'],sendable_count:1}}));
});
afterEach(()=>{cleanup();localStorage.clear();vi.restoreAllMocks();});
it('opens a saved SMS at the message step without sending, charging or trusting a final review',async()=>{
 wrap(<CreateSmsCampaign/>,`/sms-campaigns/new?draft=${id}`);
 expect(await screen.findByRole('textbox',{name:'SMS message'})).toHaveValue('Saved SMS body');
 expect(screen.getByRole('switch')).toHaveAttribute('aria-checked','false');
 expect(screen.queryByRole('button',{name:'Send SMS'})).not.toBeInTheDocument();expect(m.create).not.toHaveBeenCalled();expect(m.post).not.toHaveBeenCalled();
 await waitFor(()=>expect(screen.getByRole('switch')).toBeEnabled());
 fireEvent.click(screen.getByRole('button',{name:'Review SMS'}));await screen.findByRole('button',{name:'Continue'});
 expect(m.preview).toHaveBeenLastCalledWith(expect.objectContaining({include_opt_out:false,recipients:{manual:['0638082493'],group_ids:['8'],contact_ids:['4'],excluded:['27630000000']}}));
});
it('saves unfinished SMS edits with the original key and correct concurrency version',async()=>{
 wrap(<CreateSmsCampaign/>,`/sms-campaigns/new?draft=${id}`);await screen.findByRole('textbox',{name:'SMS message'});
 fireEvent.change(screen.getByRole('textbox',{name:'SMS message'}),{target:{value:'Edited before saving'}});
 fireEvent.click(screen.getByRole('button',{name:'Save draft'}));const dialog=screen.getByRole('dialog',{name:'Save for later'});
 fireEvent.click(within(dialog).getByRole('button',{name:'Save draft'}));
 await waitFor(()=>expect(m.put).toHaveBeenCalledWith(`/drafts/${id}`,expect.objectContaining({expected_version:2,payload:expect.objectContaining({submissionKey:'saved-campaign-key',content:'Edited before saving',includeOptOut:false,step:3})})));
 await waitFor(()=>expect(screen.queryByRole('dialog')).not.toBeInTheDocument());expect(m.create).not.toHaveBeenCalled();
});
it('keeps message edits on save failure and offers retry without creating a campaign',async()=>{
 m.put.mockRejectedValue(Error('Draft changed in another tab'));
 wrap(<CreateSmsCampaign/>,`/sms-campaigns/new?draft=${id}`);await screen.findByRole('textbox',{name:'SMS message'});
 fireEvent.change(screen.getByRole('textbox',{name:'SMS message'}),{target:{value:'Keep my edits'}});
 fireEvent.click(screen.getByRole('button',{name:'Save draft'}));fireEvent.click(within(screen.getByRole('dialog')).getByRole('button',{name:'Save draft'}));
 expect(await screen.findByRole('alert')).toHaveTextContent('Draft changed');fireEvent.click(screen.getByRole('button',{name:'Cancel'}));expect(screen.getByRole('textbox',{name:'SMS message'})).toHaveValue('Keep my edits');expect(m.create).not.toHaveBeenCalled();
});
it('removes a saved SMS only after an explicit successful queue operation',async()=>{
 wrap(<CreateSmsCampaign/>,`/sms-campaigns/new?draft=${id}`);await screen.findByRole('textbox',{name:'SMS message'});
 await waitFor(()=>expect(screen.getByRole('switch')).toBeEnabled());fireEvent.click(screen.getByRole('button',{name:'Review SMS'}));
 fireEvent.click(await screen.findByRole('button',{name:'Continue'}));expect(document.querySelector('input[type="datetime-local"]')).toHaveValue('2027-01-01T12:00');expect(m.delete).not.toHaveBeenCalled();
 fireEvent.click(screen.getByRole('button',{name:'Send SMS'}));
 await waitFor(()=>expect(m.delete).toHaveBeenCalledWith(`/drafts/${id}`,{data:{expected_version:2}}));
 expect(m.create).toHaveBeenCalledWith(expect.objectContaining({idempotency_key:'saved-campaign-key'}));expect(localStorage.getItem(`sms-campaign-draft:v1:owner:${id}`)).toBeNull();
});
it('does not fall back to another form when a selected account draft cannot be loaded',async()=>{
 m.get.mockRejectedValue(Error('Not found'));wrap(<CreateSmsCampaign/>,`/sms-campaigns/new?draft=${id}`);
 expect(await screen.findByRole('alert')).toHaveTextContent('could not be opened');expect(screen.queryByRole('textbox')).not.toBeInTheDocument();expect(m.create).not.toHaveBeenCalled();
});
it('reuses the draft ID after refresh when the first save response was lost',async()=>{
 m.put.mockRejectedValueOnce(Error('Connection lost'));
 const first=wrap(<CreateSmsCampaign/>,'/sms-campaigns/new');fireEvent.change(screen.getByRole('textbox'),{target:{value:'Stable draft'}});
 fireEvent.click(screen.getByRole('button',{name:'Save draft'}));fireEvent.click(within(screen.getByRole('dialog')).getByRole('button',{name:'Save draft'}));await screen.findByRole('alert');
 const path=m.put.mock.calls[0][0];first.unmount();
 wrap(<CreateSmsCampaign/>,'/sms-campaigns/new');fireEvent.click(screen.getByRole('button',{name:'Save draft'}));fireEvent.click(within(screen.getByRole('dialog')).getByRole('button',{name:'Save draft'}));
 await waitFor(()=>expect(m.put).toHaveBeenCalledTimes(2));expect(m.put.mock.calls[1][0]).toBe(path);expect(m.create).not.toHaveBeenCalled();
});
it('keeps older browser edits when another tab has saved a newer SMS copy',async()=>{
 localStorage.setItem(`sms-campaign-draft:v1:owner:${id}`,JSON.stringify({...sms.payload,content:'Unsaved local edit',savedDraft:{id,title:sms.title,version:1}}));
 wrap(<CreateSmsCampaign/>,`/sms-campaigns/new?draft=${id}`);expect(await screen.findByRole('textbox',{name:'SMS message'})).toHaveValue('Unsaved local edit');
 expect(screen.getByRole('alert')).toHaveTextContent('changed elsewhere');expect(screen.getByRole('button',{name:'Load latest account copy'})).toBeVisible();
});
it('restores purchase intent, recomputes current price, and does not start a payment on open/save',async()=>{
 wrap(<BuyCreditsModal open onOpenChange={()=>{}} draft={purchase}/>);
 expect((await screen.findAllByText('R306.90')).length).toBeGreaterThan(0);expect(await screen.findByRole('button',{name:/Pay directly/})).toHaveAttribute('aria-pressed','true');expect(m.post).not.toHaveBeenCalled();
 fireEvent.click(screen.getByRole('button',{name:'Save draft'}));const dialog=screen.getByRole('dialog',{name:'Save for later'});
 fireEvent.click(within(dialog).getByRole('button',{name:'Save draft'}));
 await waitFor(()=>expect(m.put).toHaveBeenCalledWith(`/drafts/${id}`,{kind:'purchase',title:'Future credits',expected_version:2,payload:purchase.payload}));expect(m.post).not.toHaveBeenCalled();
});
it('recovers quantity, method and stable purchase key after close/refresh',async()=>{
 const view=wrap(<BuyCreditsModal open onOpenChange={()=>{}}/>);await screen.findByRole('button',{name:'Choose payment method'});
 fireEvent.change(screen.getByRole('spinbutton'),{target:{value:'2500'}});fireEvent.click(screen.getByRole('button',{name:'Choose payment method'}));fireEvent.click(screen.getByRole('button',{name:/Pay directly/}));
 const before=JSON.parse(localStorage.getItem('sms-purchase-draft:v1:owner')!);view.unmount();
 wrap(<BuyCreditsModal open onOpenChange={()=>{}}/>);await screen.findByRole('button',{name:/Create bank transfer order/});
 expect(screen.getByText('R717.75')).toBeVisible();expect(JSON.parse(localStorage.getItem('sms-purchase-draft:v1:owner')!).payload.requestKey).toBe(before.payload.requestKey);expect(m.post).not.toHaveBeenCalled();
});
it('starts a saved purchase only on explicit checkout, then removes its matching draft',async()=>{
 wrap(<BuyCreditsModal open onOpenChange={()=>{}} draft={purchase}/>);
 const proceed=await screen.findByRole('button',{name:'Create bank transfer order'});await waitFor(()=>expect(proceed).toBeEnabled());fireEvent.click(proceed);await screen.findByText('Existing transfer order');
 expect(m.post).toHaveBeenCalledWith('/wallet/buy',expect.objectContaining({idempotency_key:'saved-payment-key',credits:1000,quoted_total_cents:30690}));
 expect(m.delete).toHaveBeenCalledWith(`/drafts/${id}`,{data:{expected_version:2}});expect(localStorage.getItem('sms-purchase-draft:v1:owner')).toBeNull();
});
it('does not restore another account purchase',async()=>{
 localStorage.setItem('sms-purchase-draft:v1:other',JSON.stringify({payload:purchase.payload}));wrap(<BuyCreditsModal open onOpenChange={()=>{}}/>);
 expect(await screen.findByRole('spinbutton')).toHaveValue(100);expect(m.post).not.toHaveBeenCalled();
});
it('lists only the selected draft kind and requires confirmation before deletion',async()=>{
 vi.spyOn(window,'confirm').mockReturnValue(false);wrap(<DraftList kind="sms"/>);
 expect(await screen.findByRole('link',{name:'Resume'})).toHaveAttribute('href',`/sms-campaigns/new?draft=${id}`);
 expect(m.get).toHaveBeenCalledWith('/drafts',{kind:'sms'});fireEvent.click(screen.getByRole('button',{name:'Delete'}));expect(m.delete).not.toHaveBeenCalled();
 vi.mocked(window.confirm).mockReturnValue(true);fireEvent.click(screen.getByRole('button',{name:'Delete'}));await waitFor(()=>expect(m.delete).toHaveBeenCalledWith(`/drafts/${id}`,{data:{expected_version:2}}));
});
