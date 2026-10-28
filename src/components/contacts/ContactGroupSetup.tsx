import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { createContactGroup, getContactGroups, handleApiError } from '@/lib/api';
import { AddContactModal } from './AddContactModal';
import { ContactImportModal } from './ContactImportModal';
import { GroupTransferModal } from './GroupTransferModal';
import { type PickableContactGroup } from './ContactGroupPicker';
import { useAuth } from '@/hooks/useAuth';
import { useQuery } from '@tanstack/react-query';

export function realContactGroups(groups: PickableContactGroup[]) {
  return groups.filter(group => !group.is_virtual && /^[1-9]\d*$/.test(String(group.id)));
}

export function ContactGroupSetup({ groups, loaded, onRefresh, onReady, autoPrompt = true, showTrigger = true }: {
  groups: PickableContactGroup[]; loaded: boolean;
  onRefresh: () => void | Promise<unknown>; onReady?: (groupId: string) => void;
  autoPrompt?: boolean; showTrigger?: boolean;
}) {
  const [open, setOpen] = useState(false), [name, setName] = useState(''), [creating, setCreating] = useState(false);
  const saving = useRef(false), prompted = useRef(false);
  const [created, setCreated] = useState<PickableContactGroup | null>(null);
  const [action, setAction] = useState<'add' | 'import' | 'existing' | null>(null);
  const [contactStepComplete,setContactStepComplete] = useState(false);
  const real = realContactGroups(groups), uncategorized = groups.find(group => group.id === 'uncategorized');
  useEffect(() => {
    if (loaded && autoPrompt && !real.length && !prompted.current) {prompted.current = true; setOpen(true);}
  }, [loaded, autoPrompt, real.length]);
  const refresh = () => { void onRefresh(); };
  const ready = () => {setContactStepComplete(true); refresh(); if (created) onReady?.(created.id);};
  const create = async () => {
    if (!name.trim() || saving.current) return;
    saving.current = true; setCreating(true);
    try {
      const response = await createContactGroup(name.trim());
      const group = response.data?.group ?? (response as unknown as {group?: PickableContactGroup}).group;
      if (!response.success || !group?.id) throw new Error('The group could not be created. Please try again.');
      setCreated({...group, id: String(group.id), contact_count: 0}); setName(''); refresh();
    } catch (error) { handleApiError(error); }
    finally { saving.current = false; setCreating(false); }
  };
  const destinations = created && !groups.some(group => group.id === created.id) ? [...groups, created] : groups;
  return <>
    {showTrigger && <Button type="button" variant="outline" onClick={() => setOpen(true)}>Create a group and add contacts</Button>}
    <Dialog open={open && !action} onOpenChange={next => {if (!saving.current) setOpen(next);}}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{created ? `Add contacts to ${created.name}` : real.length ? 'Create a contact group' : 'Create your first contact group'}</DialogTitle>
          <DialogDescription>{created ? 'Your group is ready. Choose how to add contacts; the new group will be selected for you.' : 'Groups make it easy to select recipients when sending SMS. Start with a name such as Customers or Church members.'}</DialogDescription>
        </DialogHeader>
        {!created ? <div className="space-y-4">
          <Label htmlFor="setup-group-name">Group name</Label><Input id="setup-group-name" maxLength={100} placeholder="For example: Church members" value={name} onChange={e => setName(e.target.value)} disabled={creating} />
          <div className="flex flex-wrap gap-2"><Button disabled={creating || !name.trim()} onClick={() => void create()}>{creating ? 'Creating group...' : 'Create group and continue'}</Button><Button variant="ghost" disabled={creating} onClick={() => setOpen(false)}>Not now</Button></div>
        </div> : <div className="space-y-3">
          <p className="text-sm">Step 2 of 2: add contacts to <strong>{created.name}</strong>.</p>
          <div className="grid gap-2"><Button onClick={() => setAction('import')}>Import a CSV or Excel file</Button><Button variant="outline" onClick={() => setAction('add')}>Add a contact manually</Button>
          {Number(uncategorized?.contact_count) > 0 && <Button variant="outline" className="h-auto min-h-10 whitespace-normal" onClick={() => setAction('existing')}>Add my {Number(uncategorized.contact_count).toLocaleString()} uncategorized contacts</Button>}</div>
          <Button variant={contactStepComplete?'default':'ghost'} onClick={() => setOpen(false)}>{contactStepComplete?'Done':"I'll add contacts later"}</Button>
        </div>}
      </DialogContent>
    </Dialog>
    {action === 'add' && created && <AddContactModal open defaultGroupId={created.id} onOpenChange={next => {if (!next) {setAction(null); refresh();}}} onSuccess={ready} />}
    {action === 'import' && created && <ContactImportModal open groups={destinations} defaultGroupId={created.id} onOpenChange={next => {if (!next) {setAction(null); refresh();}}} onSuccess={ready} />}
    {action === 'existing' && created && <GroupTransferModal mode="copy" sourceGroupId="uncategorized" wholeGroup contactIds={[]} groups={realContactGroups(destinations).map(group => ({...group, contact_count: group.contact_count ?? 0}))} defaultTargetGroupId={created.id} onClose={() => setAction(null)} onSuccess={() => ready()} />}
  </>;
}

/** Failed lookups must never be treated as an empty account. */
export function FirstContactGroupPrompt() {
  const {user} = useAuth();
  const query = useQuery({queryKey: ['contact-group-setup', user?.id], enabled: !!user, retry: false, queryFn: async () => {
    const response = await getContactGroups();
    if (!response.success) throw new Error('Groups unavailable');
    const groups = response.groups ?? response.data?.groups;
    if (!Array.isArray(groups)) throw new Error('Groups unavailable');
    return groups.map(group => ({...group, id: String(group.id)})) as PickableContactGroup[];
  }});
  return <ContactGroupSetup key={user?.id} groups={query.data ?? []} loaded={query.isSuccess} onRefresh={() => query.refetch()} showTrigger={false} />;
}
