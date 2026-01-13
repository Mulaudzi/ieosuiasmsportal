import { useState, useEffect } from "react";
import { OnboardingFlow } from "./OnboardingFlow";

const ONBOARDING_COMPLETE_KEY = "ieosuia_onboarding_complete";

export function OnboardingTrigger() {
  const [showOnboarding, setShowOnboarding] = useState(false);

  useEffect(() => {
    // Check if user has completed onboarding
    const isComplete = localStorage.getItem(ONBOARDING_COMPLETE_KEY);
    if (!isComplete) {
      // Small delay to let the page load first
      const timer = setTimeout(() => {
        setShowOnboarding(true);
      }, 500);
      return () => clearTimeout(timer);
    }
  }, []);

  const handleComplete = () => {
    localStorage.setItem(ONBOARDING_COMPLETE_KEY, "true");
    setShowOnboarding(false);
  };

  const handleSkip = () => {
    localStorage.setItem(ONBOARDING_COMPLETE_KEY, "true");
    setShowOnboarding(false);
  };

  if (!showOnboarding) return null;

  return <OnboardingFlow onComplete={handleComplete} onSkip={handleSkip} />;
}

// Export a function to reset onboarding (useful for testing/settings)
export const resetOnboarding = () => {
  localStorage.removeItem(ONBOARDING_COMPLETE_KEY);
};
