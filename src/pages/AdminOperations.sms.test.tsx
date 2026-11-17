import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import AdminOperations from './AdminOperations';
import { toast } from '@/hooks/use-toast';
const mocks=vi.hoisted(()=>({get:vi.fn()}));
vi.mock('@/lib/api',()=>({api:{get:mocks.get}}));
vi.mock('@/components/layout/AdminLayout',()=>({AdminLayout:({children,actions}:any)=><main>{actions}{children}</main>}));
vi.mock('@/components/wallet/BankTransferReview',()=>({BankTransferReview:()=>null}));
vi.mock('@/components/wallet/CreditApprovalSettings',()=>({CreditApprovalSettings:()=>null}));
vi.mock('@/components/wallet/CreditTransferPanel',()=>({CreditTransferPanel:()=>null}));
vi.mock('@/components/wallet/CashAdjustmentPanel',()=>({CashAdjustmentPanel:()=>null}));
vi.mock('@/components/wallet/CreditPaymentsHistory',()=>({CreditPaymentsHistory:()=>null}));
vi.mock('@/hooks/use-toast',()=>({toast:vi.fn()}));
afterEach(cleanup);
it('surfaces delivery-report problems without treating pending confirmations as unsent messages',async()=>{
  const original=mocks.get.getMockImplementation();
  mocks.get.mockImplementation(async(path:string)=>path==='/admin/operations/services'?{success:true,data:{services:[],delivery_reporting:{available:true,status:'attention',awaiting_confirmation:485,not_yet_queried:485,older_than_day:0,missing_lookups_last_day:3,last_callback_at:null,problems:['NO_RECENT_DELIVERY_CALLBACKS']}}}:original?.(path));
  render(<MemoryRouter initialEntries={['/guymhan?tab=monitoring']}><AdminOperations/></MemoryRouter>);
  const report=await screen.findByLabelText('Delivery reporting health');
  expect(report).toHaveTextContent('Needs attention');expect(report).toHaveTextContent('485');
  expect(report).toHaveTextContent('Verify the LogicSMS postback configuration');
});
beforeEach(()=>{vi.clearAllMocks();mocks.get.mockImplementation(async(path:string)=>({success:true,data:
 path==='/admin/operations/overview'?{users:{total:1},campaigns:{total:6},messages:{total:500},wallets:{},payments:{},recent_customers:[],customer_credits:{sms_credits_available:482,sms_credits_reserved:4,sms_credits_total:486},dispatch:{jobs:{active:2,queued:1,processing:1},messages:{waiting:44,processing:10,held:4},observed_at:'2026-10-23T22:30:00+02:00'}}:
 path.includes('/customers?')?{customers:[{id:7,name:'Customer fixture',email:'fixture@example.invalid',is_active:1,balance:170.1,reserved:1.4,sms_credits_available:482,sms_credits_reserved:4,sms_credits_total:486}]}:
 path.includes('/campaigns?')?{campaigns:[]}:path.endsWith('/services')?{services:[]}:path.endsWith('/provider')?{available:true,credits:999}:{}
 }));});
it('shows actual SMS credits rather than internal rand values, and distinguishes campaigns from messages',async()=>{
 render(<MemoryRouter><AdminOperations/></MemoryRouter>);
 const section=await screen.findByRole('region',{name:'Customer credits and live SMS jobs'});
 expect(section).toHaveTextContent('482');expect(section).toHaveTextContent('4 reserved');expect(section).toHaveTextContent('486 total remaining');expect(section).toHaveTextContent('Active SMS jobs');expect(section).toHaveTextContent('1 queued campaigns');expect(section).toHaveTextContent('10');expect(section).toHaveTextContent('44 waiting to send');expect(section).toHaveTextContent('4 held for review');expect(section).toHaveTextContent('Pending delivery confirmations are not counted');
 fireEvent.click(screen.getByRole('button',{name:'Customers'}));
 const row=screen.getByText('Customer fixture').closest('tr')!;expect(within(row).getByText('482')).toBeInTheDocument();expect(within(row).getByText('4')).toBeInTheDocument();expect(row).not.toHaveTextContent('R170.10');expect(screen.getByText('Available SMS credits')).toBeInTheDocument();
});

const measuredCampaign={id:23,name:'Measured campaign',state:'completed',recipient_count:485,delivered_count:400,actual_cost:168.35,user_name:'Customer fixture',user_email:'fixture@example.invalid',sending_timing:{status:'complete',source:'accepted_timestamps',queue_wait_seconds:46,sending_seconds:1309,total_seconds:1355,elapsed_seconds:null,accepted_per_minute:22.05,accepted_count:481,outstanding_count:0,held_count:0,skipped_count:4}};
it('shows timings and a measured recent average when opening a customer, then opens campaign performance',async()=>{
 const original=mocks.get.getMockImplementation()!;
 mocks.get.mockImplementation(async(path:string)=>path==='/admin/operations/customers/7'?{success:true,data:{customer:{id:7,name:'Customer fixture',email:'fixture@example.invalid'},counts:{campaigns:2,messages:970},campaigns:[measuredCampaign],sending_summary:{sample_size:2,recent_campaign_count:2,average_sending_seconds:1171.5,fastest_seconds:1034,slowest_seconds:1309},payments:[]}}:path.startsWith('/admin/operations/campaigns/23?')?{success:true,data:{campaign:measuredCampaign,messages:[]}}:original(path));
 render(<MemoryRouter initialEntries={['/guymhan?tab=customers']}><AdminOperations/></MemoryRouter>);
 fireEvent.click(await screen.findByText('Customer fixture'));
 const summary=await screen.findByRole('region',{name:'Customer sending performance'});
 expect(summary).toHaveTextContent('19m 32s');
 expect(screen.getAllByText('21m 49s')).toHaveLength(2);
 fireEvent.click(screen.getByRole('button',{name:'View campaign Measured campaign'}));
 const performance=await screen.findByRole('region',{name:'Campaign sending performance'});
 expect(performance).toHaveTextContent('21m 49s');expect(performance).toHaveTextContent('22m 35s');
 fireEvent.click(screen.getByRole('button',{name:'Refresh timing'}));
 await screen.findByRole('region',{name:'Campaign sending performance'});
 expect(mocks.get.mock.calls.filter(([path])=>path.startsWith('/admin/operations/campaigns/23?'))).toHaveLength(2);
});
it('includes sending time in the global campaign list',async()=>{
 const original=mocks.get.getMockImplementation()!;
 mocks.get.mockImplementation(async(path:string)=>path.includes('/campaigns?')?{success:true,data:{campaigns:[measuredCampaign]}}:original(path));
 render(<MemoryRouter initialEntries={['/guymhan?tab=campaigns']}><AdminOperations/></MemoryRouter>);
 const row=(await screen.findByText('Measured campaign')).closest('tr')!;
 expect(row).toHaveTextContent('21m 49s');expect(row).toHaveTextContent('Queue: 46s');
 expect(screen.getByRole('columnheader',{name:'Sending time'})).toBeInTheDocument();
});
it('opens a focused overview and keeps monitoring details out of the customer list',async()=>{
 render(<MemoryRouter><AdminOperations/></MemoryRouter>);
 expect(await screen.findByRole('heading',{level:2,name:'Overview'})).toBeInTheDocument();
 expect(screen.getByRole('region',{name:'Operations workspaces'})).toBeInTheDocument();
 expect(screen.queryByText('Production services')).not.toBeInTheDocument();
 fireEvent.click(screen.getByRole('button',{name:'Customers'}));
 expect(await screen.findByText('Customer fixture')).toBeInTheDocument();
 expect(screen.queryByRole('region',{name:'Customer credits and live SMS jobs'})).not.toBeInTheDocument();
});
it('retains a visible overview warning when monitoring reports a problem',async()=>{
 const original=mocks.get.getMockImplementation()!;
 mocks.get.mockImplementation(async(path:string)=>path.endsWith('/services')?{success:true,data:{services:[],delivery_reporting:{available:true,status:'attention'}}}:original(path));
 render(<MemoryRouter><AdminOperations/></MemoryRouter>);
 const warning=await screen.findByRole('link',{name:/Monitoring needs attention/});
 expect(warning).toHaveAttribute('href','/guymhan?tab=monitoring');
 expect(warning.querySelector('svg.lucide-triangle-alert')).toBeInTheDocument();
 expect(toast).not.toHaveBeenCalled();
});
