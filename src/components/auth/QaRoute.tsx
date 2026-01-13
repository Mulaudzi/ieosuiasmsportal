import { ReactNode, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";

// Email allowed to access QA Console
const QA_ALLOWED_EMAIL = "vendaboy.lm@gmail.com";

interface QaRouteProps {
  children: ReactNode;
}

export function QaRoute({ children }: QaRouteProps) {
  const { user, isLoading } = useAuth();
  const navigate = useNavigate();
  const [isAuthorized, setIsAuthorized] = useState(false);
  const [isChecking, setIsChecking] = useState(true);

  useEffect(() => {
    if (isLoading) return;

    // Check if user is authenticated
    if (!user) {
      navigate("/login", { replace: true });
      return;
    }

    // Check if user has the allowed email
    if (user.email !== QA_ALLOWED_EMAIL) {
      navigate("/dashboard", { replace: true });
      return;
    }

    setIsAuthorized(true);
    setIsChecking(false);
  }, [isLoading, user, navigate]);

  if (isLoading || isChecking) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background">
        <div className="text-center">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto"></div>
          <p className="mt-2 text-muted-foreground">Checking access...</p>
        </div>
      </div>
    );
  }

  if (!isAuthorized) {
    return null;
  }

  return <>{children}</>;
}
