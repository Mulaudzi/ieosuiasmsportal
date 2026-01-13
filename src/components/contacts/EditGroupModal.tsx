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
import { Textarea } from "@/components/ui/textarea";
import { Loader2 } from "lucide-react";
import { toast } from "@/hooks/use-toast";
import { updateContactGroup } from "@/lib/api";

interface Group {
  id: string;
  name: string;
  description?: string;
  contact_count?: number;
}

interface EditGroupModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  group: Group | null;
  onSuccess?: () => void;
}

export function EditGroupModal({ open, onOpenChange, group, onSuccess }: EditGroupModalProps) {
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (group) {
      setName(group.name);
      setDescription(group.description || "");
    }
  }, [group, open]);

  const handleClose = () => {
    onOpenChange(false);
  };

  const handleSave = async () => {
    if (!group) return;
    
    if (!name.trim()) {
      toast({
        title: "Name required",
        description: "Please enter a group name.",
        variant: "destructive",
      });
      return;
    }

    setSaving(true);
    try {
      const response = await updateContactGroup(group.id, name, description || undefined);
      if (response.success) {
        toast({
          title: "Group updated",
          description: `"${name}" has been updated.`,
        });
        onSuccess?.();
        handleClose();
      }
    } catch (error) {
      toast({
        title: "Failed to update group",
        description: error instanceof Error ? error.message : "Please try again.",
        variant: "destructive",
      });
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit Group</DialogTitle>
          <DialogDescription>
            Update the contact group details
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="editGroupName">Group Name *</Label>
            <Input
              id="editGroupName"
              placeholder="e.g., VIP Customers"
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="editDescription">Description (optional)</Label>
            <Textarea
              id="editDescription"
              placeholder="Add a description for this group..."
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              rows={3}
            />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={handleClose}>
            Cancel
          </Button>
          <Button onClick={handleSave} disabled={saving || !name.trim()}>
            {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            Update Group
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
