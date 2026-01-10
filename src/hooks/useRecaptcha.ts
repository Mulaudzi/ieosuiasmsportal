import { useState, useEffect, useCallback } from 'react';

// reCAPTCHA v3 site key (public - safe to include in code)
const RECAPTCHA_SITE_KEY = '6LcG2EUsAAAAAPzqfSAdyHvZL3mhP2av8Xj5VEL0';

declare global {
  interface Window {
    grecaptcha: {
      ready: (callback: () => void) => void;
      execute: (siteKey: string, options: { action: string }) => Promise<string>;
    };
  }
}

export function useRecaptcha() {
  const [isLoaded, setIsLoaded] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [isConfigured] = useState(!!RECAPTCHA_SITE_KEY);

  useEffect(() => {
    // If no site key, skip loading
    if (!RECAPTCHA_SITE_KEY) {
      setIsLoading(false);
      return;
    }

    // Check if already loaded
    if (window.grecaptcha) {
      window.grecaptcha.ready(() => {
        setIsLoaded(true);
        setIsLoading(false);
      });
      return;
    }

    // Load reCAPTCHA script
    const script = document.createElement('script');
    script.src = `https://www.google.com/recaptcha/api.js?render=${RECAPTCHA_SITE_KEY}`;
    script.async = true;
    script.defer = true;

    script.onload = () => {
      window.grecaptcha.ready(() => {
        setIsLoaded(true);
        setIsLoading(false);
      });
    };

    script.onerror = () => {
      console.error('Failed to load reCAPTCHA');
      setIsLoading(false);
    };

    document.head.appendChild(script);
  }, []);

  const executeRecaptcha = useCallback(async (action: string): Promise<string | null> => {
    // Return null if not configured (backend will handle gracefully)
    if (!RECAPTCHA_SITE_KEY || !isLoaded || !window.grecaptcha) {
      return null;
    }

    try {
      return await window.grecaptcha.execute(RECAPTCHA_SITE_KEY, { action });
    } catch (error) {
      console.error('reCAPTCHA execution failed:', error);
      return null;
    }
  }, [isLoaded]);

  return {
    isLoaded,
    isLoading,
    isConfigured,
    executeRecaptcha,
  };
}

export { RECAPTCHA_SITE_KEY };
