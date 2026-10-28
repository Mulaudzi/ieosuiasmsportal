import { cleanup, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import AdminOperations from './AdminOperations';
const mocks=vi.hoisted(()=>({get:vi.fn()}));
vi.mock('@/lib/api',()=>({api:{get:mocks.get}}));
vi.mock('@/components/layout/AdminLayout',()=>({AdminLayout:({children,actions}:any)=><main>{actions}{children}</main>}));
vi.mock('@/components/wallet/BankTransferReview',()=>({BankTransferReview:()=>null}));
vi.mock('@/components/wallet/CreditApprovalSettings',()=>({CreditApprovalSettings:()=>null}));
vi.mock('@/components/wallet/CreditPaymentsHistory',()=>({CreditPaymentsHistory:()=>null}));
vi.mock('@/hooks/use-toast',()=>({toast:vi.fn()}));
afterEach(cleanup);
beforeEach(()=>{vi.clearAllMocks();mocks.get.mockImplementation(async(path:string)=>({success:true,data:
 path==='/admin/operations/overview'?{users:{total:1},campaigns:{total:6},messages:{total:500},wallets:{},payments:{},recent_customers:[],customer_credits:{sms_credits_available:482,sms_credits_reserved:4,sms_credits_total:486},dispatch:{jobs:{active:2,queued:1,processing:1},messages:{waiting:44,processing:10,held:4},observed_at:'2026-10-23T22:30:00+02:00'}}:
 path.includes('/customers?')?{customers:[{id:7,name:'Customer fixture',email:'fixture@example.invalid',is_active:1,balance:170.1,reserved:1.4,sms_credits_available:482,sms_credits_reserved:4,sms_credits_total:486}]}:
 path.includes('/campaigns?')?{campaigns:[]}:path.endsWith('/services')?{services:[]}:path.endsWith('/provider')?{available:true,credits:999}:{}
 }));});
it('shows actual SMS credits rather than internal rand values, and distinguishes campaigns from messages',async()=>{
 render(<MemoryRouter><AdminOperations/></MemoryRouter>);
 const section=await screen.findByRole('region',{name:'Customer credits and live SMS jobs'});
 expect(section).toHaveTextContent('482');expect(section).toHaveTextContent('4 reserved');expect(section).toHaveTextContent('486 total remaining');expect(section).toHaveTextContent('Active SMS jobs');expect(section).toHaveTextContent('1 queued campaigns');expect(section).toHaveTextContent('10');expect(section).toHaveTextContent('44 waiting to send');expect(section).toHaveTextContent('4 held for review');expect(section).toHaveTextContent('Pending delivery confirmations are not counted');
 const row=screen.getByText('Customer fixture').closest('tr')!;expect(within(row).getByText('482')).toBeInTheDocument();expect(within(row).getByText('4')).toBeInTheDocument();expect(row).not.toHaveTextContent('R170.10');expect(screen.getByText('Available SMS credits')).toBeInTheDocument();
});
