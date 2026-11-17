import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ExistingSmsCampaignPicker } from './ExistingSmsCampaignPicker';
const mocks=vi.hoisted(()=>({get:vi.fn()}));
vi.mock('@/lib/api',()=>({api:{get:mocks.get}}));
afterEach(cleanup);
beforeEach(()=>{vi.clearAllMocks();mocks.get.mockImplementation(async(path:string)=>path==='/sms/campaigns-v2'?{success:true,campaigns:[{id:23,name:'Sunday reminder',state:'completed'}]}:{success:true,campaign:{id:23,name:'Sunday reminder',content:'Welcome on Sunday'}});});
it('loads only on explicit choice, searches all dates and copies only the selected campaign setup',async()=>{
 const select=vi.fn();render(<ExistingSmsCampaignPicker onSelect={select}/>);expect(mocks.get).not.toHaveBeenCalled();
 fireEvent.click(screen.getByText('Choose an existing campaign'));expect(await screen.findByRole('dialog')).toHaveTextContent('original campaign, recipients and history stay unchanged');
 fireEvent.click(await screen.findByRole('button',{name:'Sunday reminder — completed'}));
 await waitFor(()=>expect(select).toHaveBeenCalledWith({id:'23',name:'Sunday reminder',content:'Welcome on Sunday'}));
 expect(mocks.get).toHaveBeenCalledWith('/sms/campaigns-v2',expect.objectContaining({range:'all'}));expect(mocks.get).toHaveBeenCalledWith('/sms/campaigns-v2/23');expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});
it('does not copy or submit when campaign details are unavailable or mismatched',async()=>{
 mocks.get.mockImplementation(async(path:string)=>path==='/sms/campaigns-v2'?{success:true,campaigns:[{id:23,name:'Sunday reminder',state:'completed'}]}:{success:true,campaign:{id:24,name:'Wrong',content:'Do not copy'}});
 const select=vi.fn();render(<ExistingSmsCampaignPicker onSelect={select}/>);fireEvent.click(screen.getByText('Choose an existing campaign'));fireEvent.click(await screen.findByRole('button',{name:'Sunday reminder — completed'}));
 expect(await screen.findByRole('alert')).toHaveTextContent('Nothing has been copied or sent');expect(select).not.toHaveBeenCalled();
});
it('shows recoverable list errors without pretending there are no campaigns',async()=>{
 mocks.get.mockRejectedValue(new Error('offline'));render(<ExistingSmsCampaignPicker onSelect={vi.fn()}/>);fireEvent.click(screen.getByText('Choose an existing campaign'));
 expect(await screen.findByRole('alert')).toHaveTextContent('could not be loaded');expect(screen.queryByText('No matching campaigns. You can name a new campaign instead.')).not.toBeInTheDocument();
});
