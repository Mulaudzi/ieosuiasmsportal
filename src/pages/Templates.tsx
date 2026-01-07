import { useState, useEffect } from "react";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
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
  Search,
  MessageSquare,
  Mail,
  Edit,
  Trash2,
  Copy,
  Loader2,
} from "lucide-react";
import { TemplateModal } from "@/components/templates/TemplateModal";
import { TemplateCardSkeleton } from "@/components/ui/loading-skeleton";
import { getTemplates, deleteTemplate, createTemplate, handleApiError } from "@/lib/api";
import { toast } from "@/hooks/use-toast";

interface Template {
  id: string;
  name: string;
  type: "sms" | "email";
  content: string;
  subject?: string;
  usage_count?: number;
  updated_at?: string;
}

// Fallback mock data for demo
const mockTemplates: Template[] = [
  {
    id: "1",
    name: "Welcome Message",
    type: "sms",
    content: "Welcome to IEOSUIA! We're excited to have you on board. Reply HELP for assistance.",
    usage_count: 1250,
    updated_at: "2026-01-07T08:00:00Z",
  },
  {
    id: "2",
    name: "Order Confirmation",
    type: "sms",
    content: "Your order #{{order_id}} has been confirmed. Track it at {{tracking_url}}",
    usage_count: 890,
    updated_at: "2026-01-07T05:00:00Z",
  },
  {
    id: "3",
    name: "Flash Sale Alert",
    type: "sms",
    content: "🔥 Flash Sale! Get 50% off all items for the next 24 hours. Shop now: {{link}}",
    usage_count: 450,
    updated_at: "2026-01-06T10:00:00Z",
  },
  {
    id: "4",
    name: "Weekly Newsletter",
    type: "email",
    content: "<!DOCTYPE html><html><body><h1>Weekly Update</h1><p>Hello {{name}},</p></body></html>",
    usage_count: 320,
    updated_at: "2026-01-04T10:00:00Z",
  },
  {
    id: "5",
    name: "Password Reset",
    type: "email",
    content: "<!DOCTYPE html><html><body><p>Click here to reset: {{link}}</p></body></html>",
    usage_count: 1500,
    updated_at: "2026-01-07T09:00:00Z",
  },
];

export default function Templates() {
  const [templates, setTemplates] = useState<Template[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [modalOpen, setModalOpen] = useState(false);
  const [editingTemplate, setEditingTemplate] = useState<Template | null>(null);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [templateToDelete, setTemplateToDelete] = useState<Template | null>(null);
  const [deleting, setDeleting] = useState(false);

  useEffect(() => {
    loadTemplates();
  }, []);

  const loadTemplates = async () => {
    setLoading(true);
    try {
      const response = await getTemplates();
      if (response.success && response.data) {
        const responseData = response.data as any;
        setTemplates(Array.isArray(responseData) ? responseData : responseData.data || []);
      } else {
        // Use mock data as fallback
        setTemplates(mockTemplates);
      }
    } catch (error) {
      // Use mock data on error
      setTemplates(mockTemplates);
    } finally {
      setLoading(false);
    }
  };

  const handleCreate = (type: "sms" | "email") => {
    setEditingTemplate({ id: "", name: "", type, content: "" });
    setModalOpen(true);
  };

  const handleEdit = (template: Template) => {
    setEditingTemplate(template);
    setModalOpen(true);
  };

  const handleDuplicate = async (template: Template) => {
    try {
      const response = await createTemplate({
        name: `${template.name} (Copy)`,
        content: template.content,
        type: template.type,
      });
      if (response.success) {
        toast({ title: "Template duplicated", description: `${template.name} has been copied.` });
        loadTemplates();
      }
    } catch (error) {
      handleApiError(error);
    }
  };

  const handleDeleteConfirm = (template: Template) => {
    setTemplateToDelete(template);
    setDeleteDialogOpen(true);
  };

  const handleDelete = async () => {
    if (!templateToDelete) return;
    setDeleting(true);
    try {
      const response = await deleteTemplate(templateToDelete.id);
      if (response.success) {
        toast({ title: "Template deleted", description: `${templateToDelete.name} has been removed.` });
        setTemplates(templates.filter(t => t.id !== templateToDelete.id));
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setDeleting(false);
      setDeleteDialogOpen(false);
      setTemplateToDelete(null);
    }
  };

  const formatLastUsed = (dateString?: string) => {
    if (!dateString) return "Never";
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
    const diffDays = Math.floor(diffHours / 24);
    
    if (diffHours < 1) return "Just now";
    if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
    if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
    return date.toLocaleDateString();
  };

  const filteredTemplates = templates.filter(t => 
    t.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
    t.content.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const smsTemplates = filteredTemplates.filter((t) => t.type === "sms");
  const emailTemplates = filteredTemplates.filter((t) => t.type === "email");

  return (
    <DashboardLayout
      title="Templates"
      subtitle="Create and manage reusable message templates"
      actions={
        <Button className="gap-2" onClick={() => handleCreate("sms")}>
          <Plus className="h-4 w-4" />
          Create Template
        </Button>
      }
    >
      <Tabs defaultValue="sms" className="w-full">
        <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <TabsList>
            <TabsTrigger value="sms" className="gap-2">
              <MessageSquare className="h-4 w-4" />
              SMS Templates ({smsTemplates.length})
            </TabsTrigger>
            <TabsTrigger value="email" className="gap-2">
              <Mail className="h-4 w-4" />
              Email Templates ({emailTemplates.length})
            </TabsTrigger>
          </TabsList>

          <div className="relative max-w-sm flex-1">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input 
              placeholder="Search templates..." 
              className="pl-10"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
            />
          </div>
        </div>

        <TabsContent value="sms">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {loading ? (
              Array.from({ length: 3 }).map((_, i) => <TemplateCardSkeleton key={i} />)
            ) : (
              <>
                {smsTemplates.map((template) => (
                  <div
                    key={template.id}
                    className="group rounded-xl border border-border bg-card p-5 transition-all hover:border-primary/50 hover:shadow-lg"
                  >
                    <div className="flex items-start justify-between">
                      <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <MessageSquare className="h-5 w-5" />
                      </div>
                      <div className="flex gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => handleEdit(template)}>
                          <Edit className="h-4 w-4" />
                        </Button>
                        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => handleDuplicate(template)}>
                          <Copy className="h-4 w-4" />
                        </Button>
                        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => handleDeleteConfirm(template)}>
                          <Trash2 className="h-4 w-4 text-destructive" />
                        </Button>
                      </div>
                    </div>

                    <h3 className="mt-4 font-semibold text-foreground">
                      {template.name}
                    </h3>
                    <p className="mt-2 line-clamp-2 text-sm text-muted-foreground">
                      {template.content}
                    </p>

                    <div className="mt-4 flex items-center justify-between border-t border-border pt-4 text-xs text-muted-foreground">
                      <span>Used {template.usage_count || 0} times</span>
                      <span>Last used {formatLastUsed(template.updated_at)}</span>
                    </div>
                  </div>
                ))}

                {/* Add New Card */}
                <button 
                  onClick={() => handleCreate("sms")}
                  className="flex min-h-[200px] flex-col items-center justify-center rounded-xl border-2 border-dashed border-border bg-card/50 p-5 transition-colors hover:border-primary hover:bg-primary/5"
                >
                  <Plus className="h-10 w-10 text-muted-foreground" />
                  <p className="mt-3 font-medium text-muted-foreground">
                    Create SMS Template
                  </p>
                </button>
              </>
            )}
          </div>
        </TabsContent>

        <TabsContent value="email">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {loading ? (
              Array.from({ length: 3 }).map((_, i) => <TemplateCardSkeleton key={i} />)
            ) : (
              <>
                {emailTemplates.map((template) => (
                  <div
                    key={template.id}
                    className="group rounded-xl border border-border bg-card p-5 transition-all hover:border-accent/50 hover:shadow-lg"
                  >
                    <div className="flex items-start justify-between">
                      <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-accent/10 text-accent">
                        <Mail className="h-5 w-5" />
                      </div>
                      <div className="flex gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => handleEdit(template)}>
                          <Edit className="h-4 w-4" />
                        </Button>
                        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => handleDuplicate(template)}>
                          <Copy className="h-4 w-4" />
                        </Button>
                        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => handleDeleteConfirm(template)}>
                          <Trash2 className="h-4 w-4 text-destructive" />
                        </Button>
                      </div>
                    </div>

                    <h3 className="mt-4 font-semibold text-foreground">
                      {template.name}
                    </h3>
                    <p className="mt-2 text-sm text-muted-foreground">
                      HTML email template
                    </p>

                    <div className="mt-4 h-24 rounded-lg bg-muted/50 p-3">
                      <div className="h-3 w-3/4 rounded bg-muted" />
                      <div className="mt-2 h-2 w-full rounded bg-muted" />
                      <div className="mt-1 h-2 w-5/6 rounded bg-muted" />
                      <div className="mt-3 h-6 w-20 rounded bg-primary/20" />
                    </div>

                    <div className="mt-4 flex items-center justify-between border-t border-border pt-4 text-xs text-muted-foreground">
                      <span>Used {template.usage_count || 0} times</span>
                      <span>Last used {formatLastUsed(template.updated_at)}</span>
                    </div>
                  </div>
                ))}

                {/* Add New Card */}
                <button 
                  onClick={() => handleCreate("email")}
                  className="flex min-h-[280px] flex-col items-center justify-center rounded-xl border-2 border-dashed border-border bg-card/50 p-5 transition-colors hover:border-accent hover:bg-accent/5"
                >
                  <Plus className="h-10 w-10 text-muted-foreground" />
                  <p className="mt-3 font-medium text-muted-foreground">
                    Create Email Template
                  </p>
                </button>
              </>
            )}
          </div>
        </TabsContent>
      </Tabs>

      {/* Template Modal */}
      <TemplateModal
        open={modalOpen}
        onOpenChange={setModalOpen}
        template={editingTemplate}
        onSave={loadTemplates}
      />

      {/* Delete Confirmation Dialog */}
      <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Template</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete "{templateToDelete?.name}"? This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction 
              onClick={handleDelete} 
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={deleting}
            >
              {deleting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </DashboardLayout>
  );
}
