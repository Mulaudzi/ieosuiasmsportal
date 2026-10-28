import { useState, useEffect } from "react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Loader2 } from "lucide-react";
import { toast } from "@/hooks/use-toast";
import { createContact, createContactGroup, getContactGroups, handleApiError } from "@/lib/api";
import { ContactGroupPicker } from './ContactGroupPicker';

interface Group {
  id: string;
  name: string;
}

interface AddContactModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: () => void;
  defaultGroupId?: string;
}

export function AddContactModal({ open, onOpenChange, onSuccess, defaultGroupId }: AddContactModalProps) {
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [groupId, setGroupId] = useState("");
  const [saving, setSaving] = useState(false);
  const [groups, setGroups] = useState<Group[]>([]);
  const [loadingGroups, setLoadingGroups] = useState(false);
  const [newGroupName, setNewGroupName] = useState('');
  const [creatingGroup, setCreatingGroup] = useState(false);

  useEffect(() => {
    if (open) {
      setGroupId(defaultGroupId ?? '');
      loadGroups();
    }
  }, [open]);

  const loadGroups = async () => {
    setLoadingGroups(true);
    try {
      const res = await getContactGroups();
      if (res.success) {
        // Backend returns merged format: { success: true, groups: [...] }
        const groupsData = res.groups ?? res.data?.groups ?? [];
        setGroups(Array.isArray(groupsData) ? groupsData.filter(g => !g.is_virtual && /^[1-9]\d*$/.test(String(g.id))).map(g => ({...g, id:String(g.id)})) : []);
      }
    } catch (error) {
      console.error("Failed to load groups:", error);
    } finally {
      setLoadingGroups(false);
    }
  };

  const resetForm = () => {
    setName("");
    setPhone("");
    setEmail("");
    setGroupId("");
  };

  const handleClose = () => {
    if (saving || creatingGroup) return;
    resetForm();
    onOpenChange(false);
  };

  const handleSave = async () => {
    if (!phone && !email) {
      toast({
        title: "Contact info required",
        description: "Please enter a phone number or email address.",
        variant: "destructive",
      });
      return;
    }

    setSaving(true);
    if (!groupId && !window.confirm('This contact has no group. We recommend choosing or creating a group so it is easy to select when sending SMS. Save in Uncategorized anyway?')) { setSaving(false); return; }
    try {
      const response = await createContact({
        name: name || "Esteemed",
        phone: phone || undefined,
        email: email || undefined,
        group_id: groupId || undefined,
      });

      if (response.success) {
        const result = (response.data ?? response) as { existing_contact_reused?: boolean; already_in_group?: boolean };
        const groupName = groups.find(group => group.id === groupId)?.name ?? 'the selected group';
        toast({
          title: result.already_in_group ? 'Contact already in group' : result.existing_contact_reused ? 'Existing contact added to group' : 'Contact added',
          description: result.existing_contact_reused
            ? `${phone} ${result.already_in_group ? 'is already in' : 'has been added to'} ${groupName}. Saved details and other groups are unchanged.`
            : `${name || phone || email} has been added to your contacts.`,
        });
        onSuccess?.();
        resetForm(); onOpenChange(false);
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Add Contact</DialogTitle>
          <DialogDescription>
            Add a new contact, or enter a saved number and choose a group to add it there. Existing contact details and other groups stay unchanged.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="name">Name</Label>
            <Input
              id="name"
              placeholder="John Smith"
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="phone">Phone Number *</Label>
            <Input
              id="phone"
              placeholder="+27 82 123 4567"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="email">Email</Label>
            <Input
              id="email"
              type="email"
              placeholder="john@example.com"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
          </div>

          <div className="space-y-2">
            <Label>Add to group (recommended)</Label>
            {loadingGroups ? <p role="status">Loading groups...</p> : <ContactGroupPicker groups={groups} selectedIds={groupId ? [groupId] : []} onChange={ids => setGroupId(ids[0] ?? '')} disabled={saving || creatingGroup} />}
            <Label htmlFor="add-contact-new-group">Or create a group</Label><div className="flex gap-2"><Input id="add-contact-new-group" placeholder="Group name" maxLength={100} value={newGroupName} onChange={e => setNewGroupName(e.target.value)} disabled={saving || creatingGroup} /><Button variant="outline" disabled={saving || creatingGroup || !newGroupName.trim()} onClick={async () => {setCreatingGroup(true);try {const res=await createContactGroup(newGroupName.trim());const group=res.data?.group ?? (res as unknown as {group?:Group}).group;if(!res.success || !group?.id)throw new Error('Unable to create group.');setGroups(prev=>[...prev,{...group,id:String(group.id)}]);setGroupId(String(group.id));setNewGroupName('');}catch(error){handleApiError(error);}finally{setCreatingGroup(false);}}}>Create group</Button></div>
            {!groupId && <p className="text-sm text-muted-foreground">Without a group, this contact will appear in Uncategorized.</p>}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={handleClose}>
            Cancel
          </Button>
          <Button onClick={handleSave} disabled={saving || creatingGroup || loadingGroups}>
            {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            Add Contact
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
