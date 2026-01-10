import { useCallback, useEffect, useState } from 'react';

// Your reCAPTCHA v3 site key (public - safe to include in code)
const RECAPTCHA_SITE_KEY = '6LcG2EUsAAAAAPzqfSAdyHvZL3mhP2av8Xj5VEL0'; // Replace with your actual site key

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

  useEffect(() => {
    // Check if script is already loaded
    if (window.grecaptcha) {
      setIsLoaded(true);
      setIsLoading(false);
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

    return () => {
      // Cleanup is optional - reCAPTCHA usually stays loaded
    };
  }, []);

  const executeRecaptcha = useCallback(async (action: string): Promise<string | null> => {
    if (!isLoaded || !window.grecaptcha) {
      console.warn('reCAPTCHA not loaded');
      return null;
    }

    try {
      const token = await window.grecaptcha.execute(RECAPTCHA_SITE_KEY, { action });
      return token;
    } catch (error) {
      console.error('reCAPTCHA execution failed:', error);
      return null;
    }
  }, [isLoaded]);

  return {
    isLoaded,
    isLoading,
    executeRecaptcha,
  };
}

export { RECAPTCHA_SITE_KEY };
