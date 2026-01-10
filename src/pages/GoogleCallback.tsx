import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Loader2 } from 'lucide-react';
import { toast } from '@/hooks/use-toast';
import { useGoogleAuth } from '@/hooks/useGoogleAuth';

const TOKEN_KEY = 'auth_token';
const USER_KEY = 'auth_user';
const TOKEN_ISSUED_KEY = 'auth_token_issued';

export default function GoogleCallback() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { handleCallback } = useGoogleAuth();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const processCallback = async () => {
      const code = searchParams.get('code');
      const state = searchParams.get('state');
      const errorParam = searchParams.get('error');

      if (errorParam) {
        setError('Google sign-in was cancelled or failed');
        toast({
          title: 'Sign-in cancelled',
          description: 'Google sign-in was cancelled.',
          variant: 'destructive',
        });
        setTimeout(() => navigate('/login'), 2000);
        return;
      }

      if (!code) {
        setError('No authorization code received');
        toast({
          title: 'Error',
          description: 'No authorization code received from Google.',
          variant: 'destructive',
        });
        setTimeout(() => navigate('/login'), 2000);
        return;
      }

      const result = await handleCallback(code, state || undefined);

      if (result.success && result.token && result.user) {
        // Store auth data
        localStorage.setItem(TOKEN_KEY, result.token);
        localStorage.setItem(USER_KEY, JSON.stringify(result.user));
        localStorage.setItem(TOKEN_ISSUED_KEY, Date.now().toString());

        toast({
          title: result.isNewUser ? 'Account created!' : 'Welcome back!',
          description: result.isNewUser 
            ? 'Your account has been created successfully.' 
            : 'You have been signed in successfully.',
        });

        // Redirect to dashboard
        navigate('/', { replace: true });
      } else {
        setError(result.error || 'Failed to sign in with Google');
        toast({
          title: 'Sign-in failed',
          description: result.error || 'Failed to sign in with Google.',
          variant: 'destructive',
        });
        setTimeout(() => navigate('/login'), 2000);
      }
    };

    processCallback();
  }, [searchParams, handleCallback, navigate]);

  return (
    <div className="min-h-screen flex items-center justify-center bg-background">
      <div className="text-center">
        {error ? (
          <>
            <p className="text-destructive text-lg mb-2">{error}</p>
            <p className="text-muted-foreground">Redirecting to login...</p>
          </>
        ) : (
          <>
            <Loader2 className="h-8 w-8 animate-spin mx-auto mb-4 text-primary" />
            <p className="text-lg font-medium">Completing sign-in...</p>
            <p className="text-muted-foreground mt-2">Please wait while we verify your Google account.</p>
          </>
        )}
      </div>
    </div>
  );
}
