import {cleanup,fireEvent,render,screen,waitFor} from '@testing-library/react';
import {afterEach,expect,it,vi} from 'vitest';
import {AddContactModal} from './AddContactModal';
const mocks=vi.hoisted(()=>({create:vi.fn(),toast:vi.fn(),error:vi.fn()}));
vi.mock('@/hooks/use-toast',()=>({toast:mocks.toast}));
vi.mock('@/lib/api',()=>({createContact:mocks.create,getContactGroups:vi.fn(async()=>({success:true,groups:[{id:2,name:'test'}]})),createContactGroup:vi.fn(),handleApiError:mocks.error}));
vi.mock('./ContactGroupPicker',()=>({ContactGroupPicker:()=> <div>test group selected</div>}));
afterEach(()=>{cleanup();vi.clearAllMocks();});
it.each([false,true])('explains reused contact membership, already present: %s',async(already)=>{
 mocks.create.mockResolvedValue({success:true,existing_contact_reused:true,already_in_group:already,group_membership_added:!already});
 const success=vi.fn(),close=vi.fn();render(<AddContactModal open onOpenChange={close} onSuccess={success} defaultGroupId="2"/>);
 fireEvent.change(screen.getByLabelText('Phone Number *'),{target:{value:'0638082493'}});
 await waitFor(()=>expect(screen.getByRole('button',{name:'Add Contact'})).not.toBeDisabled());fireEvent.click(screen.getByRole('button',{name:'Add Contact'}));
 await waitFor(()=>expect(success).toHaveBeenCalledOnce());expect(close).toHaveBeenCalledWith(false);
 expect(mocks.create).toHaveBeenCalledWith(expect.objectContaining({phone:'0638082493',group_id:'2'}));
 expect(mocks.toast).toHaveBeenCalledWith(expect.objectContaining({title:already?'Contact already in group':'Existing contact added to group',description:expect.stringContaining('Saved details and other groups are unchanged.')}));
 expect(mocks.error).not.toHaveBeenCalled();
});
