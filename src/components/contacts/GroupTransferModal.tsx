import { useRef, useState } from "react";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Loader2 } from "lucide-react";
import { createContactGroup, handleApiError, transferGroupContacts, type ContactGroupTransferResult } from "@/lib/api";
import { ContactGroupPicker } from './ContactGroupPicker';
import { Input } from '@/components/ui/input';
import { toast } from "@/hooks/use-toast";

export interface TransferGroup { id: string; name: string; contact_count: number; is_virtual?: boolean }
interface Props {
  mode: 'copy' | 'move' | 'merge';
  groups: TransferGroup[];
  sourceGroupId?: string;
  contactIds: string[];
  wholeGroup?: boolean;
  defaultTargetGroupId?: string;
  onClose: () => void;
  onSuccess: (targetGroupId: string) => void;
}

export function GroupTransferModal({ mode, groups, sourceGroupId = '', contactIds, wholeGroup = false, defaultTargetGroupId = '', onClose, onSuccess }: Props) {
  const [createdGroups, setCreatedGroups] = useState<TransferGroup[]>([]);
  const [groupName, setGroupName] = useState('');
  const [creating, setCreating] = useState(false);
  const realGroups = [...groups, ...createdGroups].filter((group, index, all) => !group.is_virtual && /^[1-9]\d*$/.test(String(group.id)) && all.findIndex(item => String(item.id) === String(group.id)) === index);
  const [sourceId, setSourceId] = useState(sourceGroupId);
  const [targetId, setTargetId] = useState(defaultTargetGroupId);
  const [deleteSource, setDeleteSource] = useState(false);
  const [busy, setBusy] = useState(false);
  const submitting = useRef(false);
  const source = sourceId === 'uncategorized' ? { id: sourceId, name: 'Uncategorized', contact_count: 0 } : realGroups.find(group => group.id === sourceId);
  const target = realGroups.find(group => group.id === targetId);
  const title = sourceId === 'uncategorized' ? 'Add contacts to a group' : mode === 'merge' ? 'Merge contact groups' : mode === 'move' ? 'Move contacts to group' : 'Copy contacts to group';
  const ready = Boolean(target && targetId !== sourceId && (mode === 'copy' || source) && (mode === 'merge' || wholeGroup || contactIds.length));
  const createGroup = async () => {
    if (!groupName.trim() || creating || busy) return;
    setCreating(true);
    try {
      const response = await createContactGroup(groupName.trim());
      const group = response.data?.group ?? (response as unknown as { group?: TransferGroup }).group;
      if (!response.success || !group?.id) throw new Error(response.message || 'Unable to create group.');
      const added = { ...group, id: String(group.id), contact_count: 0 };
      setCreatedGroups(previous => [...previous, added]); setTargetId(added.id); setGroupName('');
    } catch (error) { handleApiError(error); }
    finally { setCreating(false); }
  };

  const submit = async () => {
    if (!ready || submitting.current) return;
    submitting.current = true;
    setBusy(true);
    try {
      const response = await transferGroupContacts({
        mode, target_group_id: targetId,
        ...(sourceId ? { source_group_id: sourceId } : {}),
        ...(mode === 'merge' ? { delete_source: deleteSource } : wholeGroup ? { scope: 'group' as const } : { ids: contactIds }),
      });
      if (!response.success) throw new Error(response.message || 'Unable to update contact groups.');
      const result = (response.data ?? response) as ContactGroupTransferResult;
      toast({
        title: mode === 'merge' ? 'Groups merged' : mode === 'move' ? 'Contacts moved' : 'Contacts copied',
        description: `${result.added} added to ${target!.name}; ${result.already_present} already present and not duplicated.${mode !== 'copy' ? ` ${result.removed_from_source} removed from ${source!.name}.` : ''}${result.source_deleted ? ' The empty source group was removed.' : ''}`,
      });
      onSuccess(targetId);
      onClose();
    } catch (error) { handleApiError(error); }
    finally { submitting.current = false; setBusy(false); }
  };

  return <Dialog open onOpenChange={open => { if (!open && !submitting.current && !creating) onClose(); }}>
    <DialogContent className="max-h-[90dvh] overflow-y-auto">
      <DialogHeader>
        <DialogTitle>{title}</DialogTitle>
        <DialogDescription>{mode === 'merge' || wholeGroup
          ? 'Combine every contact from the source group into the destination, including contacts on other pages.'
          : `${contactIds.length} selected contact${contactIds.length === 1 ? '' : 's'} will be checked for duplicates before updating the destination.`}</DialogDescription>
      </DialogHeader>
      <div className="min-w-0 space-y-4 break-words">
        {(mode !== 'copy' || source) && <div className="space-y-2">
          <Label htmlFor="transfer-source">From group</Label>
          {sourceGroupId ? <p className="text-sm font-medium">{source?.name ?? 'Group no longer available'}</p> : <Select value={sourceId} disabled={busy} onValueChange={value => { setSourceId(value); if (value === targetId) setTargetId(''); }}>
            <SelectTrigger id="transfer-source"><SelectValue placeholder="Select the group to move from" /></SelectTrigger>
            <SelectContent>{realGroups.map(group => <SelectItem key={group.id} value={group.id}>{group.name}</SelectItem>)}</SelectContent>
          </Select>}
          {!sourceGroupId && mode === 'move' && <p className="text-xs text-muted-foreground">All selected contacts must belong to this source group. Their memberships in other groups will not change.</p>}
        </div>}
        <div className="space-y-2">
          <Label htmlFor="transfer-target">To group</Label>
          <ContactGroupPicker groups={realGroups.filter(group => group.id !== sourceId)} selectedIds={targetId ? [targetId] : []} onChange={ids => setTargetId(ids[0] ?? '')} disabled={busy || creating} label="Destination group" />
          <div className="rounded-lg border p-3 space-y-2"><Label htmlFor="new-transfer-group">Or create a new group</Label><div className="flex flex-wrap gap-2"><Input id="new-transfer-group" className="min-w-0 flex-1" maxLength={100} placeholder="New group name" value={groupName} disabled={busy || creating} onChange={event => setGroupName(event.target.value)} /><Button variant="outline" disabled={busy || creating || !groupName.trim()} onClick={createGroup}>{creating ? 'Creating...' : 'Create group'}</Button></div></div>
        </div>
        <div className="rounded-lg border bg-muted/40 p-3 space-y-2 text-sm">
          {target && <p className="font-medium">{source?.name ?? 'Selected contacts'} → {target.name}</p>}
          <p>{mode === 'copy'
            ? 'Copy adds contacts to the destination and keeps all their existing group memberships.'
            : 'Move adds contacts to the destination and removes only their membership in the source group. Their other groups stay unchanged.'}</p>
          <p>Matching phone numbers are not added twice. Contact details, opt-out settings and campaign history stay unchanged. No contacts are deleted.</p>
        </div>
        {mode === 'merge' && <div className="space-y-2">
          <div className="flex items-start gap-2">
            <Checkbox id="delete-merged-group" checked={deleteSource} disabled={busy} onCheckedChange={value => setDeleteSource(value === true)} />
            <Label htmlFor="delete-merged-group">Remove the empty source group after merging</Label>
          </div>
          <p className="text-xs text-muted-foreground">{deleteSource
            ? `Only the group "${source?.name ?? ''}" will be removed, not its contacts. Update any saved drafts that use this group.`
            : 'The source group will remain available but empty.'}</p>
        </div>}
      </div>
      <DialogFooter>
        <Button variant="outline" disabled={busy || creating} onClick={onClose}>Cancel</Button>
        <Button disabled={!ready || busy || creating} onClick={submit}>{busy && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}{sourceId === 'uncategorized' ? 'Add to group' : mode === 'merge' ? 'Merge groups' : mode === 'move' ? 'Move contacts' : 'Copy contacts'}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>;
}
