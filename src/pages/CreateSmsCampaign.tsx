import { useState } from "react";
import { ScheduleRecommendations } from "@/components/campaigns/ScheduleRecommendations";
import { ABTestSetup } from "@/components/campaigns/ABTesting";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import {
  ArrowLeft,
  ArrowRight,
  Check,
  Upload,
  Users,
  MessageSquare,
  Calendar,
  CreditCard,
  Send,
  FileText,
  Loader2,
} from "lucide-react";
import { Link, useNavigate } from "react-router-dom";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { createSmsCampaign } from "@/lib/api";

const steps = [
  { id: 1, name: "Campaign Setup", icon: FileText },
  { id: 2, name: "Recipients", icon: Users },
  { id: 3, name: "Message", icon: MessageSquare },
  { id: 4, name: "Schedule", icon: Calendar },
  { id: 5, name: "Review", icon: Check },
];

export default function CreateSmsCampaign() {
  const navigate = useNavigate();
  const [currentStep, setCurrentStep] = useState(1);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [formData, setFormData] = useState({
    name: "",
    description: "",
    recipientMethod: "upload",
    contactGroup: "",
    message: "",
    
    scheduleType: "now",
    scheduleDate: "",
    scheduleTime: "",
  });
  
  // A/B Testing state
  const [abTestEnabled, setAbTestEnabled] = useState(false);
  const [abMessageA, setAbMessageA] = useState("");
  const [abMessageB, setAbMessageB] = useState("");
  const [abSplitPercent, setAbSplitPercent] = useState(50);

  const messageLength = formData.message.length;
  const smsCount = Math.ceil(messageLength / 160) || 0;
  const estimatedCredits = smsCount * 1250; // Assuming 1250 recipients

  const handleNext = () => {
    if (currentStep < 5) {
      setCurrentStep(currentStep + 1);
    }
  };

  const handleBack = () => {
    if (currentStep > 1) {
      setCurrentStep(currentStep - 1);
    }
  };

  const handleSubmit = async () => {
    if (!formData.name || !formData.message) {
      toast({
        title: "Missing required fields",
        description: "Please fill in campaign name and message.",
        variant: "destructive",
      });
      return;
    }

    setIsSubmitting(true);
    try {
      const campaignData = {
        ...formData,
        is_ab_test: abTestEnabled,
        ab_test_split_percent: abSplitPercent,
        ab_variants: abTestEnabled ? [
          { variant_name: 'A', message_content: abMessageA },
          { variant_name: 'B', message_content: abMessageB },
        ] : undefined,
      };
      const response = await createSmsCampaign(campaignData);
      if (response.success) {
        toast({
          title: "Campaign created successfully!",
          description: `Campaign ID: ${response.data?.campaignId}. Estimated cost: ${response.data?.estimatedCost} credits.`,
        });
        navigate("/sms-campaigns");
      } else {
        toast({
          title: "Failed to create campaign",
          description: response.error,
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

  return (
    <DashboardLayout
      title="Create SMS Campaign"
      subtitle="Send bulk SMS to your contacts"
      actions={
        <Link to="/sms-campaigns">
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
                <span
                  className={cn(
                    "hidden text-sm font-medium sm:block",
                    currentStep === step.id
                      ? "text-foreground"
                      : "text-muted-foreground"
                  )}
                >
                  {step.name}
                </span>
              </div>
              {index < steps.length - 1 && (
                <div
                  className={cn(
                    "mx-4 h-0.5 w-12 sm:w-24",
                    currentStep > step.id ? "bg-success" : "bg-border"
                  )}
                />
              )}
            </div>
          ))}
        </div>
      </div>

      {/* Step Content */}
      <div className="rounded-xl border border-border bg-card p-6">
        {/* Step 1: Campaign Setup */}
        {currentStep === 1 && (
          <div className="animate-fade-in space-y-6">
            <div>
              <h2 className="text-xl font-semibold text-foreground">
                Campaign Setup
              </h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Give your campaign a name and description
              </p>
            </div>

            <div className="space-y-4">
              <div>
                <Label htmlFor="name">Campaign Name *</Label>
                <Input
                  id="name"
                  placeholder="e.g., Summer Sale Announcement"
                  value={formData.name}
                  onChange={(e) =>
                    setFormData({ ...formData, name: e.target.value })
                  }
                  className="mt-1.5"
                />
              </div>

              <div>
                <Label htmlFor="description">Description (Optional)</Label>
                <Textarea
                  id="description"
                  placeholder="Brief description of this campaign..."
                  value={formData.description}
                  onChange={(e) =>
                    setFormData({ ...formData, description: e.target.value })
                  }
                  className="mt-1.5"
                  rows={3}
                />
              </div>
            </div>
          </div>
        )}

        {/* Step 2: Recipients */}
        {currentStep === 2 && (
          <div className="animate-fade-in space-y-6">
            <div>
              <h2 className="text-xl font-semibold text-foreground">
                Select Recipients
              </h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Choose how you want to add recipients
              </p>
            </div>

            <RadioGroup
              value={formData.recipientMethod}
              onValueChange={(value) =>
                setFormData({ ...formData, recipientMethod: value })
              }
              className="grid gap-4 sm:grid-cols-3"
            >
              <Label
                htmlFor="upload"
                className={cn(
                  "flex cursor-pointer flex-col items-center gap-3 rounded-xl border-2 p-6 transition-all",
                  formData.recipientMethod === "upload"
                    ? "border-primary bg-primary/5"
                    : "border-border hover:border-primary/50"
                )}
              >
                <RadioGroupItem value="upload" id="upload" className="sr-only" />
                <Upload className="h-8 w-8 text-primary" />
                <div className="text-center">
                  <p className="font-medium text-foreground">Upload File</p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    CSV or Excel file
                  </p>
                </div>
              </Label>

              <Label
                htmlFor="group"
                className={cn(
                  "flex cursor-pointer flex-col items-center gap-3 rounded-xl border-2 p-6 transition-all",
                  formData.recipientMethod === "group"
                    ? "border-primary bg-primary/5"
                    : "border-border hover:border-primary/50"
                )}
              >
                <RadioGroupItem value="group" id="group" className="sr-only" />
                <Users className="h-8 w-8 text-primary" />
                <div className="text-center">
                  <p className="font-medium text-foreground">Contact Group</p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Existing contacts
                  </p>
                </div>
              </Label>

              <Label
                htmlFor="manual"
                className={cn(
                  "flex cursor-pointer flex-col items-center gap-3 rounded-xl border-2 p-6 transition-all",
                  formData.recipientMethod === "manual"
                    ? "border-primary bg-primary/5"
                    : "border-border hover:border-primary/50"
                )}
              >
                <RadioGroupItem value="manual" id="manual" className="sr-only" />
                <MessageSquare className="h-8 w-8 text-primary" />
                <div className="text-center">
                  <p className="font-medium text-foreground">Manual Entry</p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Type numbers
                  </p>
                </div>
              </Label>
            </RadioGroup>

            {formData.recipientMethod === "upload" && (
              <div className="rounded-lg border-2 border-dashed border-border p-8 text-center">
                <Upload className="mx-auto h-10 w-10 text-muted-foreground" />
                <p className="mt-4 text-sm font-medium text-foreground">
                  Drop your file here or click to browse
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                  Supports CSV, XLS, XLSX up to 10MB
                </p>
                <Button variant="outline" className="mt-4">
                  Select File
                </Button>
              </div>
            )}

            {formData.recipientMethod === "group" && (
              <div>
                <Label>Select Contact Group</Label>
                <Select
                  value={formData.contactGroup}
                  onValueChange={(value) =>
                    setFormData({ ...formData, contactGroup: value })
                  }
                >
                  <SelectTrigger className="mt-1.5">
                    <SelectValue placeholder="Choose a group..." />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Contacts (12,450)</SelectItem>
                    <SelectItem value="customers">Customers (8,200)</SelectItem>
                    <SelectItem value="leads">Leads (3,500)</SelectItem>
                    <SelectItem value="vip">VIP Customers (750)</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            )}
          </div>
        )}

        {/* Step 3: Message */}
        {currentStep === 3 && (
          <div className="animate-fade-in space-y-6">
            <div>
              <h2 className="text-xl font-semibold text-foreground">
                Compose Message
              </h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Write your SMS message
              </p>
            </div>

            <div className="space-y-4">

              {/* A/B Testing Setup */}
              <ABTestSetup
                enabled={abTestEnabled}
                onEnabledChange={(enabled) => {
                  setAbTestEnabled(enabled);
                  if (enabled && formData.message) {
                    setAbMessageA(formData.message);
                  }
                }}
                messageA={abMessageA}
                messageB={abMessageB}
                splitPercent={abSplitPercent}
                onMessageAChange={setAbMessageA}
                onMessageBChange={setAbMessageB}
                onSplitChange={setAbSplitPercent}
                campaignType="sms"
                totalRecipients={1250}
              />

              {!abTestEnabled && (
                <>
                  <div>
                    <Label htmlFor="message">Message *</Label>
                    <Textarea
                      id="message"
                      placeholder="Type your message here..."
                      value={formData.message}
                      onChange={(e) =>
                        setFormData({ ...formData, message: e.target.value })
                      }
                      className="mt-1.5 min-h-[150px]"
                    />
                    <div className="mt-2 flex items-center justify-between text-sm">
                      <span className="text-muted-foreground">
                        {messageLength} / 160 characters
                        {smsCount > 1 && ` (${smsCount} SMS)`}
                      </span>
                      <span className="font-medium text-primary">
                        ~{estimatedCredits.toLocaleString()} credits
                      </span>
                    </div>
                  </div>

                  <div className="rounded-lg bg-muted/50 p-4">
                    <p className="text-sm font-medium text-foreground">Preview</p>
                    <div className="mt-2 rounded-lg bg-card p-4 shadow-sm">
                      <p className="text-xs text-muted-foreground">
                        From: IEOSUIA
                      </p>
                      <p className="mt-2 text-sm text-foreground">
                        {formData.message || "Your message will appear here..."}
                      </p>
                    </div>
                  </div>
                </>
              )}
            </div>
          </div>
        )}

        {/* Step 4: Schedule */}
        {currentStep === 4 && (
          <div className="animate-fade-in space-y-6">
            <div>
              <h2 className="text-xl font-semibold text-foreground">
                Schedule Delivery
              </h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Choose when to send your campaign
              </p>
            </div>

            <RadioGroup
              value={formData.scheduleType}
              onValueChange={(value) =>
                setFormData({ ...formData, scheduleType: value })
              }
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
                  campaignType="sms"
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
                      onChange={(e) =>
                        setFormData({ ...formData, scheduleDate: e.target.value })
                      }
                      className="mt-1.5"
                    />
                  </div>
                  <div>
                    <Label htmlFor="time">Time</Label>
                    <Input
                      id="time"
                      type="time"
                      value={formData.scheduleTime}
                      onChange={(e) =>
                        setFormData({ ...formData, scheduleTime: e.target.value })
                      }
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
              <h2 className="text-xl font-semibold text-foreground">
                Review & Send
              </h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Confirm your campaign details before sending
              </p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="rounded-lg border border-border p-4">
                <p className="text-sm text-muted-foreground">Campaign Name</p>
                <p className="mt-1 font-medium text-foreground">
                  {formData.name || "Untitled Campaign"}
                </p>
              </div>

              <div className="rounded-lg border border-border p-4">
                <p className="text-sm text-muted-foreground">Recipients</p>
                <p className="mt-1 font-medium text-foreground">
                  1,250 contacts
                </p>
              </div>

              <div className="rounded-lg border border-border p-4">
                <p className="text-sm text-muted-foreground">Sender</p>
                <p className="mt-1 font-medium text-foreground">
                  IEOSUIA
                </p>
              </div>

              <div className="rounded-lg border border-border p-4">
                <p className="text-sm text-muted-foreground">Delivery</p>
                <p className="mt-1 font-medium text-foreground">
                  {formData.scheduleType === "now"
                    ? "Send Immediately"
                    : `${formData.scheduleDate} at ${formData.scheduleTime}`}
                </p>
              </div>
            </div>

            <div className="rounded-lg bg-muted/50 p-4">
              <p className="text-sm font-medium text-foreground">Message</p>
              {abTestEnabled ? (
                <div className="mt-2 space-y-3">
                  <div className="rounded-lg border border-primary/30 bg-card p-3">
                    <div className="flex items-center gap-2 mb-1">
                      <div className="flex h-5 w-5 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">A</div>
                      <span className="text-xs text-muted-foreground">Variant A ({abSplitPercent}%)</span>
                    </div>
                    <p className="text-sm text-foreground">{abMessageA || "No message"}</p>
                  </div>
                  <div className="rounded-lg border border-accent/30 bg-card p-3">
                    <div className="flex items-center gap-2 mb-1">
                      <div className="flex h-5 w-5 items-center justify-center rounded-full bg-accent text-xs font-bold text-accent-foreground">B</div>
                      <span className="text-xs text-muted-foreground">Variant B ({100 - abSplitPercent}%)</span>
                    </div>
                    <p className="text-sm text-foreground">{abMessageB || "No message"}</p>
                  </div>
                </div>
              ) : (
                <p className="mt-2 text-sm text-foreground">
                  {formData.message || "No message entered"}
                </p>
              )}
            </div>

            <div className="flex items-center justify-between rounded-xl border-2 border-primary bg-primary/5 p-4">
              <div className="flex items-center gap-3">
                <CreditCard className="h-6 w-6 text-primary" />
                <div>
                  <p className="font-medium text-foreground">Estimated Cost</p>
                  <p className="text-sm text-muted-foreground">
                    {smsCount} SMS × 1,250 recipients
                  </p>
                </div>
              </div>
              <p className="text-2xl font-bold text-primary">
                {estimatedCredits.toLocaleString()} credits
              </p>
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
              Continue
              <ArrowRight className="h-4 w-4" />
            </Button>
          ) : (
            <Button onClick={handleSubmit} disabled={isSubmitting} className="gap-2">
              {isSubmitting ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <Send className="h-4 w-4" />
              )}
              {isSubmitting ? "Sending..." : "Send Campaign"}
            </Button>
          )}
        </div>
      </div>
    </DashboardLayout>
  );
}
