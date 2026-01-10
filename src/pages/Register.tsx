import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";
import { Zap, Loader2, Eye, EyeOff, Check, X, AlertCircle } from "lucide-react";
import { toast } from "@/hooks/use-toast";
import { useAuth } from "@/hooks/useAuth";
import { useRecaptcha } from "@/hooks/useRecaptcha";

export default function Register() {
  const navigate = useNavigate();
  const { register } = useAuth();
  const { executeRecaptcha, isLoaded: recaptchaLoaded } = useRecaptcha();
  const [isLoading, setIsLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [agreedToTerms, setAgreedToTerms] = useState(false);
  const [formData, setFormData] = useState({
    name: "",
    email: "",
    password: "",
    confirmPassword: "",
    accountType: "business",
    company: "",
  });

  // Common weak passwords to reject
  const commonPasswords = [
    'password', 'password123', '123456', '12345678', 'qwerty', 'abc123',
    'monkey', 'letmein', 'dragon', 'master', 'admin', 'welcome',
    'login', 'password1', 'sunshine', 'princess', 'football', 'iloveyou',
    '1234567890', 'passw0rd', 'shadow', 'superman', 'qwerty123'
  ];

  const passwordRequirements = () => {
    const password = formData.password;
    return {
      minLength: password.length >= 8,
      hasUppercase: /[A-Z]/.test(password),
      hasLowercase: /[a-z]/.test(password),
      hasNumber: /\d/.test(password),
      hasSpecial: /[^a-zA-Z0-9]/.test(password),
      notCommon: !commonPasswords.includes(password.toLowerCase()),
    };
  };

  const requirements = passwordRequirements();

  const passwordStrength = () => {
    const password = formData.password;
    if (!password) return { score: 0, label: "", isValid: false };
    
    const reqs = requirements;
    let score = 0;
    
    if (reqs.minLength) score++;
    if (reqs.hasUppercase && reqs.hasLowercase) score++;
    if (reqs.hasNumber) score++;
    if (reqs.hasSpecial) score++;
    
    // Deduct if common password
    if (!reqs.notCommon) score = Math.min(score, 1);
    
    const labels = ["", "Weak", "Fair", "Good", "Strong"];
    const colors = ["", "bg-destructive", "bg-warning", "bg-info", "bg-success"];
    
    // Password is valid if score >= 3 (Good or Strong) and not common
    const isValid = score >= 3 && reqs.notCommon && reqs.minLength;
    
    return { score, label: labels[score], color: colors[score], isValid };
  };

  const strength = passwordStrength();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!formData.name || !formData.email || !formData.password) {
      toast({
        title: "Missing fields",
        description: "Please fill in all required fields.",
        variant: "destructive",
      });
      return;
    }

    if (formData.password !== formData.confirmPassword) {
      toast({
        title: "Passwords don't match",
        description: "Please make sure your passwords match.",
        variant: "destructive",
      });
      return;
    }

    if (!strength.isValid) {
      toast({
        title: "Password too weak",
        description: "Password must be at least 8 characters with uppercase, lowercase, number, and special character.",
        variant: "destructive",
      });
      return;
    }

    if (!requirements.notCommon) {
      toast({
        title: "Common password detected",
        description: "Please choose a more unique password.",
        variant: "destructive",
      });
      return;
    }

    if (!agreedToTerms) {
      toast({
        title: "Terms required",
        description: "Please agree to the terms and conditions.",
        variant: "destructive",
      });
      return;
    }

    setIsLoading(true);
    try {
      // Execute reCAPTCHA
      const recaptchaToken = await executeRecaptcha('register');
      
      const result = await register({
        name: formData.name,
        email: formData.email,
        password: formData.password,
        accountType: formData.accountType,
        recaptchaToken: recaptchaToken || undefined,
      });
      
      if (result.success) {
        toast({
          title: "Account created!",
          description: "Please check your email to verify your account.",
        });
        navigate("/verify-email-reminder");
      } else {
        toast({
          title: "Registration failed",
          description: result.error || "Please try again.",
          variant: "destructive",
        });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Something went wrong. Please try again.",
        variant: "destructive",
      });
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex">
      {/* Left Panel - Branding */}
      <div className="hidden lg:flex lg:w-1/2 bg-sidebar items-center justify-center p-12">
        <div className="max-w-md">
          <div className="flex items-center gap-3 mb-8">
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary">
              <Zap className="h-7 w-7 text-primary-foreground" />
            </div>
            <span className="text-2xl font-bold text-sidebar-primary-foreground">IEOSUIA SMS PORTAL</span>
          </div>
          <h1 className="text-4xl font-bold text-sidebar-primary-foreground mb-4">
            Start Messaging Today
          </h1>
          <p className="text-lg text-sidebar-muted">
            Create your account and get 5 free SMS credits to start reaching your customers instantly.
          </p>
          
          <div className="mt-12 space-y-6">
            <div className="bg-sidebar-accent rounded-xl p-6">
              <h3 className="font-semibold text-sidebar-primary-foreground mb-3">What you get:</h3>
              <ul className="space-y-3">
                <li className="flex items-center gap-3 text-sidebar-muted">
                  <Check className="h-5 w-5 text-success" />
                  5 free SMS credits
                </li>
                <li className="flex items-center gap-3 text-sidebar-muted">
                  <Check className="h-5 w-5 text-success" />
                  Access to all basic features
                </li>
                <li className="flex items-center gap-3 text-sidebar-muted">
                  <Check className="h-5 w-5 text-success" />
                  Contact management tools
                </li>
                <li className="flex items-center gap-3 text-sidebar-muted">
                  <Check className="h-5 w-5 text-success" />
                  Delivery reports & analytics
                </li>
                <li className="flex items-center gap-3 text-sidebar-muted">
                  <Check className="h-5 w-5 text-success" />
                  No credit card required
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      {/* Right Panel - Registration Form */}
      <div className="flex-1 flex items-center justify-center p-8 bg-background overflow-y-auto">
        <div className="w-full max-w-md">
          <div className="lg:hidden flex items-center gap-2 mb-8 justify-center">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary">
              <Zap className="h-6 w-6 text-primary-foreground" />
            </div>
            <span className="text-xl font-bold text-foreground">IEOSUIA SMS PORTAL</span>
          </div>

          <div className="text-center mb-8">
            <h2 className="text-2xl font-bold text-foreground">Create your account</h2>
            <p className="mt-2 text-muted-foreground">
              Already have an account?{" "}
              <Link to="/login" className="text-primary hover:underline font-medium">
                Sign in
              </Link>
            </p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-5">
            <div className="grid grid-cols-2 gap-4">
              <div className="col-span-2">
                <Label htmlFor="name">Full Name *</Label>
                <Input
                  id="name"
                  placeholder="John Doe"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  className="mt-1.5"
                  disabled={isLoading}
                />
              </div>
            </div>

            <div>
              <Label htmlFor="email">Email Address *</Label>
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
              <Label htmlFor="accountType">Account Type</Label>
              <Select
                value={formData.accountType}
                onValueChange={(value) => setFormData({ ...formData, accountType: value })}
                disabled={isLoading}
              >
                <SelectTrigger className="mt-1.5">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="individual">Individual</SelectItem>
                  <SelectItem value="business">Business</SelectItem>
                  <SelectItem value="organization">Organization</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {formData.accountType !== "individual" && (
              <div>
                <Label htmlFor="company">Company / Organization Name</Label>
                <Input
                  id="company"
                  placeholder="Your company name"
                  value={formData.company}
                  onChange={(e) => setFormData({ ...formData, company: e.target.value })}
                  className="mt-1.5"
                  disabled={isLoading}
                />
              </div>
            )}

            <div>
              <Label htmlFor="password">Password *</Label>
              <div className="relative mt-1.5">
                <Input
                  id="password"
                  type={showPassword ? "text" : "password"}
                  placeholder="Min. 8 characters"
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
              {formData.password && (
                <div className="mt-3 space-y-3">
                  {/* Strength bar */}
                  <div>
                    <div className="flex gap-1">
                      {[1, 2, 3, 4].map((i) => (
                        <div
                          key={i}
                          className={`h-1.5 flex-1 rounded-full transition-all ${
                            i <= strength.score ? strength.color : "bg-muted"
                          }`}
                        />
                      ))}
                    </div>
                    <div className="flex items-center justify-between mt-1">
                      <p className={`text-xs font-medium ${
                        strength.score >= 3 ? 'text-success' : strength.score >= 2 ? 'text-warning' : 'text-destructive'
                      }`}>
                        {strength.label}
                      </p>
                      {strength.isValid && (
                        <span className="text-xs text-success flex items-center gap-1">
                          <Check className="h-3 w-3" /> Ready
                        </span>
                      )}
                    </div>
                  </div>
                  
                  {/* Requirements checklist */}
                  <div className="rounded-lg border border-border bg-muted/30 p-3">
                    <p className="text-xs font-medium text-foreground mb-2 flex items-center gap-1.5">
                      <AlertCircle className="h-3.5 w-3.5" />
                      Password requirements:
                    </p>
                    <ul className="space-y-1.5">
                      <li className={`text-xs flex items-center gap-2 ${requirements.minLength ? 'text-success' : 'text-muted-foreground'}`}>
                        {requirements.minLength ? <Check className="h-3 w-3" /> : <X className="h-3 w-3" />}
                        At least 8 characters
                      </li>
                      <li className={`text-xs flex items-center gap-2 ${requirements.hasUppercase ? 'text-success' : 'text-muted-foreground'}`}>
                        {requirements.hasUppercase ? <Check className="h-3 w-3" /> : <X className="h-3 w-3" />}
                        One uppercase letter (A-Z)
                      </li>
                      <li className={`text-xs flex items-center gap-2 ${requirements.hasLowercase ? 'text-success' : 'text-muted-foreground'}`}>
                        {requirements.hasLowercase ? <Check className="h-3 w-3" /> : <X className="h-3 w-3" />}
                        One lowercase letter (a-z)
                      </li>
                      <li className={`text-xs flex items-center gap-2 ${requirements.hasNumber ? 'text-success' : 'text-muted-foreground'}`}>
                        {requirements.hasNumber ? <Check className="h-3 w-3" /> : <X className="h-3 w-3" />}
                        One number (0-9)
                      </li>
                      <li className={`text-xs flex items-center gap-2 ${requirements.hasSpecial ? 'text-success' : 'text-muted-foreground'}`}>
                        {requirements.hasSpecial ? <Check className="h-3 w-3" /> : <X className="h-3 w-3" />}
                        One special character (!@#$%^&*)
                      </li>
                      {!requirements.notCommon && (
                        <li className="text-xs flex items-center gap-2 text-destructive">
                          <X className="h-3 w-3" />
                          Password is too common
                        </li>
                      )}
                    </ul>
                  </div>
                </div>
              )}
            </div>

            <div>
              <Label htmlFor="confirmPassword">Confirm Password *</Label>
              <Input
                id="confirmPassword"
                type="password"
                placeholder="••••••••"
                value={formData.confirmPassword}
                onChange={(e) => setFormData({ ...formData, confirmPassword: e.target.value })}
                className="mt-1.5"
                disabled={isLoading}
              />
            </div>

            <div className="flex items-start gap-3">
              <Checkbox
                id="terms"
                checked={agreedToTerms}
                onCheckedChange={(checked) => setAgreedToTerms(checked as boolean)}
                disabled={isLoading}
              />
              <label htmlFor="terms" className="text-sm text-muted-foreground leading-tight">
                I agree to the{" "}
                <Link to="/terms-of-service" className="text-primary hover:underline">Terms of Service</Link>
                {" "}and{" "}
                <Link to="/privacy-policy" className="text-primary hover:underline">Privacy Policy</Link>
              </label>
            </div>

            <Button type="submit" className="w-full" disabled={isLoading}>
              {isLoading ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin mr-2" />
                  Creating account...
                </>
              ) : (
                "Create Account"
              )}
            </Button>
          </form>


          <p className="mt-6 text-center text-sm text-muted-foreground">
            <Link to="/landing" className="text-primary hover:underline">← Back to home</Link>
          </p>
        </div>
      </div>
    </div>
  );
}
