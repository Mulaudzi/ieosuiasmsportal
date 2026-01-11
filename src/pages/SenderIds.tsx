import { useState, useEffect } from "react";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
  Plus,
  Key,
  Mail,
  MessageSquare,
  Check,
  Clock,
  XCircle,
  Star,
  Trash2,
  Loader2,
  RefreshCw,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { api, handleApiError } from "@/lib/api";

interface SenderId {
  id: string;
  type: "sms" | "email";
  sender_id: string | null;
  sender_email: string | null;
  sender_name: string | null;
  status: "pending" | "approved" | "rejected";
  is_default: boolean;
  created_at: string;
  verified_at: string | null;
}

const statusConfig = {
  pending: { label: "Pending", class: "status-pending", icon: Clock },
  approved: { label: "Approved", class: "status-delivered", icon: Check },
  rejected: { label: "Rejected", class: "status-failed", icon: XCircle },
};

export default function SenderIds() {
  const [senderIds, setSenderIds] = useState<SenderId[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<"all" | "sms" | "email">("all");
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [deleteId, setDeleteId] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [settingDefault, setSettingDefault] = useState<string | null>(null);

  // Form state
  const [formType, setFormType] = useState<"sms" | "email">("sms");
  const [formSenderId, setFormSenderId] = useState("");
  const [formSenderEmail, setFormSenderEmail] = useState("");
  const [formSenderName, setFormSenderName] = useState("");

  useEffect(() => {
    loadSenderIds();
  }, []);

  const loadSenderIds = async () => {
    setLoading(true);
    try {
      const response = await api.get<{ sender_ids: SenderId[] }>("/sender-ids");
      if (response.success && response.data) {
        setSenderIds(response.data.sender_ids);
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoading(false);
    }
  };

  const handleCreate = async () => {
    if (formType === "sms" && !formSenderId) {
      toast({ title: "Error", description: "Sender ID is required", variant: "destructive" });
      return;
    }
    if (formType === "email" && !formSenderEmail) {
      toast({ title: "Error", description: "Sender email is required", variant: "destructive" });
      return;
    }

    setSaving(true);
    try {
      const response = await api.post<{ sender_id: SenderId }>("/sender-ids", {
        type: formType,
        sender_id: formType === "sms" ? formSenderId : null,
        sender_email: formType === "email" ? formSenderEmail : null,
        sender_name: formSenderName || null,
      });

      if (response.success && response.data) {
        setSenderIds([response.data.sender_id, ...senderIds]);
        setIsModalOpen(false);
        resetForm();
        toast({
          title: "Sender ID created",
          description: "Your sender ID is pending approval.",
        });
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!deleteId) return;

    try {
      await api.delete(`/sender-ids/${deleteId}`);
      setSenderIds(senderIds.filter((s) => s.id !== deleteId));
      toast({ title: "Sender ID deleted" });
    } catch (error) {
      handleApiError(error);
    } finally {
      setDeleteId(null);
    }
  };

  const handleSetDefault = async (id: string) => {
    setSettingDefault(id);
    try {
      await api.post(`/sender-ids/${id}/default`);
      setSenderIds(
        senderIds.map((s) => ({
          ...s,
          is_default: s.id === id ? true : s.type === senderIds.find((x) => x.id === id)?.type ? false : s.is_default,
        }))
      );
      toast({ title: "Default sender ID updated" });
    } catch (error) {
      handleApiError(error);
    } finally {
      setSettingDefault(null);
    }
  };

  const resetForm = () => {
    setFormType("sms");
    setFormSenderId("");
    setFormSenderEmail("");
    setFormSenderName("");
  };

  const filteredIds = senderIds.filter((s) => filter === "all" || s.type === filter);

  const smsSenders = senderIds.filter((s) => s.type === "sms");
  const emailSenders = senderIds.filter((s) => s.type === "email");

  return (
    <DashboardLayout
      title="Sender IDs"
      subtitle="Manage your SMS and email sender identities"
      actions={
        <Button onClick={() => setIsModalOpen(true)} className="gap-2">
          <Plus className="h-4 w-4" />
          Add Sender ID
        </Button>
      }
    >
      {/* Stats */}
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm text-muted-foreground">Total Sender IDs</p>
          <p className="mt-1 text-2xl font-bold">{senderIds.length}</p>
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <div className="flex items-center gap-2">
            <MessageSquare className="h-4 w-4 text-primary" />
            <p className="text-sm text-muted-foreground">SMS Senders</p>
          </div>
          <p className="mt-1 text-2xl font-bold">{smsSenders.length}</p>
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <div className="flex items-center gap-2">
            <Mail className="h-4 w-4 text-accent" />
            <p className="text-sm text-muted-foreground">Email Senders</p>
          </div>
          <p className="mt-1 text-2xl font-bold">{emailSenders.length}</p>
        </div>
      </div>

      {/* Filter */}
      <div className="mb-6 flex items-center justify-between">
        <Select value={filter} onValueChange={(v) => setFilter(v as any)}>
          <SelectTrigger className="w-40">
            <SelectValue placeholder="Filter" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All Types</SelectItem>
            <SelectItem value="sms">SMS Only</SelectItem>
            <SelectItem value="email">Email Only</SelectItem>
          </SelectContent>
        </Select>
        <Button variant="outline" size="icon" onClick={loadSenderIds} disabled={loading}>
          <RefreshCw className={cn("h-4 w-4", loading && "animate-spin")} />
        </Button>
      </div>

      {/* Sender IDs List */}
      <div className="space-y-4">
        {loading ? (
          <div className="flex h-64 items-center justify-center">
            <Loader2 className="h-8 w-8 animate-spin text-primary" />
          </div>
        ) : filteredIds.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-xl border border-border bg-card py-12">
            <Key className="h-12 w-12 text-muted-foreground/50" />
            <p className="mt-4 text-lg font-medium text-foreground">No sender IDs found</p>
            <p className="mt-1 text-sm text-muted-foreground">
              Add a sender ID to start sending messages
            </p>
            <Button onClick={() => setIsModalOpen(true)} className="mt-4 gap-2">
              <Plus className="h-4 w-4" />
              Add Sender ID
            </Button>
          </div>
        ) : (
          filteredIds.map((sender) => {
            const StatusIcon = statusConfig[sender.status].icon;
            return (
              <div
                key={sender.id}
                className="flex items-center justify-between rounded-xl border border-border bg-card p-4"
              >
                <div className="flex items-center gap-4">
                  <div
                    className={cn(
                      "flex h-12 w-12 items-center justify-center rounded-xl",
                      sender.type === "sms" ? "bg-primary/10 text-primary" : "bg-accent/10 text-accent"
                    )}
                  >
                    {sender.type === "sms" ? (
                      <MessageSquare className="h-6 w-6" />
                    ) : (
                      <Mail className="h-6 w-6" />
                    )}
                  </div>
                  <div>
                    <div className="flex items-center gap-2">
                      <p className="font-semibold text-foreground">
                        {sender.type === "sms" ? sender.sender_id : sender.sender_email}
                      </p>
                      {sender.is_default && (
                        <span className="flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                          <Star className="h-3 w-3" />
                          Default
                        </span>
                      )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                      {sender.sender_name || "No display name"} • {sender.type.toUpperCase()}
                    </p>
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <span className={cn("status-badge inline-flex items-center gap-1", statusConfig[sender.status].class)}>
                    <StatusIcon className="h-3 w-3" />
                    {statusConfig[sender.status].label}
                  </span>
                  {!sender.is_default && sender.status === "approved" && (
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => handleSetDefault(sender.id)}
                      disabled={settingDefault === sender.id}
                    >
                      {settingDefault === sender.id ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                      ) : (
                        "Set Default"
                      )}
                    </Button>
                  )}
                  <Button
                    variant="ghost"
                    size="icon"
                    onClick={() => setDeleteId(sender.id)}
                    className="text-destructive hover:text-destructive"
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            );
          })
        )}
      </div>

      {/* Create Modal */}
      <Dialog open={isModalOpen} onOpenChange={setIsModalOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add Sender ID</DialogTitle>
            <DialogDescription>
              Create a new sender ID for SMS or email campaigns. New IDs require approval.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-4">
            <div>
              <Label>Type</Label>
              <Select value={formType} onValueChange={(v) => setFormType(v as "sms" | "email")}>
                <SelectTrigger className="mt-1.5">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="sms">SMS</SelectItem>
                  <SelectItem value="email">Email</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {formType === "sms" ? (
              <div>
                <Label>Sender ID</Label>
                <Input
                  placeholder="e.g., MYCOMPANY"
                  value={formSenderId}
                  onChange={(e) => setFormSenderId(e.target.value.toUpperCase())}
                  maxLength={11}
                  className="mt-1.5"
                />
                <p className="mt-1 text-xs text-muted-foreground">
                  3-11 alphanumeric characters
                </p>
              </div>
            ) : (
              <div>
                <Label>Sender Email</Label>
                <Input
                  type="email"
                  placeholder="e.g., noreply@company.com"
                  value={formSenderEmail}
                  onChange={(e) => setFormSenderEmail(e.target.value)}
                  className="mt-1.5"
                />
              </div>
            )}

            <div>
              <Label>Display Name (optional)</Label>
              <Input
                placeholder="e.g., My Company"
                value={formSenderName}
                onChange={(e) => setFormSenderName(e.target.value)}
                className="mt-1.5"
              />
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setIsModalOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleCreate} disabled={saving}>
              {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Create Sender ID
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation */}
      <AlertDialog open={!!deleteId} onOpenChange={() => setDeleteId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Sender ID?</AlertDialogTitle>
            <AlertDialogDescription>
              This action cannot be undone. Campaigns using this sender ID may need to be updated.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={handleDelete} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </DashboardLayout>
  );
}
