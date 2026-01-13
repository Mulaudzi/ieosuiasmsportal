import { createContext, useContext, useState, useEffect, useRef, useCallback, ReactNode } from "react";
import { useNavigate, useLocation } from "react-router-dom";

interface User {
  id: string;
  name?: string;
  email: string;
  phone?: string;
  avatar_url?: string;
  account_type?: string;
  email_verified?: boolean;
  email_verified_at?: string | null;
  created_at?: string;
}

interface AuthContextType {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  isEmailVerified: boolean;
  isLoading: boolean;
  login: (email: string, password: string, recaptchaToken?: string, password2?: string, password3?: string) => Promise<{ success: boolean; error?: string; requires_admin_auth?: boolean }>;
  register: (data: { name: string; email: string; password: string; accountType: string; recaptchaToken?: string }) => Promise<{ success: boolean; error?: string }>;
  logout: () => Promise<void>;
  verifyEmail: (token: string) => Promise<{ success: boolean; error?: string }>;
  resendVerification: () => Promise<{ success: boolean; error?: string }>;
  forgotPassword: (email: string, recaptchaToken?: string) => Promise<{ success: boolean; error?: string }>;
  resetPassword: (email: string, otp: string, password: string, recaptchaToken?: string) => Promise<{ success: boolean; error?: string }>;
  updateUser: (data: Partial<User> & { password?: string; current_password?: string; password_confirmation?: string }) => Promise<{ success: boolean; error?: string }>;
  setAuthFromGoogle: (user: User, token: string) => void;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

const TOKEN_KEY = "auth_token";
const USER_KEY = "auth_user";
const TOKEN_ISSUED_KEY = "auth_token_issued";
const API_URL = import.meta.env.VITE_API_URL || "https://sms.ieosuia.com/api";

const TOKEN_LIFETIME_MS = 24 * 60 * 60 * 1000;
const TOKEN_REFRESH_THRESHOLD_MS = 2 * 60 * 60 * 1000;

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const refreshTimeoutRef = useRef<NodeJS.Timeout | null>(null);

  const scheduleTokenRefresh = (tokenIssuedAt: number) => {
    if (refreshTimeoutRef.current) {
      clearTimeout(refreshTimeoutRef.current);
    }

    const now = Date.now();
    const expiresAt = tokenIssuedAt + TOKEN_LIFETIME_MS;
    const refreshAt = expiresAt - TOKEN_REFRESH_THRESHOLD_MS;
    const timeUntilRefresh = refreshAt - now;

    if (timeUntilRefresh <= 0) {
      refreshToken();
    } else {
      refreshTimeoutRef.current = setTimeout(() => {
        refreshToken();
      }, timeUntilRefresh);
    }
  };

  const refreshToken = async () => {
    const currentToken = localStorage.getItem(TOKEN_KEY);
    if (!currentToken) return;

    try {
      const response = await fetch(`${API_URL}/auth/refresh`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${currentToken}`,
        },
      });

      if (response.ok) {
        const data = await response.json();
        if (data.success && data.data?.token) {
          const newToken = data.data.token;
          const issuedAt = Date.now();
          
          setToken(newToken);
          localStorage.setItem(TOKEN_KEY, newToken);
          localStorage.setItem(TOKEN_ISSUED_KEY, issuedAt.toString());
          scheduleTokenRefresh(issuedAt);
        }
      } else if (response.status === 401) {
        handleLogout();
      }
    } catch (error) {
      console.error("Token refresh error:", error);
    }
  };

  const handleLogout = () => {
    if (refreshTimeoutRef.current) {
      clearTimeout(refreshTimeoutRef.current);
    }
    setToken(null);
    setUser(null);
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    localStorage.removeItem(TOKEN_ISSUED_KEY);
  };

  useEffect(() => {
    const storedToken = localStorage.getItem(TOKEN_KEY);
    const storedUser = localStorage.getItem(USER_KEY);
    const storedIssuedAt = localStorage.getItem(TOKEN_ISSUED_KEY);
    
    if (storedToken && storedUser) {
      setToken(storedToken);
      try {
        setUser(JSON.parse(storedUser));
      } catch {
        handleLogout();
      }
      
      const issuedAt = storedIssuedAt ? parseInt(storedIssuedAt, 10) : Date.now();
      const expiresAt = issuedAt + TOKEN_LIFETIME_MS;
      
      if (Date.now() < expiresAt) {
        scheduleTokenRefresh(issuedAt);
      } else {
        handleLogout();
      }
    }
    setIsLoading(false);

    return () => {
      if (refreshTimeoutRef.current) {
        clearTimeout(refreshTimeoutRef.current);
      }
    };
  }, []);

  const login = async (email: string, password: string, recaptchaToken?: string, password2?: string, password3?: string): Promise<{ success: boolean; error?: string; requires_admin_auth?: boolean }> => {
    try {
      const response = await fetch(`${API_URL}/auth/login`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ 
          email, 
          password, 
          password_2: password2,
          password_3: password3,
          recaptcha_token: recaptchaToken 
        }),
      });

      const data = await response.json();

      // Check if admin auth is required (3 passwords needed)
      if (data.success && data.data?.requires_admin_auth) {
        return { success: false, requires_admin_auth: true };
      }

      // Handle different HTTP status codes with specific messages
      if (response.status === 404) {
        return { success: false, error: data.message || "No account found with this email" };
      }
      
      if (response.status === 401) {
        return { success: false, error: data.message || "Invalid password" };
      }
      
      if (response.status === 400) {
        return { success: false, error: data.message || "Invalid request" };
      }
      
      if (response.status === 429) {
        return { success: false, error: "Too many login attempts. Please try again later." };
      }

      if (!response.ok) {
        return { success: false, error: data.message || "Login failed" };
      }

      // Successful response - handle both nested (data.data) and flat response formats
      const userData = data.data?.user || data.user;
      const authToken = data.data?.token || data.token;
      
      if (data.success && authToken && userData) {
        const formattedUser: User = {
          id: userData.id,
          email: userData.email,
          name: userData.name,
          phone: userData.phone,
          avatar_url: userData.avatar_url,
          account_type: userData.account_type,
          email_verified: userData.email_verified,
          email_verified_at: userData.email_verified_at,
          created_at: userData.created_at,
        };
        
        const issuedAt = Date.now();
        setToken(authToken);
        setUser(formattedUser);
        localStorage.setItem(TOKEN_KEY, authToken);
        localStorage.setItem(USER_KEY, JSON.stringify(formattedUser));
        localStorage.setItem(TOKEN_ISSUED_KEY, issuedAt.toString());
        scheduleTokenRefresh(issuedAt);
        
        return { success: true };
      }
      
      return { success: false, error: data.message || "Login failed" };
    } catch (error) {
      console.error("Login error:", error);
      return { success: false, error: "Network error. Please check your connection." };
    }
  };

  const register = async (data: { name: string; email: string; password: string; accountType: string; recaptchaToken?: string }): Promise<{ success: boolean; error?: string }> => {
    try {
      const response = await fetch(`${API_URL}/auth/register`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: data.name,
          email: data.email,
          password: data.password,
          password_confirmation: data.password,
          account_type: data.accountType,
          recaptcha_token: data.recaptchaToken,
        }),
      });

      const responseData = await response.json();

      if (response.status === 422 && responseData.errors) {
        const errorMessages = Object.values(responseData.errors).flat().join('. ');
        return { success: false, error: errorMessages };
      }

      if (!response.ok) {
        return { success: false, error: responseData.message || "Registration failed" };
      }

      // Registration successful - token and user are at top level, not nested in data
      if (responseData.success && responseData.token) {
        const userData: User = {
          id: responseData.user?.id || "user",
          name: responseData.user?.name || data.name,
          email: responseData.user?.email || data.email,
          email_verified: responseData.user?.email_verified || false,
        };
        
        const issuedAt = Date.now();
        setToken(responseData.token);
        setUser(userData);
        localStorage.setItem(TOKEN_KEY, responseData.token);
        localStorage.setItem(USER_KEY, JSON.stringify(userData));
        localStorage.setItem(TOKEN_ISSUED_KEY, issuedAt.toString());
        scheduleTokenRefresh(issuedAt);
        
        return { success: true };
      }
      
      // If success is true but no token, still consider it successful (shouldn't happen but handle gracefully)
      if (responseData.success) {
        return { success: true };
      }
      
      return { success: false, error: responseData.message || "Registration failed" };
    } catch (error) {
      console.error("Register error:", error);
      return { success: false, error: "Network error. Please check your connection." };
    }
  };

  const setAuthFromGoogle = (userData: User, authToken: string) => {
    const issuedAt = Date.now();
    setToken(authToken);
    setUser(userData);
    localStorage.setItem(TOKEN_KEY, authToken);
    localStorage.setItem(USER_KEY, JSON.stringify(userData));
    localStorage.setItem(TOKEN_ISSUED_KEY, issuedAt.toString());
    scheduleTokenRefresh(issuedAt);
  };

  const verifyEmail = async (verificationToken: string): Promise<{ success: boolean; error?: string }> => {
    try {
      const response = await fetch(`${API_URL}/auth/verify-email`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token: verificationToken }),
      });

      const data = await response.json();

      if (data.success) {
        if (user) {
          const updatedUser = { ...user, email_verified: true };
          setUser(updatedUser);
          localStorage.setItem(USER_KEY, JSON.stringify(updatedUser));
        }
        return { success: true };
      }
      return { success: false, error: data.message || "Verification failed" };
    } catch (error) {
      console.error("Verify email error:", error);
      return { success: false, error: "Network error. Please try again." };
    }
  };

  const resendVerification = async (): Promise<{ success: boolean; error?: string }> => {
    try {
      const currentToken = token || localStorage.getItem(TOKEN_KEY);
      const response = await fetch(`${API_URL}/auth/resend-verification`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          ...(currentToken ? { Authorization: `Bearer ${currentToken}` } : {}),
        },
      });

      const data = await response.json();

      if (data.success) {
        return { success: true };
      }
      return { success: false, error: data.message || "Failed to resend verification email" };
    } catch (error) {
      console.error("Resend verification error:", error);
      return { success: false, error: "Network error. Please try again." };
    }
  };

  const forgotPassword = async (email: string, recaptchaToken?: string): Promise<{ success: boolean; error?: string }> => {
    try {
      const response = await fetch(`${API_URL}/auth/forgot-password`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, recaptcha_token: recaptchaToken }),
      });

      const data = await response.json();

      if (data.success) {
        return { success: true };
      }
      return { success: false, error: data.message || "Failed to send reset code" };
    } catch (error) {
      console.error("Forgot password error:", error);
      return { success: false, error: "Network error. Please try again." };
    }
  };

  const resetPassword = async (email: string, otp: string, password: string, recaptchaToken?: string): Promise<{ success: boolean; error?: string }> => {
    try {
      const response = await fetch(`${API_URL}/auth/reset-password`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ 
          email, 
          otp, 
          password, 
          password_confirmation: password,
          recaptcha_token: recaptchaToken 
        }),
      });

      const data = await response.json();

      if (data.success) {
        return { success: true };
      }
      return { success: false, error: data.message || "Failed to reset password" };
    } catch (error) {
      console.error("Reset password error:", error);
      return { success: false, error: "Network error. Please try again." };
    }
  };

  const logout = async (): Promise<void> => {
    try {
      const currentToken = token || localStorage.getItem(TOKEN_KEY);
      if (currentToken) {
        await fetch(`${API_URL}/auth/logout`, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${currentToken}`,
          },
        });
      }
    } catch (error) {
      console.error("Logout error:", error);
    } finally {
      handleLogout();
    }
  };

  const updateUser = async (data: Partial<User> & { password?: string; current_password?: string; password_confirmation?: string }): Promise<{ success: boolean; error?: string }> => {
    try {
      const currentToken = token || localStorage.getItem(TOKEN_KEY);
      const response = await fetch(`${API_URL}/auth/user`, {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          ...(currentToken ? { Authorization: `Bearer ${currentToken}` } : {}),
        },
        body: JSON.stringify(data),
      });

      const responseData = await response.json();

      if (responseData.success && responseData.data?.user) {
        const updatedUser: User = {
          ...user!,
          ...responseData.data.user,
        };
        setUser(updatedUser);
        localStorage.setItem(USER_KEY, JSON.stringify(updatedUser));
        return { success: true };
      }
      return { success: false, error: responseData.message || "Update failed" };
    } catch (error) {
      console.error("Update user error:", error);
      return { success: false, error: "Network error. Please try again." };
    }
  };

  const isEmailVerified = !!(user?.email_verified || user?.email_verified_at);

  return (
    <AuthContext.Provider
      value={{
        user,
        token,
        isAuthenticated: !!token,
        isEmailVerified,
        isLoading,
        login,
        register,
        logout,
        verifyEmail,
        resendVerification,
        forgotPassword,
        resetPassword,
        updateUser,
        setAuthFromGoogle,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error("useAuth must be used within an AuthProvider");
  }
  return context;
}

export function ProtectedRoute({ children, requireVerified = false }: { children: ReactNode; requireVerified?: boolean }) {
  const { isAuthenticated, isEmailVerified, isLoading } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [authChecked, setAuthChecked] = useState(false);

  useEffect(() => {
    // Wait for loading to complete before making auth decisions
    if (isLoading) {
      return;
    }

    // Mark that we've done the auth check
    setAuthChecked(true);

    if (!isAuthenticated) {
      // Store the intended destination for redirect after login
      sessionStorage.setItem('redirectAfterLogin', location.pathname);
      navigate("/login", { replace: true });
    } else if (requireVerified && !isEmailVerified) {
      navigate("/verify-email-reminder", { replace: true, state: { from: location } });
    }
  }, [isAuthenticated, isEmailVerified, isLoading, navigate, requireVerified, location]);

  // Show loading state while auth is being checked
  if (isLoading || !authChecked) {
    return (
      <div className="flex h-screen items-center justify-center bg-background">
        <div className="flex flex-col items-center gap-4">
          <div className="h-10 w-10 animate-spin rounded-full border-4 border-primary border-t-transparent" />
          <p className="text-sm text-muted-foreground">Loading...</p>
        </div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return null;
  }

  if (requireVerified && !isEmailVerified) {
    return null;
  }

  return <>{children}</>;
}
