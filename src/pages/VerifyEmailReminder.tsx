import { useState } from "react";
import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Zap, Mail, Loader2, CheckCircle } from "lucide-react";
import { useAuth } from "@/hooks/useAuth";
import { toast } from "@/hooks/use-toast";

export default function VerifyEmailReminder() {
  const { user, resendVerification, logout } = useAuth();
  const [isResending, setIsResending] = useState(false);
  const [sent, setSent] = useState(false);

  const handleResend = async () => {
    setIsResending(true);
    try {
      const result = await resendVerification();
      if (result.success) {
        setSent(true);
        toast({ title: "Email sent", description: "Check your inbox for the verification link." });
      } else {
        toast({ title: "Failed to send", description: result.error, variant: "destructive" });
      }
    } catch {
      toast({ title: "Error", description: "Failed to send verification email.", variant: "destructive" });
    } finally {
      setIsResending(false);
    }
  };

  const handleLogout = async () => {
    await logout();
    window.location.href = "/login";
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-8 bg-background">
      <div className="w-full max-w-md text-center">
        <div className="flex items-center gap-2 mb-8 justify-center">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary">
            <Zap className="h-6 w-6 text-primary-foreground" />
          </div>
          <span className="text-xl font-bold text-foreground">IEOSUIA SMS</span>
        </div>

        <div className="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-warning/10">
          <Mail className="h-10 w-10 text-warning" />
        </div>

        <h1 className="text-2xl font-bold text-foreground mb-2">Verify Your Email</h1>
        <p className="text-muted-foreground mb-6">
          Please verify your email address to access all features. We sent a verification link to:
        </p>

        <div className="rounded-lg bg-muted p-4 mb-6">
          <p className="font-medium text-foreground">{user?.email}</p>
        </div>

        {sent ? (
          <div className="flex items-center justify-center gap-2 text-success mb-6">
            <CheckCircle className="h-5 w-5" />
            <span>Verification email sent!</span>
          </div>
        ) : (
          <Button
            onClick={handleResend}
            disabled={isResending}
            className="w-full mb-4"
          >
            {isResending ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin mr-2" />
                Sending...
              </>
            ) : (
              <>
                <Mail className="h-4 w-4 mr-2" />
                Resend Verification Email
              </>
            )}
          </Button>
        )}

        <div className="space-y-3">
          <p className="text-sm text-muted-foreground">
            Already verified?{" "}
            <Link to="/dashboard" className="text-primary hover:underline">
              Go to Dashboard
            </Link>
          </p>
          <p className="text-sm text-muted-foreground">
            Wrong email?{" "}
            <button
              onClick={handleLogout}
              className="text-primary hover:underline"
            >
              Sign out and try again
            </button>
          </p>
        </div>
      </div>
    </div>
  );
}
