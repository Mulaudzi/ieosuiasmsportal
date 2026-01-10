import { useState, useEffect, useCallback } from 'react';

const API_URL = import.meta.env.VITE_API_URL || 'https://sms.ieosuia.com/api';
const GOOGLE_CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID || '';

interface GoogleAuthState {
  isAvailable: boolean;
  isLoading: boolean;
  isInitialized: boolean;
}

interface GoogleAuthResult {
  success: boolean;
  user?: {
    id: string;
    email: string;
    name?: string;
    avatar_url?: string;
  };
  token?: string;
  isNewUser?: boolean;
  error?: string;
}

export function useGoogleAuth() {
  const [state, setState] = useState<GoogleAuthState>({
    isAvailable: false,
    isLoading: true,
    isInitialized: false,
  });

  // Check if Google OAuth is available on the backend
  useEffect(() => {
    const checkStatus = async () => {
      try {
        const response = await fetch(`${API_URL}/auth/google/status`);
        const data = await response.json();
        setState(prev => ({
          ...prev,
          isAvailable: data.data?.available ?? false,
          isLoading: false,
          isInitialized: true,
        }));
      } catch (error) {
        console.error('Failed to check Google OAuth status:', error);
        setState(prev => ({
          ...prev,
          isAvailable: false,
          isLoading: false,
          isInitialized: true,
        }));
      }
    };

    checkStatus();
  }, []);

  // Initialize Google Identity Services
  useEffect(() => {
    if (!GOOGLE_CLIENT_ID || !state.isAvailable) return;

    const script = document.createElement('script');
    script.src = 'https://accounts.google.com/gsi/client';
    script.async = true;
    script.defer = true;
    document.head.appendChild(script);

    return () => {
      document.head.removeChild(script);
    };
  }, [state.isAvailable]);

  // Sign in with Google popup/redirect
  const signInWithGoogle = useCallback(async (): Promise<GoogleAuthResult> => {
    if (!state.isAvailable) {
      return { success: false, error: 'Google OAuth is not available' };
    }

    try {
      // Get the auth URL from backend
      const urlResponse = await fetch(`${API_URL}/auth/google/url`);
      const urlData = await urlResponse.json();

      if (!urlData.success || !urlData.data?.auth_url) {
        return { success: false, error: 'Failed to get Google auth URL' };
      }

      // Store state for CSRF verification
      sessionStorage.setItem('google_oauth_state', urlData.data.state);

      // Redirect to Google OAuth
      window.location.href = urlData.data.auth_url;

      // This won't be reached due to redirect
      return { success: true };
    } catch (error) {
      console.error('Google sign-in error:', error);
      return { success: false, error: 'Failed to initiate Google sign-in' };
    }
  }, [state.isAvailable]);

  // Handle the OAuth callback (call this from callback page)
  const handleCallback = useCallback(async (code: string, state?: string): Promise<GoogleAuthResult> => {
    try {
      // Verify state if provided
      const storedState = sessionStorage.getItem('google_oauth_state');
      if (state && storedState && state !== storedState) {
        return { success: false, error: 'Invalid OAuth state' };
      }
      sessionStorage.removeItem('google_oauth_state');

      const response = await fetch(`${API_URL}/auth/google/callback`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code }),
      });

      const data = await response.json();

      if (data.success && data.data?.token) {
        return {
          success: true,
          user: data.data.user,
          token: data.data.token,
          isNewUser: data.data.is_new_user,
        };
      }

      return { success: false, error: data.message || 'Google sign-in failed' };
    } catch (error) {
      console.error('Google callback error:', error);
      return { success: false, error: 'Failed to complete Google sign-in' };
    }
  }, []);

  // Sign in with Google credential (for One-Tap or ID token from GSI)
  const signInWithCredential = useCallback(async (credential: string): Promise<GoogleAuthResult> => {
    try {
      const response = await fetch(`${API_URL}/auth/google/credential`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ credential }),
      });

      const data = await response.json();

      if (data.success && data.data?.token) {
        return {
          success: true,
          user: data.data.user,
          token: data.data.token,
          isNewUser: data.data.is_new_user,
        };
      }

      return { success: false, error: data.message || 'Google sign-in failed' };
    } catch (error) {
      console.error('Google credential sign-in error:', error);
      return { success: false, error: 'Failed to sign in with Google' };
    }
  }, []);

  return {
    isAvailable: state.isAvailable,
    isLoading: state.isLoading,
    isInitialized: state.isInitialized,
    signInWithGoogle,
    handleCallback,
    signInWithCredential,
  };
}
