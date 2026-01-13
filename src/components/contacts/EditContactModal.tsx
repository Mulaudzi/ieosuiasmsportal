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

  useEffect(() => {
    if (open && contact) {
      setName(contact.name || "");
      setPhone(contact.phone || "");
      setEmail(contact.email || "");
      setGroupId(contact.group_id || "");
      loadGroups();
    }
  }, [open, contact]);

  const loadGroups = async () => {
    setLoadingGroups(true);
    try {
      const res = await getContactGroups();
      if (res.success && res.data) {
        const data = res.data as { groups?: Group[] } | Group[];
        const groupsData = Array.isArray(data) ? data : (data.groups || []);
        setGroups(groupsData);
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

  const handleSave = async () => {
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
        group_id: groupId || undefined,
      });

      if (response.success) {
        toast({
          title: "Contact updated",
          description: `${name || phone || email} has been updated.`,
        });
        onSuccess?.();
        handleClose();
      }
    } catch (error) {
      handleApiError(error);
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
              onChange={(e) => setPhone(e.target.value)}
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
              <SelectTrigger>
                <SelectValue placeholder={loadingGroups ? "Loading..." : "Select a group"} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="none">No Group</SelectItem>
                {groups.map((g) => (
                  <SelectItem key={g.id} value={String(g.id)}>{g.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={handleClose}>
            Cancel
          </Button>
          <Button onClick={handleSave} disabled={saving}>
            {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            Save Changes
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
