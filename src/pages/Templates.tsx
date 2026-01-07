import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Plus,
  Search,
  MessageSquare,
  Mail,
  Edit,
  Trash2,
  Copy,
  MoreHorizontal,
} from "lucide-react";
import { cn } from "@/lib/utils";

interface Template {
  id: string;
  name: string;
  type: "sms" | "email";
  content: string;
  usageCount: number;
  lastUsed: string;
}

const templates: Template[] = [
  {
    id: "1",
    name: "Welcome Message",
    type: "sms",
    content: "Welcome to IEOSUIA! We're excited to have you on board. Reply HELP for assistance.",
    usageCount: 1250,
    lastUsed: "2 hours ago",
  },
  {
    id: "2",
    name: "Order Confirmation",
    type: "sms",
    content: "Your order #{{order_id}} has been confirmed. Track it at {{tracking_url}}",
    usageCount: 890,
    lastUsed: "5 hours ago",
  },
  {
    id: "3",
    name: "Flash Sale Alert",
    type: "sms",
    content: "🔥 Flash Sale! Get 50% off all items for the next 24 hours. Shop now: {{link}}",
    usageCount: 450,
    lastUsed: "1 day ago",
  },
  {
    id: "4",
    name: "Weekly Newsletter",
    type: "email",
    content: "<!DOCTYPE html><html>...",
    usageCount: 320,
    lastUsed: "3 days ago",
  },
  {
    id: "5",
    name: "Password Reset",
    type: "email",
    content: "<!DOCTYPE html><html>...",
    usageCount: 1500,
    lastUsed: "1 hour ago",
  },
  {
    id: "6",
    name: "Abandoned Cart",
    type: "email",
    content: "<!DOCTYPE html><html>...",
    usageCount: 680,
    lastUsed: "6 hours ago",
  },
];

export default function Templates() {
  const smsTemplates = templates.filter((t) => t.type === "sms");
  const emailTemplates = templates.filter((t) => t.type === "email");

  return (
    <DashboardLayout
      title="Templates"
      subtitle="Create and manage reusable message templates"
      actions={
        <Button className="gap-2">
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
              SMS Templates
            </TabsTrigger>
            <TabsTrigger value="email" className="gap-2">
              <Mail className="h-4 w-4" />
              Email Templates
            </TabsTrigger>
          </TabsList>

          <div className="relative max-w-sm flex-1">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input placeholder="Search templates..." className="pl-10" />
          </div>
        </div>

        <TabsContent value="sms">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
                    <Button variant="ghost" size="icon" className="h-8 w-8">
                      <Edit className="h-4 w-4" />
                    </Button>
                    <Button variant="ghost" size="icon" className="h-8 w-8">
                      <Copy className="h-4 w-4" />
                    </Button>
                    <Button variant="ghost" size="icon" className="h-8 w-8">
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
                  <span>Used {template.usageCount} times</span>
                  <span>Last used {template.lastUsed}</span>
                </div>
              </div>
            ))}

            {/* Add New Card */}
            <button className="flex min-h-[200px] flex-col items-center justify-center rounded-xl border-2 border-dashed border-border bg-card/50 p-5 transition-colors hover:border-primary hover:bg-primary/5">
              <Plus className="h-10 w-10 text-muted-foreground" />
              <p className="mt-3 font-medium text-muted-foreground">
                Create SMS Template
              </p>
            </button>
          </div>
        </TabsContent>

        <TabsContent value="email">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
                    <Button variant="ghost" size="icon" className="h-8 w-8">
                      <Edit className="h-4 w-4" />
                    </Button>
                    <Button variant="ghost" size="icon" className="h-8 w-8">
                      <Copy className="h-4 w-4" />
                    </Button>
                    <Button variant="ghost" size="icon" className="h-8 w-8">
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
                  <span>Used {template.usageCount} times</span>
                  <span>Last used {template.lastUsed}</span>
                </div>
              </div>
            ))}

            {/* Add New Card */}
            <button className="flex min-h-[280px] flex-col items-center justify-center rounded-xl border-2 border-dashed border-border bg-card/50 p-5 transition-colors hover:border-accent hover:bg-accent/5">
              <Plus className="h-10 w-10 text-muted-foreground" />
              <p className="mt-3 font-medium text-muted-foreground">
                Create Email Template
              </p>
            </button>
          </div>
        </TabsContent>
      </Tabs>
    </DashboardLayout>
  );
}
