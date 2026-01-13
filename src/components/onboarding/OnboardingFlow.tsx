import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import {
  MessageSquare,
  Users,
  FileText,
  Send,
  Check,
  ArrowRight,
  ArrowLeft,
  Sparkles,
  Upload,
  Edit3,
  Calendar,
  BarChart3,
} from "lucide-react";
import { cn } from "@/lib/utils";

interface OnboardingStep {
  id: string;
  title: string;
  description: string;
  icon: React.ElementType;
  content: React.ReactNode;
}

interface OnboardingFlowProps {
  onComplete: () => void;
  onSkip: () => void;
}

export function OnboardingFlow({ onComplete, onSkip }: OnboardingFlowProps) {
  const navigate = useNavigate();
  const [currentStep, setCurrentStep] = useState(0);

  const steps: OnboardingStep[] = [
    {
      id: "welcome",
      title: "Welcome to IEOSUIA SMS Portal",
      description: "Let's get you set up to send your first SMS campaign",
      icon: Sparkles,
      content: (
        <div className="text-center space-y-6">
          <div className="flex justify-center">
            <div className="h-24 w-24 rounded-full bg-primary/10 flex items-center justify-center">
              <MessageSquare className="h-12 w-12 text-primary" />
            </div>
          </div>
          <div>
            <h3 className="text-2xl font-bold text-foreground mb-2">
              Ready to reach your customers?
            </h3>
            <p className="text-muted-foreground max-w-md mx-auto">
              We'll guide you through setting up your first SMS campaign in just a few simple steps.
            </p>
          </div>
          <div className="grid gap-4 max-w-md mx-auto">
            <div className="flex items-center gap-3 bg-muted/50 rounded-lg p-4 text-left">
              <Check className="h-5 w-5 text-success shrink-0" />
              <span className="text-sm text-foreground">27 free SMS credits to get started</span>
            </div>
            <div className="flex items-center gap-3 bg-muted/50 rounded-lg p-4 text-left">
              <Check className="h-5 w-5 text-success shrink-0" />
              <span className="text-sm text-foreground">No credit card required</span>
            </div>
            <div className="flex items-center gap-3 bg-muted/50 rounded-lg p-4 text-left">
              <Check className="h-5 w-5 text-success shrink-0" />
              <span className="text-sm text-foreground">POPIA compliant from day one</span>
            </div>
          </div>
        </div>
      ),
    },
    {
      id: "contacts",
      title: "Add Your Contacts",
      description: "Import or add contacts to send messages to",
      icon: Users,
      content: (
        <div className="space-y-6">
          <div className="text-center mb-8">
            <div className="flex justify-center mb-4">
              <div className="h-16 w-16 rounded-full bg-primary/10 flex items-center justify-center">
                <Users className="h-8 w-8 text-primary" />
              </div>
            </div>
            <h3 className="text-xl font-bold text-foreground mb-2">
              Build Your Contact List
            </h3>
            <p className="text-muted-foreground">
              You have three easy ways to add contacts
            </p>
          </div>
          <div className="grid gap-4">
            <button
              onClick={() => navigate("/contacts")}
              className="flex items-start gap-4 bg-card border border-border rounded-xl p-5 text-left hover:border-primary/50 transition-colors group"
            >
              <div className="h-12 w-12 rounded-lg bg-primary/10 flex items-center justify-center shrink-0 group-hover:bg-primary/20 transition-colors">
                <Upload className="h-6 w-6 text-primary" />
              </div>
              <div className="flex-1">
                <h4 className="font-semibold text-foreground mb-1">Import from CSV/Excel</h4>
                <p className="text-sm text-muted-foreground">
                  Upload a spreadsheet with your contacts. We'll map the columns automatically.
                </p>
              </div>
              <ArrowRight className="h-5 w-5 text-muted-foreground group-hover:text-primary transition-colors mt-1" />
            </button>
            <button
              onClick={() => navigate("/contacts")}
              className="flex items-start gap-4 bg-card border border-border rounded-xl p-5 text-left hover:border-primary/50 transition-colors group"
            >
              <div className="h-12 w-12 rounded-lg bg-primary/10 flex items-center justify-center shrink-0 group-hover:bg-primary/20 transition-colors">
                <Edit3 className="h-6 w-6 text-primary" />
              </div>
              <div className="flex-1">
                <h4 className="font-semibold text-foreground mb-1">Add Manually</h4>
                <p className="text-sm text-muted-foreground">
                  Add contacts one by one with full control over their details.
                </p>
              </div>
              <ArrowRight className="h-5 w-5 text-muted-foreground group-hover:text-primary transition-colors mt-1" />
            </button>
            <div className="flex items-start gap-4 bg-muted/30 border border-border/50 rounded-xl p-5 text-left">
              <div className="h-12 w-12 rounded-lg bg-muted flex items-center justify-center shrink-0">
                <Users className="h-6 w-6 text-muted-foreground" />
              </div>
              <div className="flex-1">
                <h4 className="font-semibold text-foreground mb-1">Create Groups</h4>
                <p className="text-sm text-muted-foreground">
                  Organize contacts into groups for targeted campaigns.
                </p>
              </div>
            </div>
          </div>
        </div>
      ),
    },
    {
      id: "templates",
      title: "Create Message Templates",
      description: "Save time with reusable message templates",
      icon: FileText,
      content: (
        <div className="space-y-6">
          <div className="text-center mb-8">
            <div className="flex justify-center mb-4">
              <div className="h-16 w-16 rounded-full bg-primary/10 flex items-center justify-center">
                <FileText className="h-8 w-8 text-primary" />
              </div>
            </div>
            <h3 className="text-xl font-bold text-foreground mb-2">
              Message Templates
            </h3>
            <p className="text-muted-foreground">
              Create templates once, use them for multiple campaigns
            </p>
          </div>
          <div className="bg-card border border-border rounded-xl p-6">
            <div className="mb-4">
              <span className="text-xs font-medium text-primary bg-primary/10 px-2 py-1 rounded-full">
                Example Template
              </span>
            </div>
            <div className="bg-muted/50 rounded-lg p-4 mb-4">
              <p className="text-foreground">
                Hi <span className="text-primary font-medium">{"{{name}}"}</span>! 🎉
              </p>
              <p className="text-foreground mt-2">
                Your order #<span className="text-primary font-medium">{"{{order_id}}"}</span> has been shipped!
                Track it here: <span className="text-primary font-medium">{"{{tracking_url}}"}</span>
              </p>
              <p className="text-muted-foreground text-sm mt-2">
                - Your Business Name
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <span className="text-xs bg-muted px-3 py-1.5 rounded-full text-muted-foreground">
                {"{{name}}"} - Contact's name
              </span>
              <span className="text-xs bg-muted px-3 py-1.5 rounded-full text-muted-foreground">
                {"{{phone}}"} - Phone number
              </span>
              <span className="text-xs bg-muted px-3 py-1.5 rounded-full text-muted-foreground">
                {"{{custom}}"} - Your data
              </span>
            </div>
          </div>
          <Button 
            variant="outline" 
            className="w-full gap-2"
            onClick={() => navigate("/templates")}
          >
            <FileText className="h-4 w-4" />
            Go to Templates
          </Button>
        </div>
      ),
    },
    {
      id: "campaign",
      title: "Send Your First Campaign",
      description: "You're ready to send your first SMS campaign!",
      icon: Send,
      content: (
        <div className="space-y-6">
          <div className="text-center mb-8">
            <div className="flex justify-center mb-4">
              <div className="h-16 w-16 rounded-full bg-primary/10 flex items-center justify-center">
                <Send className="h-8 w-8 text-primary" />
              </div>
            </div>
            <h3 className="text-xl font-bold text-foreground mb-2">
              Create Your Campaign
            </h3>
            <p className="text-muted-foreground">
              Choose your approach based on your needs
            </p>
          </div>
          <div className="grid gap-4">
            <button
              onClick={() => navigate("/sms-campaigns/new")}
              className="flex items-start gap-4 bg-card border-2 border-primary rounded-xl p-5 text-left hover:bg-primary/5 transition-colors group"
            >
              <div className="h-12 w-12 rounded-lg bg-primary/10 flex items-center justify-center shrink-0">
                <Send className="h-6 w-6 text-primary" />
              </div>
              <div className="flex-1">
                <div className="flex items-center gap-2 mb-1">
                  <h4 className="font-semibold text-foreground">Send Immediately</h4>
                  <span className="text-xs bg-primary/10 text-primary px-2 py-0.5 rounded-full">
                    Recommended
                  </span>
                </div>
                <p className="text-sm text-muted-foreground">
                  Create and send your campaign right away to reach customers instantly.
                </p>
              </div>
              <ArrowRight className="h-5 w-5 text-primary mt-1" />
            </button>
            <button
              onClick={() => navigate("/sms-campaigns/new")}
              className="flex items-start gap-4 bg-card border border-border rounded-xl p-5 text-left hover:border-primary/50 transition-colors group"
            >
              <div className="h-12 w-12 rounded-lg bg-muted flex items-center justify-center shrink-0 group-hover:bg-primary/10 transition-colors">
                <Calendar className="h-6 w-6 text-muted-foreground group-hover:text-primary transition-colors" />
              </div>
              <div className="flex-1">
                <h4 className="font-semibold text-foreground mb-1">Schedule for Later</h4>
                <p className="text-sm text-muted-foreground">
                  Plan campaigns in advance and schedule them for optimal delivery times.
                </p>
              </div>
              <ArrowRight className="h-5 w-5 text-muted-foreground group-hover:text-primary transition-colors mt-1" />
            </button>
            <div className="flex items-start gap-4 bg-muted/30 border border-border/50 rounded-xl p-5 text-left">
              <div className="h-12 w-12 rounded-lg bg-muted flex items-center justify-center shrink-0">
                <BarChart3 className="h-6 w-6 text-muted-foreground" />
              </div>
              <div className="flex-1">
                <h4 className="font-semibold text-foreground mb-1">A/B Testing</h4>
                <p className="text-sm text-muted-foreground">
                  Test different messages to see what works best with your audience.
                </p>
              </div>
            </div>
          </div>
        </div>
      ),
    },
  ];

  const progress = ((currentStep + 1) / steps.length) * 100;

  const handleNext = () => {
    if (currentStep < steps.length - 1) {
      setCurrentStep(currentStep + 1);
    } else {
      onComplete();
    }
  };

  const handleBack = () => {
    if (currentStep > 0) {
      setCurrentStep(currentStep - 1);
    }
  };

  const currentStepData = steps[currentStep];
  const StepIcon = currentStepData.icon;

  return (
    <div className="fixed inset-0 z-50 bg-background/95 backdrop-blur-sm flex items-center justify-center p-4">
      <div className="bg-card border border-border rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col">
        {/* Header */}
        <div className="p-6 border-b border-border">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-lg bg-primary/10 flex items-center justify-center">
                <StepIcon className="h-5 w-5 text-primary" />
              </div>
              <div>
                <p className="text-sm text-muted-foreground">
                  Step {currentStep + 1} of {steps.length}
                </p>
                <h2 className="text-lg font-semibold text-foreground">
                  {currentStepData.title}
                </h2>
              </div>
            </div>
            <Button variant="ghost" size="sm" onClick={onSkip}>
              Skip Setup
            </Button>
          </div>
          <Progress value={progress} className="h-2" />
        </div>

        {/* Step Indicators */}
        <div className="px-6 py-4 border-b border-border bg-muted/30">
          <div className="flex justify-between">
            {steps.map((step, index) => {
              const Icon = step.icon;
              const isCompleted = index < currentStep;
              const isCurrent = index === currentStep;
              return (
                <div
                  key={step.id}
                  className={cn(
                    "flex items-center gap-2",
                    index < steps.length - 1 && "flex-1"
                  )}
                >
                  <div
                    className={cn(
                      "h-8 w-8 rounded-full flex items-center justify-center shrink-0 transition-colors",
                      isCompleted && "bg-success text-success-foreground",
                      isCurrent && "bg-primary text-primary-foreground",
                      !isCompleted && !isCurrent && "bg-muted text-muted-foreground"
                    )}
                  >
                    {isCompleted ? (
                      <Check className="h-4 w-4" />
                    ) : (
                      <Icon className="h-4 w-4" />
                    )}
                  </div>
                  {index < steps.length - 1 && (
                    <div
                      className={cn(
                        "h-0.5 flex-1 transition-colors",
                        isCompleted ? "bg-success" : "bg-border"
                      )}
                    />
                  )}
                </div>
              );
            })}
          </div>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-6">
          {currentStepData.content}
        </div>

        {/* Footer */}
        <div className="p-6 border-t border-border bg-muted/30 flex items-center justify-between">
          <Button
            variant="ghost"
            onClick={handleBack}
            disabled={currentStep === 0}
            className="gap-2"
          >
            <ArrowLeft className="h-4 w-4" />
            Back
          </Button>
          <Button onClick={handleNext} className="gap-2">
            {currentStep === steps.length - 1 ? (
              <>
                Get Started
                <Sparkles className="h-4 w-4" />
              </>
            ) : (
              <>
                Continue
                <ArrowRight className="h-4 w-4" />
              </>
            )}
          </Button>
        </div>
      </div>
    </div>
  );
}
