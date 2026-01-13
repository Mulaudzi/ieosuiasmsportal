import { useState, useEffect } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Loader2, Eye, EyeOff, Shield, AlertTriangle } from "lucide-react";
import { toast } from "@/hooks/use-toast";
import { useAuth } from "@/hooks/useAuth";
import { api } from "@/lib/api";
import smsPortalLogo from "@/assets/ieosuia-sms-portal-logo.png";
import smsPortalLogoWhite from "@/assets/ieosuia-sms-portal-logo-white.png";

interface AdminCheckResponse {
  is_admin: boolean;
  remaining_attempts?: number;
  locked_until?: string;
}

export default function AdminLogin() {
  const navigate = useNavigate();
  const { login, user } = useAuth();
  const [isLoading, setIsLoading] = useState(false);
  const [isCheckingEmail, setIsCheckingEmail] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [pendingRedirect, setPendingRedirect] = useState(false);
  
  // Admin validation state
  const [isValidAdmin, setIsValidAdmin] = useState<boolean | null>(null);
  const [remainingAttempts, setRemainingAttempts] = useState<number | null>(null);
  const [lockedUntil, setLockedUntil] = useState<string | null>(null);
  
  const [formData, setFormData] = useState({
    email: "",
    password_1: "",
    password_2: "",
    password_3: "",
  });

  // Check if already logged in as admin
  useEffect(() => {
    const adminSession = sessionStorage.getItem("admin_session");
    if (adminSession && user) {
      navigate("/admin", { replace: true });
    }
  }, [user, navigate]);

  // Verify email is admin on blur
  const checkAdminEmail = async () => {
    if (!formData.email || !formData.email.includes('@')) {
      setIsValidAdmin(null);
      return;
    }
    
    setIsCheckingEmail(true);
    try {
      const response = await api.post<AdminCheckResponse>("/admin/check-email", { email: formData.email });
      const data = response.data;
      
      if (data?.is_admin === true) {
        setIsValidAdmin(true);
        if (data.remaining_attempts !== undefined) {
          setRemainingAttempts(data.remaining_attempts);
        }
        if (data.locked_until) {
          setLockedUntil(data.locked_until);
        }
      } else {
        setIsValidAdmin(false);
      }
    } catch {
      setIsValidAdmin(false);
    } finally {
      setIsCheckingEmail(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (!formData.email || !formData.password_1 || !formData.password_2 || !formData.password_3) {
      toast({
        title: "Missing fields",
        description: "All 3 passwords are required for admin login.",
        variant: "destructive",
      });
      return;
    }

    if (isValidAdmin === false) {
      toast({
        title: "Invalid Email",
        description: "This email is not registered as an admin.",
        variant: "destructive",
      });
      return;
    }

    setIsLoading(true);
    try {
      const result = await login(
        formData.email, 
        formData.password_1, 
        undefined,
        formData.password_2,
        formData.password_3
      );
      
      if (result.success) {
        setPendingRedirect(true);
      } else {
        // Clear passwords on failure
        setFormData(prev => ({ ...prev, password_1: "", password_2: "", password_3: "" }));
        
        // Update remaining attempts if returned
        if (result.remaining_attempts !== undefined) {
          setRemainingAttempts(result.remaining_attempts);
        }
        
        toast({
          title: "Authentication Failed",
          description: "Invalid credentials. Please verify all passwords and try again.",
          variant: "destructive",
        });
      }
    } catch (error) {
      console.error("Admin login error:", error);
      toast({
        title: "Connection Error",
        description: "Unable to connect to the server. Please try again.",
        variant: "destructive",
      });
    } finally {
      setIsLoading(false);
    }
  };

  // Handle redirect after successful login
  useEffect(() => {
    if (pendingRedirect && user) {
      if (user.account_type === 'admin') {
        sessionStorage.setItem("admin_session", btoa(`${Date.now()}-admin`));
        sessionStorage.setItem("admin_session_timestamp", Date.now().toString());
        
        toast({
          title: "Admin Access Granted",
          description: "Welcome to the admin dashboard.",
        });
        navigate("/admin", { replace: true });
      } else {
        toast({
          title: "Access Denied",
          description: "This account does not have admin privileges.",
          variant: "destructive",
        });
        navigate("/dashboard", { replace: true });
      }
      setPendingRedirect(false);
    }
  }, [pendingRedirect, user, navigate]);

  const isLocked = lockedUntil && new Date(lockedUntil) > new Date();

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
            Enhanced security requires 3 passwords for admin access. Enter all passwords to proceed.
          </p>
          <div className="mt-12 space-y-4">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-full bg-primary flex items-center justify-center">
                <Shield className="h-5 w-5 text-sidebar-primary-foreground" />
              </div>
              <span className="text-sidebar-muted">3-Password Security</span>
            </div>
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center">
                <span className="text-lg">🔒</span>
              </div>
              <span className="text-sidebar-muted">Rate-limited authentication</span>
            </div>
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center">
                <span className="text-lg">📋</span>
              </div>
              <span className="text-sidebar-muted">Audit logged access</span>
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
            <div className="mx-auto w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center mb-4">
              <Shield className="h-6 w-6 text-primary" />
            </div>
            <h2 className="text-2xl font-bold text-foreground">Admin Sign In</h2>
            <p className="mt-2 text-muted-foreground">
              Enter all 3 passwords to authenticate
            </p>
          </div>

          {/* Rate limiting warning */}
          {remainingAttempts !== null && remainingAttempts <= 3 && (
            <div className="mb-6 p-3 bg-warning/10 border border-warning/20 rounded-lg flex items-start gap-2">
              <AlertTriangle className="h-5 w-5 text-warning shrink-0 mt-0.5" />
              <div className="text-sm">
                <p className="font-medium text-warning">Limited Attempts Remaining</p>
                <p className="text-muted-foreground">
                  {remainingAttempts} attempt{remainingAttempts !== 1 ? 's' : ''} remaining before lockout
                </p>
              </div>
            </div>
          )}

          {/* Account locked warning */}
          {isLocked && (
            <div className="mb-6 p-3 bg-destructive/10 border border-destructive/20 rounded-lg flex items-start gap-2">
              <AlertTriangle className="h-5 w-5 text-destructive shrink-0 mt-0.5" />
              <div className="text-sm">
                <p className="font-medium text-destructive">Account Temporarily Locked</p>
                <p className="text-muted-foreground">
                  Too many failed attempts. Try again after {new Date(lockedUntil!).toLocaleTimeString()}
                </p>
              </div>
            </div>
          )}

          {/* Invalid admin email warning */}
          {isValidAdmin === false && (
            <div className="mb-6 p-3 bg-destructive/10 border border-destructive/20 rounded-lg flex items-start gap-2">
              <AlertTriangle className="h-5 w-5 text-destructive shrink-0 mt-0.5" />
              <div className="text-sm">
                <p className="font-medium text-destructive">Not an Admin Email</p>
                <p className="text-muted-foreground">
                  This email is not registered as an administrator.
                </p>
              </div>
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-5">
            <div>
              <div className="flex items-center justify-between">
                <Label htmlFor="email">Admin Email</Label>
                {isCheckingEmail && (
                  <Loader2 className="h-3 w-3 animate-spin text-muted-foreground" />
                )}
              </div>
              <Input
                id="email"
                type="email"
                placeholder="admin@example.com"
                value={formData.email}
                onChange={(e) => {
                  setFormData({ ...formData, email: e.target.value });
                  setIsValidAdmin(null);
                }}
                onBlur={checkAdminEmail}
                className="mt-1.5"
                disabled={isLoading || isLocked}
              />
            </div>

            <div>
              <Label htmlFor="password_1">Password 1</Label>
              <div className="relative mt-1.5">
                <Input
                  id="password_1"
                  type={showPassword ? "text" : "password"}
                  placeholder="••••••••"
                  value={formData.password_1}
                  onChange={(e) => setFormData({ ...formData, password_1: e.target.value })}
                  disabled={isLoading || isLocked}
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

            <div>
              <Label htmlFor="password_2">Password 2</Label>
              <div className="relative mt-1.5">
                <Input
                  id="password_2"
                  type={showPassword ? "text" : "password"}
                  placeholder="••••••••"
                  value={formData.password_2}
                  onChange={(e) => setFormData({ ...formData, password_2: e.target.value })}
                  disabled={isLoading || isLocked}
                />
              </div>
            </div>

            <div>
              <Label htmlFor="password_3">Password 3</Label>
              <div className="relative mt-1.5">
                <Input
                  id="password_3"
                  type={showPassword ? "text" : "password"}
                  placeholder="••••••••"
                  value={formData.password_3}
                  onChange={(e) => setFormData({ ...formData, password_3: e.target.value })}
                  disabled={isLoading || isLocked}
                />
              </div>
            </div>

            <Button 
              type="submit" 
              className="w-full h-12" 
              disabled={isLoading || isLocked || isValidAdmin === false}
            >
              {isLoading ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin mr-2" />
                  Authenticating...
                </>
              ) : (
                <>
                  <Shield className="h-4 w-4 mr-2" />
                  Authenticate
                </>
              )}
            </Button>
          </form>

          <p className="mt-8 text-center text-sm text-muted-foreground">
            <Link to="/login" className="text-primary hover:underline">← Back to user login</Link>
          </p>
        </div>
      </div>
    </div>
  );
}
