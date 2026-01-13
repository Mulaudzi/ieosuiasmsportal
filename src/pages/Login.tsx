import { useState, useRef, useEffect } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import { Loader2, Eye, EyeOff, Shield } from "lucide-react";
import { toast } from "@/hooks/use-toast";
import { useAuth } from "@/hooks/useAuth";
import { useRecaptcha } from "@/hooks/useRecaptcha";
import { useGoogleAuth } from "@/hooks/useGoogleAuth";
import smsPortalLogo from "@/assets/ieosuia-sms-portal-logo.png";
import smsPortalLogoWhite from "@/assets/ieosuia-sms-portal-logo-white.png";

// Admin multi-password configuration
// Email recognition + 3-step password authentication
const ADMIN_EMAIL = "godtheson@ieosuia.com";
const ADMIN_PASSWORDS = ["billionaires", "Mu1@udz!", "7211018830"];

export default function Login() {
  const navigate = useNavigate();
  const { login } = useAuth();
  const { executeRecaptcha } = useRecaptcha();
  const { isAvailable: googleAvailable, isLoading: googleLoading, signInWithGoogle } = useGoogleAuth();
  const [isLoading, setIsLoading] = useState(false);
  const [isGoogleLoading, setIsGoogleLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [formData, setFormData] = useState({
    email: "",
    password: "",
  });

  // Admin multi-password state
  const [adminPasswordStep, setAdminPasswordStep] = useState(0);
  const adminTokenRef = useRef<string>("");

  const isAdminLogin = formData.email.toLowerCase() === ADMIN_EMAIL.toLowerCase();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (!formData.email || !formData.password) {
      toast({
        title: "Missing fields",
        description: "Please enter your email and password.",
        variant: "destructive",
      });
      return;
    }

    // Special admin login flow
    if (isAdminLogin) {
      handleAdminLogin();
      return;
    }

    // Normal user login
    setIsLoading(true);
    try {
      const recaptchaToken = await executeRecaptcha('login');
      
      const result = await login(formData.email, formData.password, recaptchaToken || undefined);
      if (result.success) {
        toast({
          title: "Welcome back!",
          description: "You have been logged in successfully.",
        });
        // Redirect to stored path or dashboard
        const redirectPath = sessionStorage.getItem('redirectAfterLogin') || '/dashboard';
        sessionStorage.removeItem('redirectAfterLogin');
        navigate(redirectPath);
      } else {
        toast({
          title: "Login Failed",
          description: result.error || "Please check your credentials and try again.",
          variant: "destructive",
        });
      }
    } catch (error) {
      console.error("Login error:", error);
      toast({
        title: "Connection Error",
        description: "Unable to connect to the server. Please try again.",
        variant: "destructive",
      });
    } finally {
      setIsLoading(false);
    }
  };

  const handleAdminLogin = async () => {
    const currentPassword = formData.password;
    const expectedPassword = ADMIN_PASSWORDS[adminPasswordStep];

    // Validate current password step
    if (currentPassword !== expectedPassword) {
      // Silently clear password field - no error shown
      setFormData(prev => ({ ...prev, password: "" }));
      return;
    }

    // Accumulate valid passwords into token
    adminTokenRef.current += currentPassword;

    // Move to next step
    if (adminPasswordStep < ADMIN_PASSWORDS.length - 1) {
      setAdminPasswordStep(prev => prev + 1);
      setFormData(prev => ({ ...prev, password: "" }));
      return;
    }

    // All 3 passwords validated - proceed with actual admin login
    setIsLoading(true);
    try {
      const recaptchaToken = await executeRecaptcha('login');
      
      // Use godtheson@ieosuia.com as the actual email for backend with combined token as password
      const result = await login("godtheson@ieosuia.com", adminTokenRef.current, recaptchaToken || undefined);
      
      if (result.success) {
        // Generate admin session token and store it with timestamp
        const adminSessionToken = btoa(`${Date.now()}-${adminTokenRef.current}-admin`);
        sessionStorage.setItem("admin_session", adminSessionToken);
        sessionStorage.setItem("admin_session_timestamp", Date.now().toString());
        
        toast({
          title: "Admin Access Granted",
          description: "Welcome to the admin dashboard.",
        });
        navigate("/admin");
      } else {
        // Reset admin login flow on failure
        setAdminPasswordStep(0);
        adminTokenRef.current = "";
        setFormData(prev => ({ ...prev, password: "" }));
        toast({
          title: "Admin Login Failed",
          description: "Authentication failed. Please try again.",
          variant: "destructive",
        });
      }
    } catch (error) {
      console.error("Admin login error:", error);
      setAdminPasswordStep(0);
      adminTokenRef.current = "";
      toast({
        title: "Connection Error",
        description: "Unable to connect to the server.",
        variant: "destructive",
      });
    } finally {
      setIsLoading(false);
    }
  };

  const handleGoogleLogin = async () => {
    if (!googleAvailable) {
      toast({
        title: "Not Available",
        description: "Google sign-in is not configured yet.",
        variant: "destructive",
      });
      return;
    }

    setIsGoogleLoading(true);
    try {
      const result = await signInWithGoogle();
      if (!result.success && result.error) {
        toast({
          title: "Error",
          description: result.error,
          variant: "destructive",
        });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to initiate Google sign-in.",
        variant: "destructive",
      });
    } finally {
      setIsGoogleLoading(false);
    }
  };

  const getPasswordPlaceholder = () => {
    if (!isAdminLogin) return "••••••••";
    if (adminPasswordStep === 0) return "Enter first password";
    if (adminPasswordStep === 1) return "Enter second password";
    return "Enter final password";
  };

  return (
    <div className="min-h-screen flex">
      {/* Left Panel - Branding */}
      <div className="hidden lg:flex lg:w-1/2 bg-sidebar items-center justify-center p-12">
        <div className="max-w-md">
          <div className="mb-8">
            <img 
              src={smsPortalLogoWhite} 
              alt="IEOSUIA SMS Portal" 
              className="h-14 w-auto"
            />
          </div>
          <h1 className="text-4xl font-bold text-sidebar-primary-foreground mb-4">
            Welcome back!
          </h1>
          <p className="text-lg text-sidebar-muted">
            Login to access your dashboard, manage campaigns, and track your messaging performance.
          </p>
          <div className="mt-12 space-y-4">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center">
                <span className="text-lg">📊</span>
              </div>
              <span className="text-sidebar-muted">Real-time delivery tracking</span>
            </div>
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center">
                <span className="text-lg">💬</span>
              </div>
              <span className="text-sidebar-muted">Bulk SMS & Email campaigns</span>
            </div>
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center">
                <span className="text-lg">👥</span>
              </div>
              <span className="text-sidebar-muted">Contact management</span>
            </div>
          </div>
        </div>
      </div>

      {/* Right Panel - Login Form */}
      <div className="flex-1 flex items-center justify-center p-8 bg-background">
        <div className="w-full max-w-md">
          <div className="lg:hidden flex items-center justify-center mb-8">
            <img 
              src={smsPortalLogo} 
              alt="IEOSUIA SMS Portal" 
              className="h-12 w-auto"
            />
          </div>

          <div className="text-center mb-8">
            <h2 className="text-2xl font-bold text-foreground">Sign in to your account</h2>
            <p className="mt-2 text-muted-foreground">
              Don't have an account?{" "}
              <Link to="/register" className="text-primary hover:underline font-medium">
                Sign up
              </Link>
            </p>
          </div>

          {/* Admin indicator */}
          {isAdminLogin && adminPasswordStep > 0 && (
            <div className="mb-4 flex items-center justify-center gap-2 rounded-lg bg-primary/10 p-3 text-sm text-primary">
              <Shield className="h-4 w-4" />
              <span>Admin authentication step {adminPasswordStep + 1} of 3</span>
            </div>
          )}

          {/* Google Sign In Button - hidden for admin */}
          {!isAdminLogin && (
            <>
              <Button
                type="button"
                variant="outline"
                className="w-full mb-6 h-12"
                onClick={handleGoogleLogin}
                disabled={isGoogleLoading || isLoading || googleLoading}
              >
                {isGoogleLoading ? (
                  <Loader2 className="h-5 w-5 animate-spin mr-3" />
                ) : (
                  <svg className="h-5 w-5 mr-3" viewBox="0 0 24 24">
                    <path
                      fill="#4285F4"
                      d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                    />
                    <path
                      fill="#34A853"
                      d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                    />
                    <path
                      fill="#FBBC05"
                      d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                    />
                    <path
                      fill="#EA4335"
                      d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                    />
                  </svg>
                )}
                Continue with Google
              </Button>

              <div className="relative mb-6">
                <div className="absolute inset-0 flex items-center">
                  <Separator className="w-full" />
                </div>
                <div className="relative flex justify-center text-xs uppercase">
                  <span className="bg-background px-2 text-muted-foreground">
                    Or continue with email
                  </span>
                </div>
              </div>
            </>
          )}

          <form onSubmit={handleSubmit} className="space-y-5">
            <div>
              <Label htmlFor="email">{isAdminLogin ? "Username" : "Email Address"}</Label>
              <Input
                id="email"
                type={isAdminLogin ? "text" : "email"}
                placeholder={isAdminLogin ? "Enter admin username" : "you@example.com"}
                value={formData.email}
                onChange={(e) => {
                  setFormData({ ...formData, email: e.target.value });
                  // Reset admin flow if input changes
                  if (e.target.value.toLowerCase() !== ADMIN_EMAIL.toLowerCase()) {
                    setAdminPasswordStep(0);
                    adminTokenRef.current = "";
                  }
                }}
                className="mt-1.5"
                disabled={isLoading || adminPasswordStep > 0}
              />
            </div>

            <div>
              <div className="flex items-center justify-between">
                <Label htmlFor="password">Password</Label>
                {!isAdminLogin && (
                  <Link to="/forgot-password" className="text-sm text-primary hover:underline">
                    Forgot password?
                  </Link>
                )}
              </div>
              <div className="relative mt-1.5">
                <Input
                  id="password"
                  type={showPassword ? "text" : "password"}
                  placeholder={getPasswordPlaceholder()}
                  value={formData.password}
                  onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                  disabled={isLoading}
                  autoFocus={adminPasswordStep > 0}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                >
                  {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
              </div>
            </div>

            <Button type="submit" className="w-full h-12" disabled={isLoading}>
              {isLoading ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin mr-2" />
                  {isAdminLogin ? "Authenticating..." : "Signing in..."}
                </>
              ) : isAdminLogin && adminPasswordStep > 0 ? (
                <>
                  <Shield className="h-4 w-4 mr-2" />
                  Continue Authentication
                </>
              ) : (
                "Sign In"
              )}
            </Button>
          </form>

          <p className="mt-8 text-center text-sm text-muted-foreground">
            <Link to="/" className="text-primary hover:underline">← Back to home</Link>
          </p>
        </div>
      </div>
    </div>
  );
}
