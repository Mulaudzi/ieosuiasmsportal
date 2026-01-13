import { useState, useEffect } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import { Loader2, Eye, EyeOff, Shield, ArrowLeft, ArrowRight } from "lucide-react";
import { toast } from "@/hooks/use-toast";
import { useAuth } from "@/hooks/useAuth";
import { useRecaptcha } from "@/hooks/useRecaptcha";
import { useGoogleAuth } from "@/hooks/useGoogleAuth";
import smsPortalLogo from "@/assets/ieosuia-sms-portal-logo.png";
import smsPortalLogoWhite from "@/assets/ieosuia-sms-portal-logo-white.png";

export default function Login() {
  const navigate = useNavigate();
  const { login, user } = useAuth();
  const { executeRecaptcha } = useRecaptcha();
  const { isAvailable: googleAvailable, isLoading: googleLoading, signInWithGoogle } = useGoogleAuth();
  const [isLoading, setIsLoading] = useState(false);
  const [isGoogleLoading, setIsGoogleLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [pendingRedirect, setPendingRedirect] = useState(false);
  
  // Admin multi-password flow
  const [adminStep, setAdminStep] = useState(0); // 0 = normal login, 1-3 = admin password steps
  const [adminPasswords, setAdminPasswords] = useState({
    password_1: "",
    password_2: "",
    password_3: "",
  });
  const [adminEmail, setAdminEmail] = useState("");
  
  const [formData, setFormData] = useState({
    email: "",
    password: "",
  });

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

    setIsLoading(true);
    try {
      const recaptchaToken = await executeRecaptcha('login');
      
      const result = await login(formData.email, formData.password, recaptchaToken || undefined);
      
      // Check if admin auth is required (3 passwords)
      if (result.requires_admin_auth) {
        setAdminEmail(formData.email);
        setAdminPasswords(prev => ({ ...prev, password_1: formData.password }));
        setAdminStep(2); // Move to password 2
        setFormData({ ...formData, password: "" });
        toast({
          title: "Admin Authentication Required",
          description: "Please enter your second password.",
        });
        return;
      }
      
      if (result.success) {
        setPendingRedirect(true);
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

  const handleAdminPasswordSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (adminStep === 2) {
      if (!formData.password) {
        toast({
          title: "Missing field",
          description: "Please enter your second password.",
          variant: "destructive",
        });
        return;
      }
      setAdminPasswords(prev => ({ ...prev, password_2: formData.password }));
      setAdminStep(3);
      setFormData({ ...formData, password: "" });
      toast({
        title: "Password 2 Accepted",
        description: "Please enter your third password.",
      });
      return;
    }
    
    if (adminStep === 3) {
      if (!formData.password) {
        toast({
          title: "Missing field",
          description: "Please enter your third password.",
          variant: "destructive",
        });
        return;
      }
      
      setIsLoading(true);
      try {
        const recaptchaToken = await executeRecaptcha('login');
        const finalPasswords = {
          ...adminPasswords,
          password_3: formData.password,
        };
        
        const result = await login(
          adminEmail, 
          finalPasswords.password_1, 
          recaptchaToken || undefined,
          finalPasswords.password_2,
          finalPasswords.password_3
        );
        
        if (result.success) {
          setPendingRedirect(true);
        } else {
          // Reset admin flow on failure
          setAdminStep(0);
          setAdminPasswords({ password_1: "", password_2: "", password_3: "" });
          setAdminEmail("");
          setFormData({ email: adminEmail, password: "" });
          toast({
            title: "Login Failed",
            description: result.error || "Invalid credentials. Please try again.",
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
    }
  };

  const handleBackToStep = (step: number) => {
    if (step === 0) {
      setAdminStep(0);
      setAdminPasswords({ password_1: "", password_2: "", password_3: "" });
      setAdminEmail("");
      setFormData({ email: "", password: "" });
    } else {
      setAdminStep(step);
      setFormData({ ...formData, password: "" });
    }
  };

  // Handle redirect after login when user state is updated
  useEffect(() => {
    if (pendingRedirect && user) {
      const isAdmin = user.account_type === 'admin';
      
      if (isAdmin) {
        sessionStorage.setItem("admin_session", btoa(`${Date.now()}-admin`));
        sessionStorage.setItem("admin_session_timestamp", Date.now().toString());
        
        toast({
          title: "Admin Access Granted",
          description: "Welcome to the admin dashboard.",
        });
        navigate("/admin");
      } else {
        toast({
          title: "Welcome back!",
          description: "You have been logged in successfully.",
        });
        const redirectPath = sessionStorage.getItem('redirectAfterLogin') || '/dashboard';
        sessionStorage.removeItem('redirectAfterLogin');
        navigate(redirectPath);
      }
      setPendingRedirect(false);
    }
  }, [pendingRedirect, user, navigate]);

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

  // Admin multi-password form
  if (adminStep > 0) {
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
              Admin Authentication
            </h1>
            <p className="text-lg text-sidebar-muted">
              Enhanced security requires 3 passwords for admin access. Enter each password to proceed.
            </p>
            <div className="mt-12 space-y-4">
              <div className="flex items-center gap-3">
                <div className={`h-10 w-10 rounded-full flex items-center justify-center ${adminStep >= 1 ? 'bg-primary' : 'bg-primary/20'}`}>
                  <span className="text-sidebar-primary-foreground font-bold">1</span>
                </div>
                <span className={`${adminStep >= 1 ? 'text-sidebar-primary-foreground' : 'text-sidebar-muted'}`}>
                  Password 1 {adminStep > 1 && '✓'}
                </span>
              </div>
              <div className="flex items-center gap-3">
                <div className={`h-10 w-10 rounded-full flex items-center justify-center ${adminStep >= 2 ? 'bg-primary' : 'bg-primary/20'}`}>
                  <span className="text-sidebar-primary-foreground font-bold">2</span>
                </div>
                <span className={`${adminStep >= 2 ? 'text-sidebar-primary-foreground' : 'text-sidebar-muted'}`}>
                  Password 2 {adminStep > 2 && '✓'}
                </span>
              </div>
              <div className="flex items-center gap-3">
                <div className={`h-10 w-10 rounded-full flex items-center justify-center ${adminStep >= 3 ? 'bg-primary' : 'bg-primary/20'}`}>
                  <span className="text-sidebar-primary-foreground font-bold">3</span>
                </div>
                <span className={`${adminStep >= 3 ? 'text-sidebar-primary-foreground' : 'text-sidebar-muted'}`}>
                  Password 3
                </span>
              </div>
            </div>
          </div>
        </div>

        {/* Right Panel - Admin Password Form */}
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
              <div className="mx-auto w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center mb-4">
                <Shield className="h-6 w-6 text-primary" />
              </div>
              <h2 className="text-2xl font-bold text-foreground">
                Enter Password {adminStep}
              </h2>
              <p className="mt-2 text-muted-foreground">
                Step {adminStep} of 3 - Admin authentication for {adminEmail}
              </p>
            </div>

            {/* Step indicators for mobile */}
            <div className="lg:hidden flex justify-center gap-2 mb-6">
              {[1, 2, 3].map((step) => (
                <div
                  key={step}
                  className={`h-2 w-12 rounded-full ${
                    step < adminStep ? 'bg-primary' : 
                    step === adminStep ? 'bg-primary' : 'bg-muted'
                  }`}
                />
              ))}
            </div>

            <form onSubmit={handleAdminPasswordSubmit} className="space-y-5">
              <div>
                <Label htmlFor="admin-password">Password {adminStep}</Label>
                <div className="relative mt-1.5">
                  <Input
                    id="admin-password"
                    type={showPassword ? "text" : "password"}
                    placeholder="••••••••"
                    value={formData.password}
                    onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                    disabled={isLoading}
                    autoFocus
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

              <div className="flex gap-3">
                <Button 
                  type="button" 
                  variant="outline" 
                  className="flex-1"
                  onClick={() => handleBackToStep(adminStep - 1)}
                  disabled={isLoading}
                >
                  <ArrowLeft className="h-4 w-4 mr-2" />
                  Back
                </Button>
                <Button type="submit" className="flex-1" disabled={isLoading}>
                  {isLoading ? (
                    <>
                      <Loader2 className="h-4 w-4 animate-spin mr-2" />
                      Verifying...
                    </>
                  ) : adminStep === 3 ? (
                    "Sign In"
                  ) : (
                    <>
                      Next
                      <ArrowRight className="h-4 w-4 ml-2" />
                    </>
                  )}
                </Button>
              </div>
            </form>

            <p className="mt-8 text-center text-sm text-muted-foreground">
              <button 
                onClick={() => handleBackToStep(0)} 
                className="text-primary hover:underline"
              >
                ← Cancel admin login
              </button>
            </p>
          </div>
        </div>
      </div>
    );
  }

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

          {/* Google Sign In Button */}
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

          <form onSubmit={handleSubmit} className="space-y-5">
            <div>
              <Label htmlFor="email">Email Address</Label>
              <Input
                id="email"
                type="email"
                placeholder="you@example.com"
                value={formData.email}
                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                className="mt-1.5"
                disabled={isLoading}
              />
            </div>

            <div>
              <div className="flex items-center justify-between">
                <Label htmlFor="password">Password</Label>
                <Link to="/forgot-password" className="text-sm text-primary hover:underline">
                  Forgot password?
                </Link>
              </div>
              <div className="relative mt-1.5">
                <Input
                  id="password"
                  type={showPassword ? "text" : "password"}
                  placeholder="••••••••"
                  value={formData.password}
                  onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                  disabled={isLoading}
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
                  Signing in...
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
