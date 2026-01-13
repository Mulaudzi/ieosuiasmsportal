import { useState } from "react";
import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Loader2, ArrowLeft, Mail, CheckCircle, KeyRound } from "lucide-react";
import { toast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { useRecaptcha } from "@/hooks/useRecaptcha";
import smsPortalLogo from "@/assets/ieosuia-sms-portal-logo.png";
import smsPortalLogoWhite from "@/assets/ieosuia-sms-portal-logo-white.png";

type Step = "email" | "code" | "reset" | "success";

export default function ForgotPassword() {
  const [step, setStep] = useState<Step>("email");
  const [isLoading, setIsLoading] = useState(false);
  const [email, setEmail] = useState("");
  const [otp, setOtp] = useState("");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const { executeRecaptcha } = useRecaptcha();

  const handleSendCode = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email) {
      toast({ title: "Email required", description: "Please enter your email address.", variant: "destructive" });
      return;
    }

    setIsLoading(true);
    try {
      // Execute reCAPTCHA
      const recaptchaToken = await executeRecaptcha('forgot_password');
      
      const response = await api.post("/auth/forgot-password", { 
        email,
        recaptcha_token: recaptchaToken 
      });
      if (response.success) {
        toast({ title: "Code sent", description: "Check your email for the reset code." });
        setStep("code");
      }
    } catch (error) {
      toast({ title: "Error", description: error instanceof Error ? error.message : "Failed to send code", variant: "destructive" });
    } finally {
      setIsLoading(false);
    }
  };

  const handleVerifyCode = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!otp || otp.length !== 6) {
      toast({ title: "Invalid code", description: "Please enter the 6-digit code.", variant: "destructive" });
      return;
    }
    setStep("reset");
  };

  const handleResetPassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (password.length < 8) {
      toast({ title: "Password too short", description: "Password must be at least 8 characters.", variant: "destructive" });
      return;
    }
    if (password !== confirmPassword) {
      toast({ title: "Passwords don't match", description: "Please make sure your passwords match.", variant: "destructive" });
      return;
    }

    setIsLoading(true);
    try {
      // Execute reCAPTCHA
      const recaptchaToken = await executeRecaptcha('reset_password');
      
      const response = await api.post("/auth/reset-password", {
        email,
        otp,
        password,
        password_confirmation: confirmPassword,
        recaptcha_token: recaptchaToken,
      });
      if (response.success) {
        setStep("success");
      }
    } catch (error) {
      toast({ title: "Error", description: error instanceof Error ? error.message : "Failed to reset password", variant: "destructive" });
    } finally {
      setIsLoading(false);
    }
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
            Reset Your Password
          </h1>
          <p className="text-lg text-sidebar-muted">
            Don't worry, we'll help you get back into your account. Just follow the simple steps to reset your password.
          </p>
          <div className="mt-12 space-y-4">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center">
                <span className="text-lg">1️⃣</span>
              </div>
              <span className="text-sidebar-muted">Enter your email address</span>
            </div>
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center">
                <span className="text-lg">2️⃣</span>
              </div>
              <span className="text-sidebar-muted">Enter the code sent to your email</span>
            </div>
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center">
                <span className="text-lg">3️⃣</span>
              </div>
              <span className="text-sidebar-muted">Create a new secure password</span>
            </div>
          </div>
        </div>
      </div>

      {/* Right Panel - Form */}
      <div className="flex-1 flex items-center justify-center p-8 bg-background">
        <div className="w-full max-w-md">
          <div className="lg:hidden flex items-center justify-center mb-8">
            <img 
              src={smsPortalLogo} 
              alt="IEOSUIA SMS Portal" 
              className="h-12 w-auto"
            />
          </div>

          {step === "email" && (
            <>
              <div className="text-center mb-8">
                <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
                  <Mail className="h-8 w-8 text-primary" />
                </div>
                <h2 className="text-2xl font-bold text-foreground">Forgot Password?</h2>
                <p className="mt-2 text-muted-foreground">
                  Enter your email and we'll send you a reset code
                </p>
              </div>

              <form onSubmit={handleSendCode} className="space-y-6">
                <div>
                  <Label htmlFor="email">Email Address</Label>
                  <Input
                    id="email"
                    type="email"
                    placeholder="you@example.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    className="mt-1.5"
                    disabled={isLoading}
                  />
                </div>

                <Button type="submit" className="w-full" disabled={isLoading}>
                  {isLoading ? (
                    <>
                      <Loader2 className="h-4 w-4 animate-spin mr-2" />
                      Sending...
                    </>
                  ) : (
                    "Send Reset Code"
                  )}
                </Button>
              </form>
            </>
          )}

          {step === "code" && (
            <>
              <div className="text-center mb-8">
                <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
                  <KeyRound className="h-8 w-8 text-primary" />
                </div>
                <h2 className="text-2xl font-bold text-foreground">Enter Reset Code</h2>
                <p className="mt-2 text-muted-foreground">
                  We sent a 6-digit code to <strong>{email}</strong>
                </p>
              </div>

              <form onSubmit={handleVerifyCode} className="space-y-6">
                <div>
                  <Label htmlFor="otp">Reset Code</Label>
                  <Input
                    id="otp"
                    type="text"
                    placeholder="000000"
                    value={otp}
                    onChange={(e) => setOtp(e.target.value.replace(/\D/g, "").slice(0, 6))}
                    className="mt-1.5 text-center text-2xl tracking-widest"
                    maxLength={6}
                    disabled={isLoading}
                  />
                </div>

                <Button type="submit" className="w-full">
                  Verify Code
                </Button>

                <Button
                  type="button"
                  variant="ghost"
                  className="w-full"
                  onClick={() => setStep("email")}
                >
                  <ArrowLeft className="h-4 w-4 mr-2" />
                  Back to email
                </Button>
              </form>
            </>
          )}

          {step === "reset" && (
            <>
              <div className="text-center mb-8">
                <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
                  <KeyRound className="h-8 w-8 text-primary" />
                </div>
                <h2 className="text-2xl font-bold text-foreground">Set New Password</h2>
                <p className="mt-2 text-muted-foreground">
                  Create a strong password for your account
                </p>
              </div>

              <form onSubmit={handleResetPassword} className="space-y-6">
                <div>
                  <Label htmlFor="password">New Password</Label>
                  <Input
                    id="password"
                    type="password"
                    placeholder="Min. 8 characters"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    className="mt-1.5"
                    disabled={isLoading}
                  />
                </div>

                <div>
                  <Label htmlFor="confirmPassword">Confirm Password</Label>
                  <Input
                    id="confirmPassword"
                    type="password"
                    placeholder="••••••••"
                    value={confirmPassword}
                    onChange={(e) => setConfirmPassword(e.target.value)}
                    className="mt-1.5"
                    disabled={isLoading}
                  />
                </div>

                <Button type="submit" className="w-full" disabled={isLoading}>
                  {isLoading ? (
                    <>
                      <Loader2 className="h-4 w-4 animate-spin mr-2" />
                      Resetting...
                    </>
                  ) : (
                    "Reset Password"
                  )}
                </Button>
              </form>
            </>
          )}

          {step === "success" && (
            <div className="text-center">
              <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-success/10">
                <CheckCircle className="h-8 w-8 text-success" />
              </div>
              <h2 className="text-2xl font-bold text-foreground">Password Reset!</h2>
              <p className="mt-2 text-muted-foreground">
                Your password has been reset successfully. You can now log in with your new password.
              </p>
              <Link to="/login">
                <Button className="mt-6 w-full">Go to Login</Button>
              </Link>
            </div>
          )}

          <p className="mt-8 text-center text-sm text-muted-foreground">
            <Link to="/login" className="text-primary hover:underline">
              <ArrowLeft className="h-4 w-4 inline mr-1" />
              Back to login
            </Link>
          </p>
        </div>
      </div>
    </div>
  );
}
