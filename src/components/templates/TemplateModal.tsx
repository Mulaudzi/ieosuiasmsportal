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
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Badge } from "@/components/ui/badge";
import { MessageSquare, Mail, Info, Loader2 } from "lucide-react";
import { toast } from "@/hooks/use-toast";
import { createTemplate, updateTemplate, handleApiError } from "@/lib/api";

interface Template {
  id?: string;
  name: string;
  type: "sms" | "email";
  content: string;
  subject?: string;
}

interface TemplateModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  template?: Template | null;
  onSave?: (template: Template) => void;
}

const VARIABLE_SUGGESTIONS = [
  { name: "{{name}}", description: "Contact's name" },
  { name: "{{phone}}", description: "Contact's phone number" },
  { name: "{{email}}", description: "Contact's email" },
  { name: "{{order_id}}", description: "Order ID" },
  { name: "{{tracking_url}}", description: "Tracking URL" },
  { name: "{{link}}", description: "Custom link" },
  { name: "{{date}}", description: "Current date" },
  { name: "{{company}}", description: "Your company name" },
];

export function TemplateModal({ open, onOpenChange, template, onSave }: TemplateModalProps) {
  const [name, setName] = useState("");
  const [type, setType] = useState<"sms" | "email">("sms");
  const [content, setContent] = useState("");
  const [subject, setSubject] = useState("");
  const [saving, setSaving] = useState(false);

  const isEditing = !!template?.id;

  useEffect(() => {
    if (template) {
      setName(template.name);
      setType(template.type);
      setContent(template.content);
      setSubject(template.subject || "");
    } else {
      setName("");
      setType("sms");
      setContent("");
      setSubject("");
    }
  }, [template, open]);

  const insertVariable = (variable: string) => {
    setContent((prev) => prev + variable);
  };

  const handleSave = async () => {
    if (!name.trim()) {
      toast({
        title: "Name required",
        description: "Please enter a template name.",
        variant: "destructive",
      });
      return;
    }
    if (!content.trim()) {
      toast({
        title: "Content required",
        description: "Please enter template content.",
        variant: "destructive",
      });
      return;
    }
    if (type === "email" && !subject.trim()) {
      toast({
        title: "Subject required",
        description: "Email templates require a subject line.",
        variant: "destructive",
      });
      return;
    }

    setSaving(true);
    
    try {
      if (isEditing && template?.id) {
        // Update existing template
        const response = await updateTemplate(template.id, {
          name,
          content,
          subject: type === "email" ? subject : undefined,
        });
        
        if (response.success) {
          toast({
            title: "Template updated",
            description: `"${name}" has been saved.`,
          });
          onSave?.(response.data?.template || { id: template.id, name, type, content, subject });
          onOpenChange(false);
        }
      } else {
        // Create new template
        const response = await createTemplate({
          name,
          content,
          type,
        });
        
        if (response.success) {
          toast({
            title: "Template created",
            description: `"${name}" has been saved.`,
          });
          onSave?.(response.data?.template || { name, type, content, subject });
          onOpenChange(false);
        }
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setSaving(false);
    }
  };

  const charCount = content.length;
  const smsCount = Math.ceil(charCount / 160) || 1;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>{isEditing ? "Edit Template" : "Create Template"}</DialogTitle>
          <DialogDescription>
            {isEditing
              ? "Update your message template"
              : "Create a reusable message template with variable placeholders"}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-6">
          {/* Template Type */}
          {!isEditing && (
            <Tabs value={type} onValueChange={(v) => setType(v as "sms" | "email")}>
              <TabsList className="w-full">
                <TabsTrigger value="sms" className="flex-1 gap-2">
                  <MessageSquare className="h-4 w-4" />
                  SMS Template
                </TabsTrigger>
                <TabsTrigger value="email" className="flex-1 gap-2">
                  <Mail className="h-4 w-4" />
                  Email Template
                </TabsTrigger>
              </TabsList>
            </Tabs>
          )}

          {/* Name */}
          <div className="space-y-2">
            <Label htmlFor="name">Template Name</Label>
            <Input
              id="name"
              placeholder="e.g., Welcome Message"
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
          </div>

          {/* Subject (Email only) */}
          {type === "email" && (
            <div className="space-y-2">
              <Label htmlFor="subject">Email Subject</Label>
              <Input
                id="subject"
                placeholder="e.g., Welcome to {{company}}!"
                value={subject}
                onChange={(e) => setSubject(e.target.value)}
              />
            </div>
          )}

          {/* Content */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label htmlFor="content">
                {type === "sms" ? "Message Content" : "Email Body"}
              </Label>
              {type === "sms" && (
                <span className="text-xs text-muted-foreground">
                  {charCount} chars • {smsCount} SMS
                </span>
              )}
            </div>
            <Textarea
              id="content"
              placeholder={
                type === "sms"
                  ? "Hi {{name}}, welcome to our service!"
                  : "Dear {{name}},\n\nThank you for joining..."
              }
              value={content}
              onChange={(e) => setContent(e.target.value)}
              rows={type === "sms" ? 4 : 8}
            />
          </div>

          {/* Variables */}
          <div className="space-y-3">
            <div className="flex items-center gap-2">
              <Info className="h-4 w-4 text-muted-foreground" />
              <span className="text-sm text-muted-foreground">
                Click to insert variable placeholders
              </span>
            </div>
            <div className="flex flex-wrap gap-2">
              {VARIABLE_SUGGESTIONS.map((v) => (
                <Badge
                  key={v.name}
                  variant="secondary"
                  className="cursor-pointer hover:bg-primary hover:text-primary-foreground"
                  onClick={() => insertVariable(v.name)}
                  title={v.description}
                >
                  {v.name}
                </Badge>
              ))}
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={handleSave} disabled={saving}>
            {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {isEditing ? "Update Template" : "Create Template"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
