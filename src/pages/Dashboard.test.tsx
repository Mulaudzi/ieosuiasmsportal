import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import Dashboard from './Dashboard';
const mocks=vi.hoisted(()=>({stats:vi.fn(),get:vi.fn()}));
vi.mock('@/lib/api',()=>({getDashboardStats:mocks.stats,api:{get:mocks.get}}));
vi.mock('@/hooks/useSmsRefresh',()=>({useSmsRefresh:()=>{}}));
vi.mock('@/components/layout/DashboardLayout',()=>({DashboardLayout:({children,actions}:{children:React.ReactNode;actions:React.ReactNode})=><main>{actions}{children}</main>}));
vi.mock('@/components/dashboard/DashboardTour',()=>({DashboardTour:()=>null}));
vi.mock('@/components/dashboard/GetStartedChecklist',()=>({GetStartedChecklist:()=>null}));
vi.mock('@/components/dashboard/CampaignSummary',()=>({LatestCampaign:()=>null,RecentCampaignTable:()=>null}));
vi.mock('@/components/dashboard/DeliveryStats',()=>({DeliveryStats:()=>null}));
vi.mock('@/components/dashboard/MetricCard',()=>({MetricCard:({title,value}:{title:string;value:string})=><div data-testid={title}>{value}</div>}));
vi.mock('@/components/dashboard/CampaignChart',()=>({CampaignChart:({period}:{period:{range:string;from?:string;to?:string}})=><div data-testid="chart-range">{JSON.stringify(period)}</div>}));
vi.mock('@/components/dashboard/DateRangeFilter',()=>({DateRangeFilter:({onChange}:{onChange:(period:{range:string;from?:string;to?:string})=>void})=><><button onClick={()=>onChange({range:'7d'})}>Seven days</button><button onClick={()=>onChange({range:'custom',from:'2026-10-01',to:'2026-10-21'})}>Custom dates</button></>}));
const response=(sent:number)=>({success:true,total_sent:sent,total_contacts:690,total_delivered:sent,total_failed:0,total_pending:0,total_awaiting_delivery:0,total_dlr_unavailable:0,total_skipped:0,total_recipients:sent,total_failed_submissions:0,active_campaigns:0,delivery_rate:100});
beforeEach(()=>{mocks.stats.mockReset();mocks.get.mockReset();mocks.stats.mockResolvedValue(response(403));mocks.get.mockResolvedValue({success:true,campaigns:[]});});
afterEach(cleanup);
const mount=()=>render(<Dashboard/>,{wrapper:MemoryRouter});
it('requests 30 days by default and passes the same period to chart and campaigns',async()=>{
 mount();await waitFor(()=>expect(screen.getByTestId('Total Sent')).toHaveTextContent('403'));
 expect(mocks.stats).toHaveBeenCalledWith('30d',{range:'30d'});
 expect(mocks.get).toHaveBeenCalledWith('/dashboard/recent-campaigns',{range:'30d'});
 expect(screen.getByTestId('chart-range')).toHaveTextContent('30d');
});
it('applies both custom boundaries consistently',async()=>{
 mount();await screen.findByTestId('Total Sent');fireEvent.click(screen.getByText('Custom dates'));
 await waitFor(()=>expect(mocks.stats).toHaveBeenLastCalledWith('custom',{range:'custom',from:'2026-10-01',to:'2026-10-21'}));
 expect(mocks.get).toHaveBeenLastCalledWith('/dashboard/recent-campaigns',{range:'custom',from:'2026-10-01',to:'2026-10-21'});
 expect(screen.getByTestId('chart-range')).toHaveTextContent('2026-10-21');
});
it('ignores a late response from the previous reporting period',async()=>{
 let resolveOld!:(value:ReturnType<typeof response>)=>void;
 mocks.stats.mockImplementation((range:string)=>range==='30d'?new Promise(resolve=>{resolveOld=resolve;}):Promise.resolve(response(70)));
 mount();fireEvent.click(screen.getByText('Seven days'));
 await waitFor(()=>expect(screen.getByTestId('Total Sent')).toHaveTextContent('70'));
 resolveOld(response(403));await Promise.resolve();
 expect(screen.getByTestId('Total Sent')).toHaveTextContent('70');
});
it('does not present missing metrics as zero activity',async()=>{
 mocks.stats.mockRejectedValue(new Error('Unavailable'));mount();
 await screen.findByRole('alert');expect(screen.getByTestId('Total Sent')).toHaveTextContent('—');
});
