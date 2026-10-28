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
import { updateContact, getContactGroups, handleApiError } from "@/lib/api";
import { ApiError } from '@/lib/api-error';

interface Group {
  id: string;
  name: string;
}

interface Contact {
  id: string;
  name: string;
  phone: string;
  email: string;
  group_id?: string;
  group_name?: string;
}

interface EditContactModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  contact: Contact | null;
  onSuccess?: () => void;
}

export function EditContactModal({ open, onOpenChange, contact, onSuccess }: EditContactModalProps) {
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [groupId, setGroupId] = useState("");
  const [saving, setSaving] = useState(false);
  const [groups, setGroups] = useState<Group[]>([]);
  const [loadingGroups, setLoadingGroups] = useState(false);
  const [duplicate, setDuplicate] = useState(false);

  useEffect(() => {
    if (open && contact) {
      setName(contact.name || "");
      setPhone(contact.phone || "");
      setEmail(contact.email || "");
      setGroupId("__keep__");
      setDuplicate(false);
      loadGroups();
    }
  }, [open, contact]);

  const loadGroups = async () => {
    setLoadingGroups(true);
    try {
      const res = await getContactGroups();
      if (res.success) {
        // Backend returns merged format: { success: true, groups: [...] }
        const groupsData = (res as typeof res & {groups?: Array<Group & {is_virtual?: boolean}>}).groups || [];
        setGroups(Array.isArray(groupsData) ? groupsData.filter(group => !group.is_virtual && /^[1-9]\d*$/.test(String(group.id))) : []);
      }
    } catch (error) {
      console.error("Failed to load groups:", error);
    } finally {
      setLoadingGroups(false);
    }
  };

  const handleClose = () => {
    onOpenChange(false);
  };

  const handleSave = async (reuseExisting = false) => {
    if (!contact) return;
    
    if (!phone && !email) {
      toast({
        title: "Contact info required",
        description: "Please enter a phone number or email address.",
        variant: "destructive",
      });
      return;
    }

    setSaving(true);
    try {
      const response = await updateContact(contact.id, {
        name: name || "Esteemed",
        phone: phone || undefined,
        email: email || undefined,
        ...(reuseExisting ? {reuse_existing:true} : groupId === "__keep__" ? {} : { group_id: groupId === "none" ? "" : groupId }),
      });

      if (response.success) {
        toast({
          title: reuseExisting ? 'Existing contact used' : "Contact updated",
          description: reuseExisting ? 'Group memberships now use the saved number. Other details, opt-outs and message history are unchanged.' : `${name || phone || email} has been updated.`,
        });
        onSuccess?.();
        handleClose();
      }
    } catch (error) {
      if(error instanceof ApiError && error.status === 409 && error.message.startsWith('This number is already saved.'))setDuplicate(true);
      else handleApiError(error);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit Contact</DialogTitle>
          <DialogDescription>
            Update contact information
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="editName">Name</Label>
            <Input
              id="editName"
              placeholder="John Smith"
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="editPhone">Phone Number *</Label>
            <Input
              id="editPhone"
              placeholder="+27 82 123 4567"
              value={phone}
              onChange={(e) => {setPhone(e.target.value);setDuplicate(false);}}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="editEmail">Email</Label>
            <Input
              id="editEmail"
              type="email"
              placeholder="john@example.com"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="editGroup">Group</Label>
            <Select value={groupId} onValueChange={setGroupId}>
              <SelectTrigger id="editGroup">
                <SelectValue placeholder={loadingGroups ? "Loading..." : "Select a group"} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__keep__">Keep existing groups</SelectItem>
                <SelectItem value="none">No Group</SelectItem>
                {groups.map((g) => (
                  <SelectItem key={g.id} value={String(g.id)}>{g.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">{groupId === "__keep__"
              ? `Existing memberships will be kept${contact?.group_name ? `: ${contact.group_name}` : '.'}`
              : 'This replaces all existing group memberships. To keep them, use Copy to Group instead.'}</p>
          </div>
        </div>

        {duplicate && <div role="alert" className="space-y-3 rounded-lg border border-primary/30 bg-primary/5 p-4 text-sm"><p>This number is already saved in your account. Use that saved contact in all of this contact’s groups instead?</p><p className="text-xs text-muted-foreground">The saved contact keeps its details, opt-outs and other groups. The original mistyped contact remains saved without these group memberships. No contacts or past messages are deleted, and edits to name or email here will not be applied.</p><Button variant="outline" disabled={saving} onClick={()=>void handleSave(true)}>Use existing contact in these groups</Button></div>}

        <DialogFooter>
          <Button variant="outline" onClick={handleClose}>
            Cancel
          </Button>
          <Button onClick={()=>void handleSave()} disabled={saving}>
            {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            Save Changes
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
