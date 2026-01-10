import { createContext, useContext, useState, useEffect, useRef, ReactNode } from "react";
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
  login: (email: string, password: string, recaptchaToken?: string) => Promise<{ success: boolean; error?: string }>;
  register: (data: { name: string; email: string; password: string; accountType: string; recaptchaToken?: string }) => Promise<{ success: boolean; error?: string }>;
  logout: () => Promise<void>;
  verifyEmail: (token: string) => Promise<{ success: boolean; error?: string }>;
  resendVerification: () => Promise<{ success: boolean; error?: string }>;
  forgotPassword: (email: string) => Promise<{ success: boolean; error?: string }>;
  resetPassword: (email: string, otp: string, password: string) => Promise<{ success: boolean; error?: string }>;
  updateUser: (data: Partial<User> & { password?: string; current_password?: string; password_confirmation?: string }) => Promise<{ success: boolean; error?: string }>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

const TOKEN_KEY = "auth_token";
const USER_KEY = "auth_user";
const TOKEN_ISSUED_KEY = "auth_token_issued";
const API_URL = import.meta.env.VITE_API_URL || "https://sms.ieosuia.com/api";

// Token expires in 24 hours, refresh when less than 2 hours remaining
const TOKEN_LIFETIME_MS = 24 * 60 * 60 * 1000;
const TOKEN_REFRESH_THRESHOLD_MS = 2 * 60 * 60 * 1000;

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const refreshTimeoutRef = useRef<NodeJS.Timeout | null>(null);

  // Calculate when to refresh the token
  const scheduleTokenRefresh = (tokenIssuedAt: number) => {
    if (refreshTimeoutRef.current) {
      clearTimeout(refreshTimeoutRef.current);
    }

    const now = Date.now();
    const expiresAt = tokenIssuedAt + TOKEN_LIFETIME_MS;
    const refreshAt = expiresAt - TOKEN_REFRESH_THRESHOLD_MS;
    const timeUntilRefresh = refreshAt - now;

    if (timeUntilRefresh <= 0) {
      // Token needs immediate refresh
      refreshToken();
    } else {
      // Schedule refresh
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
          
          // Schedule next refresh
          scheduleTokenRefresh(issuedAt);
        }
      } else if (response.status === 401) {
        // Token is invalid, logout
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

  // Initialize auth state from localStorage
  useEffect(() => {
    const storedToken = localStorage.getItem(TOKEN_KEY);
    const storedUser = localStorage.getItem(USER_KEY);
    const storedIssuedAt = localStorage.getItem(TOKEN_ISSUED_KEY);
    
    if (storedToken && storedUser) {
      setToken(storedToken);
      setUser(JSON.parse(storedUser));
      
      // Check if token is still valid and schedule refresh
      const issuedAt = storedIssuedAt ? parseInt(storedIssuedAt, 10) : Date.now();
      const expiresAt = issuedAt + TOKEN_LIFETIME_MS;
      
      if (Date.now() < expiresAt) {
        scheduleTokenRefresh(issuedAt);
      } else {
        // Token expired, clear auth state
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

  const apiRequest = async (endpoint: string, options: RequestInit = {}) => {
    const currentToken = token || localStorage.getItem(TOKEN_KEY);
    const headers: HeadersInit = {
      "Content-Type": "application/json",
      ...(currentToken ? { Authorization: `Bearer ${currentToken}` } : {}),
      ...options.headers,
    };

    const response = await fetch(`${API_URL}${endpoint}`, {
      ...options,
      headers,
    });

    return response.json();
  };

  const login = async (email: string, password: string, recaptchaToken?: string): Promise<{ success: boolean; error?: string }> => {
    try {
      const response = await apiRequest("/auth/login", {
        method: "POST",
        body: JSON.stringify({ email, password, recaptcha_token: recaptchaToken }),
      });

      if (response.success && response.data) {
        const userData: User = {
          id: response.data.user?.id || "user",
          email,
          name: response.data.user?.name,
          phone: response.data.user?.phone,
          avatar_url: response.data.user?.avatar_url,
          account_type: response.data.user?.account_type,
          email_verified: response.data.user?.email_verified,
          email_verified_at: response.data.user?.email_verified_at,
          created_at: response.data.user?.created_at,
        };
        const issuedAt = Date.now();
        
        setToken(response.data.token);
        setUser(userData);
        localStorage.setItem(TOKEN_KEY, response.data.token);
        localStorage.setItem(USER_KEY, JSON.stringify(userData));
        localStorage.setItem(TOKEN_ISSUED_KEY, issuedAt.toString());
        
        // Schedule token refresh
        scheduleTokenRefresh(issuedAt);
        
        return { success: true };
      }
      return { success: false, error: response.message || "Login failed" };
    } catch (error) {
      console.error("Login error:", error);
      return { success: false, error: "Network error. Please try again." };
    }
  };

  const register = async (data: { name: string; email: string; password: string; accountType: string; recaptchaToken?: string }): Promise<{ success: boolean; error?: string }> => {
    try {
      const response = await apiRequest("/auth/register", {
        method: "POST",
        body: JSON.stringify({
          name: data.name,
          email: data.email,
          password: data.password,
          password_confirmation: data.password,
          account_type: data.accountType,
          recaptcha_token: data.recaptchaToken,
        }),
      });

      if (response.success && response.data) {
        const userData: User = {
          id: response.data.user?.id || "user",
          name: data.name,
          email: data.email,
          email_verified: false,
        };
        const issuedAt = Date.now();
        
        setToken(response.data.token);
        setUser(userData);
        localStorage.setItem(TOKEN_KEY, response.data.token);
        localStorage.setItem(USER_KEY, JSON.stringify(userData));
        localStorage.setItem(TOKEN_ISSUED_KEY, issuedAt.toString());
        
        // Schedule token refresh
        scheduleTokenRefresh(issuedAt);
        
        return { success: true };
      }
      
      // Handle validation errors (422)
      if (response.errors) {
        const errorMessages = Object.values(response.errors).flat().join('. ');
        return { success: false, error: errorMessages || response.message || "Registration failed" };
      }
      
      return { success: false, error: response.message || "Registration failed" };
    } catch (error) {
      console.error("Register error:", error);
      return { success: false, error: "Network error. Please try again." };
    }
  };

  const verifyEmail = async (verificationToken: string): Promise<{ success: boolean; error?: string }> => {
    try {
      const response = await apiRequest("/auth/verify-email", {
        method: "POST",
        body: JSON.stringify({ token: verificationToken }),
      });

      if (response.success) {
        // Update user state to reflect verified email
        if (user) {
          const updatedUser = { ...user, email_verified: true };
          setUser(updatedUser);
          localStorage.setItem(USER_KEY, JSON.stringify(updatedUser));
        }
        return { success: true };
      }
      return { success: false, error: response.message || "Verification failed" };
    } catch (error) {
      console.error("Verify email error:", error);
      return { success: false, error: "Network error. Please try again." };
    }
  };

  const resendVerification = async (): Promise<{ success: boolean; error?: string }> => {
    try {
      const response = await apiRequest("/auth/resend-verification", {
        method: "POST",
      });

      if (response.success) {
        return { success: true };
      }
      return { success: false, error: response.message || "Failed to resend verification email" };
    } catch (error) {
      console.error("Resend verification error:", error);
      return { success: false, error: "Network error. Please try again." };
    }
  };

  const forgotPassword = async (email: string): Promise<{ success: boolean; error?: string }> => {
    try {
      const response = await apiRequest("/auth/forgot-password", {
        method: "POST",
        body: JSON.stringify({ email }),
      });

      if (response.success) {
        return { success: true };
      }
      return { success: false, error: response.message || "Failed to send reset code" };
    } catch (error) {
      console.error("Forgot password error:", error);
      return { success: false, error: "Network error. Please try again." };
    }
  };

  const resetPassword = async (email: string, otp: string, password: string): Promise<{ success: boolean; error?: string }> => {
    try {
      const response = await apiRequest("/auth/reset-password", {
        method: "POST",
        body: JSON.stringify({ email, otp, password, password_confirmation: password }),
      });

      if (response.success) {
        return { success: true };
      }
      return { success: false, error: response.message || "Failed to reset password" };
    } catch (error) {
      console.error("Reset password error:", error);
      return { success: false, error: "Network error. Please try again." };
    }
  };

  const logout = async (): Promise<void> => {
    try {
      await apiRequest("/auth/logout", { method: "POST" });
    } catch (error) {
      console.error("Logout error:", error);
    } finally {
      handleLogout();
    }
  };

  const updateUser = async (data: Partial<User> & { password?: string; current_password?: string; password_confirmation?: string }): Promise<{ success: boolean; error?: string }> => {
    try {
      const response = await apiRequest("/auth/user", {
        method: "PUT",
        body: JSON.stringify(data),
      });

      if (response.success && response.data?.user) {
        const updatedUser: User = {
          ...user!,
          ...response.data.user,
        };
        setUser(updatedUser);
        localStorage.setItem(USER_KEY, JSON.stringify(updatedUser));
        return { success: true };
      }
      return { success: false, error: response.message || "Update failed" };
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

// Protected Route wrapper component
export function ProtectedRoute({ children, requireVerified = false }: { children: ReactNode; requireVerified?: boolean }) {
  const { isAuthenticated, isEmailVerified, isLoading } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      navigate("/login", { replace: true });
    } else if (!isLoading && isAuthenticated && requireVerified && !isEmailVerified) {
      navigate("/verify-email-reminder", { replace: true, state: { from: location } });
    }
  }, [isAuthenticated, isEmailVerified, isLoading, navigate, requireVerified, location]);

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-primary border-t-transparent" />
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
