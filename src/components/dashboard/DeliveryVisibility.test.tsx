import {cleanup,fireEvent,render,screen} from '@testing-library/react';
import {afterEach,beforeEach,describe,expect,it,vi} from 'vitest';
import {DeliveryBreakdown} from './DeliveryBreakdown';
import {CampaignDeliveryBar} from './CampaignDeliveryBar';
import {DeliveryStats} from './DeliveryStats';
import {TooltipProvider} from '@/components/ui/tooltip';

vi.mock('recharts',()=>({ResponsiveContainer:({children}:{children:React.ReactNode})=><div data-testid="delivery-chart">{children}</div>,PieChart:({children}:{children:React.ReactNode})=><div>{children}</div>,Pie:()=>null,Cell:()=>null,Tooltip:()=>null}));
beforeEach(()=>{
  vi.stubGlobal('ResizeObserver',class {observe(){} unobserve(){} disconnect(){}});
  vi.stubGlobal('IntersectionObserver',class {observe(){} unobserve(){} disconnect(){}});
});
afterEach(()=>{cleanup();vi.unstubAllGlobals();});
describe('delivery display readiness',()=>{
  it('shows the current breakdown immediately for partially confirmed results',()=>{
    render(<CampaignDeliveryBar delivered={7} failed={0} counts={[7,3,0,0,0]} total={10}/>,{wrapper:TooltipProvider});
    fireEvent.click(screen.getByRole('button',{name:'Delivery Breakdown'}));
    expect(screen.getByText('Sent - Awaiting Confirmation',{exact:true})).toBeInTheDocument();
    expect(screen.getByText('Latest available results. Confirmations update as network reports arrive.')).toBeInTheDocument();
  });
  it('updates an open panel from the latest snapshot without hiding previous confirmations',()=>{
    const {rerender}=render(<DeliveryBreakdown counts={[7,3,0,0,0]} total={10}/>,{wrapper:TooltipProvider});
    rerender(<DeliveryBreakdown counts={[8,0,0,1,1]} total={10}/>);
    fireEvent.click(screen.getByRole('button',{name:'Delivery Breakdown'}));
    expect(screen.getByText('Delivered',{exact:true})).toBeInTheDocument();
    expect(screen.getByText('Not Sent',{exact:true})).toBeInTheDocument();
    rerender(<DeliveryBreakdown counts={[8,0,1,0,1]} total={10}/>);
    expect(screen.getByRole('button',{name:'Delivery Breakdown'})).toBeInTheDocument();
    expect(screen.getByText('Sent - Confirmation Unavailable',{exact:true})).toBeInTheDocument();
  });
  it('shows current dashboard counts while unavailable confirmations remain neutral',()=>{
    render(<DeliveryStats stats={{total_sent:10,total_delivered:8,total_failed:0,total_pending:2,total_awaiting_delivery:0,total_dlr_unavailable:2,total_skipped:0,delivery_rate:80}}/>,{wrapper:TooltipProvider});
    expect(screen.getByTestId('delivery-chart')).toBeInTheDocument();
    expect(screen.getByText('80%')).toBeInTheDocument();
    expect(screen.getByText('Latest results. Confirmations are still updating.')).toBeInTheDocument();
  });
  it('shows final dashboard confirmations without changing its denominator',()=>{
    render(<DeliveryStats stats={{total_sent:10,total_delivered:8,total_failed:2,total_pending:0,total_awaiting_delivery:0,total_dlr_unavailable:0,total_skipped:0,delivery_rate:80}}/>,{wrapper:TooltipProvider});
    expect(screen.getByTestId('delivery-chart')).toBeInTheDocument();
    expect(screen.getByText('80%')).toBeInTheDocument();
  });
});
