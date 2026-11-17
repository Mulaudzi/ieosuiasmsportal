import {cleanup,fireEvent,render,screen,waitFor,act} from '@testing-library/react';
import {afterEach,beforeEach,expect,it,vi} from 'vitest';
import AdminOperationalLogs from './AdminOperationalLogs';
const m=vi.hoisted(()=>({get:vi.fn()}));
vi.mock('@/lib/api',()=>({api:{get:m.get}}));
vi.mock('@/components/layout/AdminLayout',()=>({AdminLayout:({children,actions}:any)=><main>{actions}{children}</main>}));
const response=(message:string)=>({success:true,logs:[{id:1,level:'info',service:'fixture',event:'read',message,created_at:'2026-11-16 12:00:00'}],runs:[],pagination:{page:1,last_page:1,total:1}});
afterEach(cleanup);beforeEach(()=>vi.clearAllMocks());
it('shows a failed initial request and recovers on Refresh without claiming no events exist',async()=>{
 m.get.mockRejectedValueOnce(Error('Service temporarily unavailable')).mockResolvedValueOnce(response('Recovered log'));
 render(<AdminOperationalLogs/>);expect(await screen.findByRole('alert')).toHaveTextContent('Service temporarily unavailable');
 expect(screen.queryByText('No matching operational events.')).not.toBeInTheDocument();
 fireEvent.click(screen.getByRole('button',{name:'Refresh'}));expect(await screen.findByText('Recovered log')).toBeInTheDocument();expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});
it('retains prior records and identifies stale results when refresh fails',async()=>{
 m.get.mockResolvedValueOnce(response('Previously loaded log')).mockRejectedValueOnce(Error('Refresh unavailable'));
 render(<AdminOperationalLogs/>);await screen.findByText('Previously loaded log');fireEvent.click(screen.getByRole('button',{name:'Refresh'}));
 expect(await screen.findByRole('alert')).toHaveTextContent('Showing the last successfully loaded results.');expect(screen.getByText('Previously loaded log')).toBeInTheDocument();
});
it('does not replace newer filtered results with a slower old request',async()=>{
 let finish!:(value:ReturnType<typeof response>)=>void;
 m.get.mockImplementationOnce(()=>new Promise(resolve=>{finish=resolve;})).mockResolvedValueOnce(response('Latest filtered result'));
 render(<AdminOperationalLogs/>);fireEvent.change(screen.getByPlaceholderText('Search events and errors'),{target:{value:'latest'}});
 await screen.findByText('Latest filtered result');await act(async()=>finish(response('Stale old result')));
 expect(screen.queryByText('Stale old result')).not.toBeInTheDocument();expect(screen.getByText('Latest filtered result')).toBeInTheDocument();
 await waitFor(()=>expect(screen.getByRole('button',{name:'Refresh'})).toBeEnabled());
});
