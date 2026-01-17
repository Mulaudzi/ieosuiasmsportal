import { useState, useEffect, useRef } from "react";
import { ScheduleRecommendations } from "@/components/campaigns/ScheduleRecommendations";
import { ABTestSetup } from "@/components/campaigns/ABTesting";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Checkbox } from "@/components/ui/checkbox";
import {
  ArrowLeft,
  ArrowRight,
  Check,
  Upload,
  Users,
  Mail,
  Calendar,
  FileText,
  Loader2,
  Paperclip,
  X,
  Eye,
  Trash2,
} from "lucide-react";
import { Link, useNavigate } from "react-router-dom";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { 
  createEmailCampaign, 
  getTemplates, 
  getContactGroups, 
  uploadAttachment,
  deleteAttachment,
  checkCampaignCredits,
  api 
} from "@/lib/api";
import ReactQuill from "react-quill";
import "react-quill/dist/quill.snow.css";

const steps = [
  { id: 1, name: "Setup", icon: FileText },
  { id: 2, name: "Recipients", icon: Users },
  { id: 3, name: "Content", icon: Mail },
  { id: 4, name: "Schedule", icon: Calendar },
  { id: 5, name: "Review", icon: Check },
];

interface Attachment {
  id: string;
  name: string;
  size: number;
  stored_name?: string;
}

interface Template {
  id: string;
  name: string;
  subject: string;
  content: string;
}

interface ContactGroup {
  id: string;
  name: string;
  contact_count: number;
}

// Sender ID interface - REMOVED (sender ID feature disabled)

export default function CreateEmailCampaign() {
  const navigate = useNavigate();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [currentStep, setCurrentStep] = useState(1);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [templates, setTemplates] = useState<Template[]>([]);
  const [contactGroups, setContactGroups] = useState<ContactGroup[]>([]);
  // Sender IDs removed
  const [contacts, setContacts] = useState<any[]>([]);
  const [selectedContacts, setSelectedContacts] = useState<string[]>([]);
  const [creditCheck, setCreditCheck] = useState<any>(null);
  
  const [formData, setFormData] = useState({
    name: "",
    description: "",
    subject: "",
    recipientMethod: "group",
    contactGroup: "",
    senderEmail: "",
    message: "",
    templateId: "",
    scheduleType: "now",
    scheduleDate: "",
    scheduleTime: "",
  });
  
  const [attachments, setAttachments] = useState<Attachment[]>([]);
  
  // A/B Testing state
  const [abTestEnabled, setAbTestEnabled] = useState(false);
  const [abMessageA, setAbMessageA] = useState("");
  const [abMessageB, setAbMessageB] = useState("");
  const [abSubjectA, setAbSubjectA] = useState("");
  const [abSubjectB, setAbSubjectB] = useState("");
  const [abSplitPercent, setAbSplitPercent] = useState(50);

  useEffect(() => {
    loadInitialData();
  }, []);

  const loadInitialData = async () => {
    try {
      const [templatesRes, groupsRes] = await Promise.all([
        getTemplates("email"),
        api.get<{ groups: ContactGroup[] }>("/contact-groups"),
      ]);

      if (templatesRes.success) setTemplates(templatesRes.data || []);
      if (groupsRes.success) setContactGroups((groupsRes.groups as ContactGroup[]) || []);
    } catch (error) {
      console.error("Failed to load initial data:", error);
    }
  };

  const loadGroupContacts = async (groupId: string) => {
    try {
      const response = await api.get<{ data: any[] }>("/contacts", { group_id: groupId, per_page: "1000" });
      if (response.success) {
        const contactsData = (response.data as any[]) || [];
        setContacts(contactsData);
        setSelectedContacts(contactsData.map((c: any) => c.id));
      }
    } catch (error) {
      console.error("Failed to load contacts:", error);
    }
  };

  const handleTemplateSelect = (templateId: string) => {
    const template = templates.find(t => t.id === templateId);
    if (template) {
      setFormData(prev => ({
        ...prev,
        templateId,
        subject: template.subject || prev.subject,
        message: template.content,
      }));
    }
  };

  const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = e.target.files;
    if (!files?.length) return;

    setUploading(true);
    for (const file of Array.from(files)) {
      try {
        const formData = new FormData();
        formData.append("file", file);
        
        const response = await uploadAttachment(formData);
        if (response.success && response.data?.attachment) {
          setAttachments(prev => [...prev, {
            id: response.data.attachment.id,
            name: response.data.attachment.name,
            size: response.data.attachment.size,
            stored_name: response.data.attachment.stored_name,
          }]);
        }
      } catch (error) {
        toast({
          title: "Upload failed",
          description: `Failed to upload ${file.name}`,
          variant: "destructive",
        });
      }
    }
    setUploading(false);
    if (fileInputRef.current) fileInputRef.current.value = "";
  };

  const handleRemoveAttachment = async (id: string) => {
    try {
      await deleteAttachment(id);
      setAttachments(prev => prev.filter(a => a.id !== id));
    } catch (error) {
      console.error("Failed to remove attachment:", error);
    }
  };

  const handleGroupChange = (groupId: string) => {
    setFormData(prev => ({ ...prev, contactGroup: groupId }));
    if (groupId) loadGroupContacts(groupId);
  };

  const handleNext = async () => {
    if (currentStep === 4) {
      // Check credits before review
      try {
        const response = await checkCampaignCredits(selectedContacts.length, "email");
        if (response.success) {
          setCreditCheck(response.data);
        }
      } catch (error) {
        console.error("Credit check failed:", error);
      }
    }
    if (currentStep < 5) setCurrentStep(currentStep + 1);
  };

  const handleBack = () => {
    if (currentStep > 1) setCurrentStep(currentStep - 1);
  };

  const handleSubmit = async () => {
    if (!formData.name || !formData.subject || !formData.message) {
      toast({
        title: "Missing required fields",
        description: "Please fill in campaign name, subject, and message.",
        variant: "destructive",
      });
      return;
    }

    if (selectedContacts.length === 0) {
      toast({
        title: "No recipients",
        description: "Please select at least one recipient.",
        variant: "destructive",
      });
      return;
    }

    setIsSubmitting(true);
    try {
      const recipients = contacts
        .filter(c => selectedContacts.includes(c.id))
        .map(c => ({ email: c.email, name: c.name }));

      const response = await createEmailCampaign({
        name: formData.name,
        subject: abTestEnabled ? abSubjectA : formData.subject,
        message: abTestEnabled ? abMessageA : formData.message,
        recipients,
        attachments: attachments.map(a => ({ id: a.id, stored_name: a.stored_name })),
        scheduled_at: formData.scheduleType === "schedule" 
          ? `${formData.scheduleDate} ${formData.scheduleTime}` 
          : null,
        is_ab_test: abTestEnabled,
        ab_test_split_percent: abSplitPercent,
        ab_variants: abTestEnabled ? [
          { variant_name: 'A', message_content: abMessageA, subject: abSubjectA },
          { variant_name: 'B', message_content: abMessageB, subject: abSubjectB },
        ] : undefined,
      });

      if (response.success) {
        toast({
          title: "Campaign created!",
          description: "Your email campaign has been created successfully.",
        });
        navigate("/email-campaigns");
      } else {
        toast({
          title: "Failed to create campaign",
          description: response.error || "Please try again.",
          variant: "destructive",
        });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Something went wrong. Please try again.",
        variant: "destructive",
      });
    } finally {
      setIsSubmitting(false);
    }
  };

  const quillModules = {
    toolbar: [
      [{ header: [1, 2, 3, false] }],
      ["bold", "italic", "underline", "strike"],
      [{ color: [] }, { background: [] }],
      [{ list: "ordered" }, { list: "bullet" }],
      [{ align: [] }],
      ["link", "image"],
      ["clean"],
    ],
  };

  const formatFileSize = (bytes: number) => {
    if (bytes < 1024) return bytes + " B";
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + " KB";
    return (bytes / (1024 * 1024)).toFixed(1) + " MB";
  };

  return (
    <DashboardLayout
      title="Create Email Campaign"
      subtitle="Send professional emails to your contacts"
      actions={
        <Link to="/email-campaigns">
          <Button variant="outline" className="gap-2">
            <ArrowLeft className="h-4 w-4" />
            Back to Campaigns
          </Button>
        </Link>
      }
    >
      {/* Progress Steps */}
      <div className="mb-8">
        <div className="flex items-center justify-between">
          {steps.map((step, index) => (
            <div key={step.id} className="flex items-center">
              <div className="wizard-step">
                <div
                  className={cn(
                    "wizard-step-circle",
                    currentStep === step.id && "active",
                    currentStep > step.id && "completed",
                    currentStep < step.id && "pending"
                  )}
                >
                  {currentStep > step.id ? (
                    <Check className="h-5 w-5" />
                  ) : (
                    <step.icon className="h-5 w-5" />
                  )}
                </div>
                <span className={cn(
                  "hidden text-sm font-medium sm:block",
                  currentStep === step.id ? "text-foreground" : "text-muted-foreground"
                )}>
                  {step.name}
                </span>
              </div>
              {index < steps.length - 1 && (
                <div className={cn(
                  "mx-4 h-0.5 w-12 sm:w-24",
                  currentStep > step.id ? "bg-success" : "bg-border"
                )} />
              )}
            </div>
          ))}
        </div>
      </div>

      {/* Step Content */}
      <div className="rounded-xl border border-border bg-card p-6">
        {/* Step 1: Setup */}
        {currentStep === 1 && (
          <div className="animate-fade-in space-y-6">
            <div>
              <h2 className="text-xl font-semibold text-foreground">Campaign Setup</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Configure your email campaign basics
              </p>
            </div>

            <div className="space-y-4">
              <div>
                <Label htmlFor="name">Campaign Name *</Label>
                <Input
                  id="name"
                  placeholder="e.g., January Newsletter"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  className="mt-1.5"
                />
              </div>

              <div>
                <Label htmlFor="subject">Email Subject *</Label>
                <Input
                  id="subject"
                  placeholder="e.g., 🎉 Exciting news inside!"
                  value={formData.subject}
                  onChange={(e) => setFormData({ ...formData, subject: e.target.value })}
                  className="mt-1.5"
                />
              </div>

              <div>
                <Label>From Email</Label>
                <Input
                  type="email"
                  placeholder="noreply@yourdomain.com"
                  value={formData.senderEmail}
                  onChange={(e) => setFormData({ ...formData, senderEmail: e.target.value })}
                  className="mt-1.5"
                />
                <p className="text-xs text-muted-foreground mt-1">
                  The email address that will appear as the sender
                </p>
              </div>

              {templates.length > 0 && (
                <div>
                  <Label>Use Template (Optional)</Label>
                  <Select
                    value={formData.templateId}
                    onValueChange={handleTemplateSelect}
                  >
                    <SelectTrigger className="mt-1.5">
                      <SelectValue placeholder="Select a template..." />
                    </SelectTrigger>
                    <SelectContent>
                      {templates.map((template) => (
                        <SelectItem key={template.id} value={template.id}>
                          {template.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              )}
            </div>
          </div>
        )}

        {/* Step 2: Recipients */}
        {currentStep === 2 && (
          <div className="animate-fade-in space-y-6">
            <div>
              <h2 className="text-xl font-semibold text-foreground">Select Recipients</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Choose who will receive this email
              </p>
            </div>

            <div>
              <Label>Contact Group</Label>
              <Select value={formData.contactGroup} onValueChange={handleGroupChange}>
                <SelectTrigger className="mt-1.5">
                  <SelectValue placeholder="Select a group..." />
                </SelectTrigger>
                <SelectContent>
                  {contactGroups.map((group) => (
                    <SelectItem key={group.id} value={group.id}>
                      {group.name} ({group.contact_count} contacts)
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {contacts.length > 0 && (
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <Label>Recipients ({selectedContacts.length} selected)</Label>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setSelectedContacts(contacts.map(c => c.id))}
                    >
                      Select All
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setSelectedContacts([])}
                    >
                      Clear All
                    </Button>
                  </div>
                </div>
                <div className="max-h-64 overflow-y-auto rounded-lg border border-border">
                  {contacts.slice(0, 50).map((contact) => (
                    <label
                      key={contact.id}
                      className="flex cursor-pointer items-center gap-3 border-b border-border p-3 last:border-0 hover:bg-muted/50"
                    >
                      <Checkbox
                        checked={selectedContacts.includes(contact.id)}
                        onCheckedChange={(checked) => {
                          if (checked) {
                            setSelectedContacts([...selectedContacts, contact.id]);
                          } else {
                            setSelectedContacts(selectedContacts.filter(id => id !== contact.id));
                          }
                        }}
                      />
                      <div className="flex-1 min-w-0">
                        <p className="font-medium text-foreground truncate">{contact.name}</p>
                        <p className="text-sm text-muted-foreground truncate">{contact.email}</p>
                      </div>
                    </label>
                  ))}
                  {contacts.length > 50 && (
                    <div className="p-3 text-center text-sm text-muted-foreground">
                      +{contacts.length - 50} more contacts
                    </div>
                  )}
                </div>
              </div>
            )}
          </div>
        )}

        {/* Step 3: Content */}
        {currentStep === 3 && (
          <div className="animate-fade-in space-y-6">
            <div>
              <h2 className="text-xl font-semibold text-foreground">Email Content</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Design your email with the rich text editor
              </p>
            </div>

            <div className="space-y-4">
              {/* A/B Testing Setup */}
              <ABTestSetup
                enabled={abTestEnabled}
                onEnabledChange={(enabled) => {
                  setAbTestEnabled(enabled);
                  if (enabled && formData.subject) {
                    setAbSubjectA(formData.subject);
                    setAbMessageA(formData.message);
                  }
                }}
                messageA={abMessageA}
                messageB={abMessageB}
                subjectA={abSubjectA}
                subjectB={abSubjectB}
                splitPercent={abSplitPercent}
                onMessageAChange={setAbMessageA}
                onMessageBChange={setAbMessageB}
                onSubjectAChange={setAbSubjectA}
                onSubjectBChange={setAbSubjectB}
                onSplitChange={setAbSplitPercent}
                campaignType="email"
                totalRecipients={selectedContacts.length}
              />

              {!abTestEnabled && (
                <>
                  <div>
                    <Label>Message *</Label>
                    <div className="mt-1.5 rounded-lg border border-border">
                      <ReactQuill
                        theme="snow"
                        value={formData.message}
                        onChange={(content) => setFormData({ ...formData, message: content })}
                        modules={quillModules}
                        className="min-h-[300px]"
                        placeholder="Compose your email..."
                      />
                    </div>
                    <p className="mt-2 text-xs text-muted-foreground">
                      Use {"{{name}}"} to personalize with recipient's name
                    </p>
                  </div>
                </>
              )}

              {/* Attachments */}
              <div>
                <Label>Attachments</Label>
                <div className="mt-1.5 space-y-2">
                  {attachments.map((attachment) => (
                    <div
                      key={attachment.id}
                      className="flex items-center justify-between rounded-lg border border-border bg-muted/50 p-3"
                    >
                      <div className="flex items-center gap-3">
                        <Paperclip className="h-4 w-4 text-muted-foreground" />
                        <div>
                          <p className="text-sm font-medium text-foreground">{attachment.name}</p>
                          <p className="text-xs text-muted-foreground">{formatFileSize(attachment.size)}</p>
                        </div>
                      </div>
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => handleRemoveAttachment(attachment.id)}
                      >
                        <X className="h-4 w-4" />
                      </Button>
                    </div>
                  ))}
                  
                  <input
                    ref={fileInputRef}
                    type="file"
                    multiple
                    accept=".pdf,.jpg,.jpeg,.png,.docx,.doc,.txt,.xlsx,.xls"
                    onChange={handleFileUpload}
                    className="hidden"
                  />
                  <Button
                    variant="outline"
                    className="gap-2"
                    onClick={() => fileInputRef.current?.click()}
                    disabled={uploading}
                  >
                    {uploading ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                      <Paperclip className="h-4 w-4" />
                    )}
                    Add Attachment
                  </Button>
                  <p className="text-xs text-muted-foreground">
                    Allowed: PDF, JPG, PNG, DOCX, TXT, XLSX (max 10MB each)
                  </p>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Step 4: Schedule */}
        {currentStep === 4 && (
          <div className="animate-fade-in space-y-6">
            <div>
              <h2 className="text-xl font-semibold text-foreground">Schedule Delivery</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Choose when to send your email campaign
              </p>
            </div>

            <RadioGroup
              value={formData.scheduleType}
              onValueChange={(value) => setFormData({ ...formData, scheduleType: value })}
              className="space-y-4"
            >
              <Label
                htmlFor="now"
                className={cn(
                  "flex cursor-pointer items-center gap-4 rounded-xl border-2 p-4 transition-all",
                  formData.scheduleType === "now"
                    ? "border-primary bg-primary/5"
                    : "border-border hover:border-primary/50"
                )}
              >
                <RadioGroupItem value="now" id="now" />
                <div>
                  <p className="font-medium text-foreground">Send Now</p>
                  <p className="text-sm text-muted-foreground">
                    Start sending immediately after review
                  </p>
                </div>
              </Label>

              <Label
                htmlFor="schedule"
                className={cn(
                  "flex cursor-pointer items-center gap-4 rounded-xl border-2 p-4 transition-all",
                  formData.scheduleType === "schedule"
                    ? "border-primary bg-primary/5"
                    : "border-border hover:border-primary/50"
                )}
              >
                <RadioGroupItem value="schedule" id="schedule" />
                <div>
                  <p className="font-medium text-foreground">Schedule</p>
                  <p className="text-sm text-muted-foreground">
                    Pick a specific date and time
                  </p>
                </div>
              </Label>
            </RadioGroup>

            {formData.scheduleType === "schedule" && (
              <div className="space-y-4">
                {/* Schedule Recommendations */}
                <ScheduleRecommendations 
                  campaignType="email"
                  onSelectTime={(date, time) => 
                    setFormData({ ...formData, scheduleDate: date, scheduleTime: time })
                  }
                />
                
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <Label htmlFor="date">Date</Label>
                    <Input
                      id="date"
                      type="date"
                      value={formData.scheduleDate}
                      onChange={(e) => setFormData({ ...formData, scheduleDate: e.target.value })}
                      className="mt-1.5"
                    />
                  </div>
                  <div>
                    <Label htmlFor="time">Time</Label>
                    <Input
                      id="time"
                      type="time"
                      value={formData.scheduleTime}
                      onChange={(e) => setFormData({ ...formData, scheduleTime: e.target.value })}
                      className="mt-1.5"
                    />
                  </div>
                </div>
              </div>
            )}
          </div>
        )}

        {/* Step 5: Review */}
        {currentStep === 5 && (
          <div className="animate-fade-in space-y-6">
            <div>
              <h2 className="text-xl font-semibold text-foreground">Review & Send</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Confirm your campaign details before sending
              </p>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
              <div className="space-y-4">
                <div className="rounded-lg border border-border p-4">
                  <h3 className="font-medium text-foreground">Campaign Details</h3>
                  <dl className="mt-3 space-y-2 text-sm">
                    <div className="flex justify-between">
                      <dt className="text-muted-foreground">Name</dt>
                      <dd className="font-medium text-foreground">{formData.name}</dd>
                    </div>
                    <div className="flex justify-between">
                      <dt className="text-muted-foreground">Subject</dt>
                      <dd className="font-medium text-foreground">{formData.subject}</dd>
                    </div>
                    <div className="flex justify-between">
                      <dt className="text-muted-foreground">Recipients</dt>
                      <dd className="font-medium text-foreground">{selectedContacts.length}</dd>
                    </div>
                    <div className="flex justify-between">
                      <dt className="text-muted-foreground">Attachments</dt>
                      <dd className="font-medium text-foreground">{attachments.length}</dd>
                    </div>
                    <div className="flex justify-between">
                      <dt className="text-muted-foreground">Schedule</dt>
                      <dd className="font-medium text-foreground">
                        {formData.scheduleType === "now" 
                          ? "Send immediately" 
                          : `${formData.scheduleDate} ${formData.scheduleTime}`}
                      </dd>
                    </div>
                  </dl>
                </div>

                {creditCheck && (
                  <div className={cn(
                    "rounded-lg border p-4",
                    creditCheck.sufficient_credits 
                      ? "border-success/50 bg-success/10" 
                      : "border-destructive/50 bg-destructive/10"
                  )}>
                    <h3 className="font-medium text-foreground">Cost Estimate</h3>
                    <dl className="mt-3 space-y-2 text-sm">
                      <div className="flex justify-between">
                        <dt className="text-muted-foreground">Estimated Cost</dt>
                        <dd className="font-medium text-foreground">
                          R{creditCheck.estimated_cost?.toFixed(2)}
                        </dd>
                      </div>
                      <div className="flex justify-between">
                        <dt className="text-muted-foreground">Available Balance</dt>
                        <dd className="font-medium text-foreground">
                          R{creditCheck.available_balance?.toFixed(2)}
                        </dd>
                      </div>
                    </dl>
                    {!creditCheck.sufficient_credits && (
                      <p className="mt-2 text-sm text-destructive">
                        Insufficient credits. Please top up your wallet.
                      </p>
                    )}
                  </div>
                )}
              </div>

              <div className="rounded-lg border border-border p-4">
                <div className="flex items-center justify-between">
                  <h3 className="font-medium text-foreground">Email Preview</h3>
                  <Eye className="h-4 w-4 text-muted-foreground" />
                </div>
                <div className="mt-4 rounded-lg border border-border bg-muted/30 p-4">
                  <p className="text-sm font-medium text-foreground">{formData.subject}</p>
                  <div 
                    className="mt-4 prose prose-sm max-w-none text-foreground"
                    dangerouslySetInnerHTML={{ __html: formData.message }}
                  />
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Navigation */}
        <div className="mt-8 flex items-center justify-between border-t border-border pt-6">
          <Button
            variant="outline"
            onClick={handleBack}
            disabled={currentStep === 1}
            className="gap-2"
          >
            <ArrowLeft className="h-4 w-4" />
            Back
          </Button>

          {currentStep < 5 ? (
            <Button onClick={handleNext} className="gap-2">
              Next
              <ArrowRight className="h-4 w-4" />
            </Button>
          ) : (
            <Button 
              onClick={handleSubmit} 
              disabled={isSubmitting || (creditCheck && !creditCheck.sufficient_credits)}
              className="gap-2"
            >
              {isSubmitting ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <Mail className="h-4 w-4" />
              )}
              {formData.scheduleType === "now" ? "Send Campaign" : "Schedule Campaign"}
            </Button>
          )}
        </div>
      </div>
    </DashboardLayout>
  );
}
