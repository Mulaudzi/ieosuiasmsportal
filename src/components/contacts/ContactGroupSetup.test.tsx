import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ContactGroupSetup } from './ContactGroupSetup';
import { ContactGroupPicker } from './ContactGroupPicker';
const mocks=vi.hoisted(()=>({create:vi.fn(),refresh:vi.fn(),ready:vi.fn()}));
vi.mock('@/lib/api',()=>({createContactGroup:mocks.create,handleApiError:vi.fn()}));
vi.mock('./AddContactModal',()=>({AddContactModal:({defaultGroupId,onSuccess,onOpenChange}:any)=><div role="dialog" aria-label="Add fixture"><p>Destination {defaultGroupId}</p><button onClick={()=>{onSuccess();onOpenChange(false);}}>Save fixture contact</button></div>}));
vi.mock('./ContactImportModal',()=>({ContactImportModal:({defaultGroupId,onSuccess,onOpenChange}:any)=><div role="dialog" aria-label="Import fixture"><p>Import destination {defaultGroupId}</p><button onClick={()=>{onSuccess();onOpenChange(false);}}>Finish fixture import</button></div>}));
vi.mock('./GroupTransferModal',()=>({GroupTransferModal:({defaultTargetGroupId,sourceGroupId,onSuccess,onClose}:any)=><div role="dialog" aria-label="Transfer fixture"><p>{sourceGroupId} to {defaultTargetGroupId}</p><button onClick={()=>{onSuccess(defaultTargetGroupId);onClose();}}>Finish fixture transfer</button></div>}));
const groups=[{id:'uncategorized',name:'Uncategorized',contact_count:2,is_virtual:true}];
beforeEach(()=>{vi.clearAllMocks();mocks.create.mockResolvedValue({success:true,data:{group:{id:7,name:'Church members'}}});});
afterEach(cleanup);
const mount=(props={})=>render(<ContactGroupSetup groups={groups} loaded onRefresh={mocks.refresh} onReady={mocks.ready} {...props}/>);
it('waits for confirmed group loading and ignores virtual Uncategorized as a real group',async()=>{
 const view=mount({loaded:false});expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
 view.rerender(<ContactGroupSetup groups={groups} loaded onRefresh={mocks.refresh}/>);
 expect(await screen.findByRole('dialog')).toHaveTextContent('Create your first contact group');expect(mocks.create).not.toHaveBeenCalled();
});
it.each(['manual','import','existing'])('creates a group then guides %s addition to that exact group',async method=>{
 mount();await screen.findByRole('dialog');fireEvent.change(screen.getByLabelText('Group name'),{target:{value:' Church members '}});
 const button=screen.getByRole('button',{name:'Create group and continue'});fireEvent.click(button);fireEvent.click(button);
 await screen.findByText('Add contacts to Church members');expect(mocks.create).toHaveBeenCalledTimes(1);expect(mocks.create).toHaveBeenCalledWith('Church members');
 if(method==='manual'){fireEvent.click(screen.getByText('Add a contact manually'));expect(await screen.findByText('Destination 7')).toBeInTheDocument();fireEvent.click(screen.getByText('Save fixture contact'));}
 if(method==='import'){fireEvent.click(screen.getByText('Import a CSV or Excel file'));expect(await screen.findByText('Import destination 7')).toBeInTheDocument();fireEvent.click(screen.getByText('Finish fixture import'));}
 if(method==='existing'){fireEvent.click(screen.getByText('Add my 2 uncategorized contacts'));expect(await screen.findByText('uncategorized to 7')).toBeInTheDocument();fireEvent.click(screen.getByText('Finish fixture transfer'));}
 await waitFor(()=>expect(mocks.ready).toHaveBeenCalledWith('7'));expect(mocks.refresh).toHaveBeenCalled();expect(screen.getByRole('button',{name:'Done'})).toBeInTheDocument();
});
it('does not auto-prompt users with an actual group and allows dismissal',async()=>{
 const view=mount({groups:[...groups,{id:'8',name:'Existing',contact_count:0}]});expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
 fireEvent.click(screen.getByText('Create a group and add contacts'));await screen.findByRole('dialog');fireEvent.click(screen.getByText('Not now'));expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
 view.rerender(<ContactGroupSetup groups={[]} loaded onRefresh={mocks.refresh} autoPrompt={false}/>);expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});
it('hides empty Uncategorized only in the send picker, not other ordinary empty groups',()=>{
 const onChange=vi.fn();render(<ContactGroupPicker groups={[{id:'1',name:'Uncategorized',contact_count:0},{id:'2',name:'Empty group',contact_count:0},{id:'3',name:'Members',contact_count:5},...groups]} selectedIds={[]} onChange={onChange} hideEmptyUncategorized disableEmpty/>);
 expect(screen.queryByText('Uncategorized')).not.toBeInTheDocument();expect(screen.getByText('Empty group').closest('button')).toBeDisabled();fireEvent.click(screen.getByText('Members'));expect(onChange).toHaveBeenCalledWith(['3']);
});
