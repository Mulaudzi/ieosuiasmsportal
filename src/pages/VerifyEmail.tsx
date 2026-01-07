import { useEffect, useState } from "react";
import { useSearchParams, useNavigate, Link } from "react-router-dom";
import { CheckCircle, XCircle, Loader2, Mail } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/hooks/useAuth";

type VerificationStatus = "loading" | "success" | "error" | "expired" | "already-verified";

export default function VerifyEmail() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { verifyEmail, resendVerification, isAuthenticated, user } = useAuth();
  
  const [status, setStatus] = useState<VerificationStatus>("loading");
  const [errorMessage, setErrorMessage] = useState<string>("");
  const [resendLoading, setResendLoading] = useState(false);
  const [resendSuccess, setResendSuccess] = useState(false);

  const token = searchParams.get("token");

  useEffect(() => {
    const verify = async () => {
      if (!token) {
        setStatus("error");
        setErrorMessage("No verification token provided");
        return;
      }

      const result = await verifyEmail(token);

      if (result.success) {
        setStatus("success");
      } else {
        if (result.error?.toLowerCase().includes("expired")) {
          setStatus("expired");
        } else if (result.error?.toLowerCase().includes("already")) {
          setStatus("already-verified");
        } else {
          setStatus("error");
        }
        setErrorMessage(result.error || "Verification failed");
      }
    };

    verify();
  }, [token, verifyEmail]);

  const handleResendVerification = async () => {
    setResendLoading(true);
    setResendSuccess(false);
    
    const result = await resendVerification();
    
    setResendLoading(false);
    if (result.success) {
      setResendSuccess(true);
    } else {
      setErrorMessage(result.error || "Failed to resend verification email");
    }
  };

  const renderContent = () => {
    switch (status) {
      case "loading":
        return (
          <>
            <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-muted">
              <Loader2 className="h-8 w-8 animate-spin text-primary" />
            </div>
            <CardTitle className="text-2xl">Verifying your email...</CardTitle>
            <CardDescription>Please wait while we verify your email address.</CardDescription>
          </>
        );

      case "success":
        return (
          <>
            <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/20">
              <CheckCircle className="h-8 w-8 text-green-600 dark:text-green-400" />
            </div>
            <CardTitle className="text-2xl text-green-600 dark:text-green-400">Email Verified!</CardTitle>
            <CardDescription>
              Your email has been successfully verified. You can now access all features.
            </CardDescription>
          </>
        );

      case "already-verified":
        return (
          <>
            <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/20">
              <CheckCircle className="h-8 w-8 text-blue-600 dark:text-blue-400" />
            </div>
            <CardTitle className="text-2xl text-blue-600 dark:text-blue-400">Already Verified</CardTitle>
            <CardDescription>
              Your email address has already been verified. You're all set!
            </CardDescription>
          </>
        );

      case "expired":
        return (
          <>
            <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-yellow-100 dark:bg-yellow-900/20">
              <XCircle className="h-8 w-8 text-yellow-600 dark:text-yellow-400" />
            </div>
            <CardTitle className="text-2xl text-yellow-600 dark:text-yellow-400">Link Expired</CardTitle>
            <CardDescription>
              This verification link has expired. Please request a new one.
            </CardDescription>
          </>
        );

      case "error":
      default:
        return (
          <>
            <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-destructive/10">
              <XCircle className="h-8 w-8 text-destructive" />
            </div>
            <CardTitle className="text-2xl text-destructive">Verification Failed</CardTitle>
            <CardDescription>{errorMessage || "Something went wrong. Please try again."}</CardDescription>
          </>
        );
    }
  };

  const renderActions = () => {
    if (status === "loading") return null;

    return (
      <div className="flex flex-col gap-3">
        {status === "success" || status === "already-verified" ? (
          <Button onClick={() => navigate(isAuthenticated ? "/dashboard" : "/login")} className="w-full">
            {isAuthenticated ? "Go to Dashboard" : "Go to Login"}
          </Button>
        ) : (
          <>
            {isAuthenticated && (status === "expired" || status === "error") && (
              <Button
                onClick={handleResendVerification}
                disabled={resendLoading || resendSuccess}
                className="w-full"
              >
                {resendLoading ? (
                  <>
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    Sending...
                  </>
                ) : resendSuccess ? (
                  <>
                    <Mail className="mr-2 h-4 w-4" />
                    Email Sent!
                  </>
                ) : (
                  <>
                    <Mail className="mr-2 h-4 w-4" />
                    Resend Verification Email
                  </>
                )}
              </Button>
            )}
            <Button variant="outline" onClick={() => navigate("/login")} className="w-full">
              Back to Login
            </Button>
          </>
        )}
      </div>
    );
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-background p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="space-y-4 text-center">
          {renderContent()}
        </CardHeader>
        <CardContent>
          {renderActions()}
          
          {resendSuccess && (
            <p className="mt-4 text-center text-sm text-muted-foreground">
              A new verification email has been sent to {user?.email}. Please check your inbox.
            </p>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
