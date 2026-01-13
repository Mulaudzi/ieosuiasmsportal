import { useState, useEffect, useCallback } from 'react';

const API_URL = import.meta.env.VITE_API_URL || 'https://sms.ieosuia.com/api';

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
        
        // Handle non-JSON responses gracefully
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
          console.warn('Google OAuth status check returned non-JSON response');
          setState(prev => ({
            ...prev,
            isAvailable: false,
            isLoading: false,
            isInitialized: true,
          }));
          return;
        }
        
        if (!response.ok) {
          // Don't log 500/503 as errors since Google OAuth may just not be configured
          if (response.status !== 503 && response.status !== 500) {
            console.warn('Google OAuth status check failed:', response.status);
          }
          setState(prev => ({
            ...prev,
            isAvailable: false,
            isLoading: false,
            isInitialized: true,
          }));
          return;
        }
        
        const text = await response.text();
        if (!text.trim()) {
          setState(prev => ({
            ...prev,
            isAvailable: false,
            isLoading: false,
            isInitialized: true,
          }));
          return;
        }
        
        const data = JSON.parse(text);
        
        // Handle both response formats
        const available = data.data?.available ?? data.available ?? false;
        
        setState(prev => ({
          ...prev,
          isAvailable: available,
          isLoading: false,
          isInitialized: true,
        }));
      } catch (error) {
        // Silently handle network errors - Google OAuth just won't be available
        console.debug('Google OAuth not available:', error);
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

  // Sign in with Google popup/redirect
  const signInWithGoogle = useCallback(async (): Promise<GoogleAuthResult> => {
    if (!state.isAvailable) {
      return { success: false, error: 'Google sign-in is not configured' };
    }

    try {
      const urlResponse = await fetch(`${API_URL}/auth/google/url`);
      
      if (!urlResponse.ok) {
        const errorData = await urlResponse.json().catch(() => ({}));
        return { success: false, error: errorData.message || 'Failed to get Google auth URL' };
      }
      
      const urlData = await urlResponse.json();

      // Handle both response formats
      const authUrl = urlData.data?.auth_url ?? urlData.auth_url;
      const oauthState = urlData.data?.state ?? urlData.state;

      if (!authUrl) {
        return { success: false, error: 'Failed to get Google auth URL' };
      }

      // Store state for CSRF verification
      if (oauthState) {
        sessionStorage.setItem('google_oauth_state', oauthState);
      }

      // Redirect to Google OAuth
      window.location.href = authUrl;

      return { success: true };
    } catch (error) {
      console.error('Google sign-in error:', error);
      return { success: false, error: 'Failed to initiate Google sign-in' };
    }
  }, [state.isAvailable]);

  // Handle the OAuth callback
  const handleCallback = useCallback(async (code: string, oauthState?: string): Promise<GoogleAuthResult> => {
    try {
      // Verify state if provided
      const storedState = sessionStorage.getItem('google_oauth_state');
      if (oauthState && storedState && oauthState !== storedState) {
        return { success: false, error: 'Invalid OAuth state - possible CSRF attack' };
      }
      sessionStorage.removeItem('google_oauth_state');

      const response = await fetch(`${API_URL}/auth/google/callback`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code }),
      });

      const data = await response.json();

      if (!response.ok) {
        return { success: false, error: data.message || 'Google sign-in failed' };
      }

      // Handle both response formats
      const token = data.data?.token ?? data.token;
      const user = data.data?.user ?? data.user;
      const isNewUser = data.data?.is_new_user ?? data.is_new_user ?? false;

      if (token && user) {
        return {
          success: true,
          user,
          token,
          isNewUser,
        };
      }

      return { success: false, error: data.message || 'Google sign-in failed' };
    } catch (error) {
      console.error('Google callback error:', error);
      return { success: false, error: 'Failed to complete Google sign-in' };
    }
  }, []);

  // Sign in with Google credential (for One-Tap)
  const signInWithCredential = useCallback(async (credential: string): Promise<GoogleAuthResult> => {
    try {
      const response = await fetch(`${API_URL}/auth/google/credential`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ credential }),
      });

      const data = await response.json();

      if (!response.ok) {
        return { success: false, error: data.message || 'Google sign-in failed' };
      }

      const token = data.data?.token ?? data.token;
      const user = data.data?.user ?? data.user;
      const isNewUser = data.data?.is_new_user ?? data.is_new_user ?? false;

      if (token && user) {
        return {
          success: true,
          user,
          token,
          isNewUser,
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
