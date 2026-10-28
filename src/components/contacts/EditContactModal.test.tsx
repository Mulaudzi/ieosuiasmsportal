import {cleanup,fireEvent,render,screen,waitFor} from '@testing-library/react';
import {afterEach,expect,it,vi} from 'vitest';
import {EditContactModal} from './EditContactModal';
import {updateContact} from '@/lib/api';
import {ApiError} from '@/lib/api-error';
vi.mock('@/hooks/use-toast',()=>({toast:vi.fn()}));
vi.mock('@/lib/api',()=>({getContactGroups:vi.fn(async()=>({success:true,groups:[]})),updateContact:vi.fn(),handleApiError:vi.fn()}));
afterEach(()=>{cleanup();vi.clearAllMocks();});
it('offers explicit safe reuse after a duplicate-number correction, preserving existing groups',async()=>{
 vi.mocked(updateContact).mockRejectedValueOnce(new ApiError('This number is already saved. Use the saved contact.',409)).mockResolvedValueOnce({success:true});
 const done=vi.fn();render(<EditContactModal open onOpenChange={()=>{}} onSuccess={done} contact={{id:'1',name:'Wrong number',phone:'0799282775',email:''}}/>);
 fireEvent.change(screen.getByLabelText('Phone Number *'),{target:{value:'0799292775'}});fireEvent.click(screen.getByRole('button',{name:'Save Changes'}));
 await screen.findByText(/Use that saved contact in all/);expect(done).not.toHaveBeenCalled();expect(updateContact).toHaveBeenCalledTimes(1);
 fireEvent.click(screen.getByRole('button',{name:'Use existing contact in these groups'}));
 await waitFor(()=>expect(done).toHaveBeenCalledTimes(1));expect(updateContact).toHaveBeenLastCalledWith('1',expect.objectContaining({phone:'0799292775',reuse_existing:true}));
 expect(vi.mocked(updateContact).mock.lastCall?.[1]).not.toHaveProperty('group_id');
});
