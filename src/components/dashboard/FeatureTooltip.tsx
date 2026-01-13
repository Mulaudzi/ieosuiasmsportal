import { useState, useEffect } from "react";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { Button } from "@/components/ui/button";
import { HelpCircle, X, Lightbulb } from "lucide-react";
import { cn } from "@/lib/utils";

interface FeatureTooltipProps {
  children: React.ReactNode;
  title: string;
  description: string;
  tip?: string;
  side?: "top" | "right" | "bottom" | "left";
  showIndicator?: boolean;
  className?: string;
}

export function FeatureTooltip({
  children,
  title,
  description,
  tip,
  side = "top",
  showIndicator = true,
  className,
}: FeatureTooltipProps) {
  return (
    <Tooltip delayDuration={300}>
      <TooltipTrigger asChild>
        <div className={cn("relative group", className)}>
          {children}
          {showIndicator && (
            <div className="absolute -top-1 -right-1 z-10 opacity-0 group-hover:opacity-100 transition-opacity">
              <div className="h-5 w-5 rounded-full bg-primary/90 flex items-center justify-center shadow-lg">
                <HelpCircle className="h-3 w-3 text-primary-foreground" />
              </div>
            </div>
          )}
        </div>
      </TooltipTrigger>
      <TooltipContent 
        side={side} 
        className="max-w-xs p-4 bg-card border-border shadow-xl"
        sideOffset={8}
      >
        <div className="space-y-2">
          <h4 className="font-semibold text-foreground text-sm">{title}</h4>
          <p className="text-xs text-muted-foreground leading-relaxed">{description}</p>
          {tip && (
            <div className="flex items-start gap-2 pt-2 border-t border-border mt-2">
              <Lightbulb className="h-3.5 w-3.5 text-amber-500 shrink-0 mt-0.5" />
              <p className="text-xs text-amber-600 dark:text-amber-400">{tip}</p>
            </div>
          )}
        </div>
      </TooltipContent>
    </Tooltip>
  );
}

// Tutorial overlay component for first-time users
const TUTORIAL_COMPLETE_KEY = "ieosuia_dashboard_tutorial_complete";

interface TutorialStep {
  id: string;
  title: string;
  description: string;
  target: string;
  position: "top" | "right" | "bottom" | "left";
}

const tutorialSteps: TutorialStep[] = [
  {
    id: "metrics",
    title: "Performance Metrics",
    description: "Track your SMS and email sending activity, total contacts, and overall delivery rate at a glance.",
    target: "metrics-section",
    position: "bottom",
  },
  {
    id: "chart",
    title: "Campaign Analytics",
    description: "Visualize your messaging trends over time. See patterns in your sending activity to optimize timing.",
    target: "chart-section",
    position: "top",
  },
  {
    id: "campaigns",
    title: "Recent Campaigns",
    description: "Quick access to your latest campaigns with status and performance data.",
    target: "campaigns-section",
    position: "top",
  },
  {
    id: "actions",
    title: "Quick Actions",
    description: "Shortcuts to common tasks like importing contacts, managing templates, and viewing reports.",
    target: "actions-section",
    position: "top",
  },
];

interface DashboardTutorialProps {
  onComplete: () => void;
}

export function DashboardTutorial({ onComplete }: DashboardTutorialProps) {
  const [currentStep, setCurrentStep] = useState(0);
  const [isVisible, setIsVisible] = useState(true);

  const handleNext = () => {
    if (currentStep < tutorialSteps.length - 1) {
      setCurrentStep(currentStep + 1);
    } else {
      handleComplete();
    }
  };

  const handleComplete = () => {
    localStorage.setItem(TUTORIAL_COMPLETE_KEY, "true");
    setIsVisible(false);
    onComplete();
  };

  const handleSkip = () => {
    handleComplete();
  };

  if (!isVisible) return null;

  const step = tutorialSteps[currentStep];

  return (
    <div className="fixed inset-0 z-50 pointer-events-none">
      {/* Backdrop */}
      <div className="absolute inset-0 bg-background/60 backdrop-blur-sm pointer-events-auto" />
      
      {/* Tutorial Card */}
      <div className="absolute bottom-6 left-1/2 -translate-x-1/2 pointer-events-auto">
        <div className="bg-card border border-border rounded-xl shadow-2xl p-6 max-w-md">
          <div className="flex items-start justify-between mb-4">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-lg bg-primary/10 flex items-center justify-center">
                <Lightbulb className="h-5 w-5 text-primary" />
              </div>
              <div>
                <p className="text-xs text-muted-foreground">
                  Tip {currentStep + 1} of {tutorialSteps.length}
                </p>
                <h3 className="font-semibold text-foreground">{step.title}</h3>
              </div>
            </div>
            <Button variant="ghost" size="icon" onClick={handleSkip} className="h-8 w-8">
              <X className="h-4 w-4" />
            </Button>
          </div>
          
          <p className="text-sm text-muted-foreground mb-6">
            {step.description}
          </p>

          {/* Progress dots */}
          <div className="flex items-center justify-between">
            <div className="flex gap-1.5">
              {tutorialSteps.map((_, index) => (
                <div
                  key={index}
                  className={cn(
                    "h-2 w-2 rounded-full transition-colors",
                    index === currentStep ? "bg-primary" : "bg-muted"
                  )}
                />
              ))}
            </div>
            
            <div className="flex gap-2">
              <Button variant="ghost" size="sm" onClick={handleSkip}>
                Skip Tour
              </Button>
              <Button size="sm" onClick={handleNext}>
                {currentStep === tutorialSteps.length - 1 ? "Got it!" : "Next"}
              </Button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

// Hook to check if tutorial should be shown
export function useDashboardTutorial() {
  const [showTutorial, setShowTutorial] = useState(false);

  useEffect(() => {
    const tutorialComplete = localStorage.getItem(TUTORIAL_COMPLETE_KEY);
    const onboardingComplete = localStorage.getItem("ieosuia_onboarding_complete");
    
    // Show tutorial after onboarding is complete but tutorial hasn't been shown
    if (onboardingComplete && !tutorialComplete) {
      // Small delay to let the page render first
      const timer = setTimeout(() => {
        setShowTutorial(true);
      }, 1000);
      return () => clearTimeout(timer);
    }
  }, []);

  const completeTutorial = () => {
    setShowTutorial(false);
  };

  const resetTutorial = () => {
    localStorage.removeItem(TUTORIAL_COMPLETE_KEY);
  };

  return { showTutorial, completeTutorial, resetTutorial };
}

// Export reset function for settings
export const resetDashboardTutorial = () => {
  localStorage.removeItem(TUTORIAL_COMPLETE_KEY);
};
