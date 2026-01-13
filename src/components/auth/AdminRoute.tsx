import { ReactNode, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";
import { Loader2, Shield } from "lucide-react";

interface AdminRouteProps {
  children: ReactNode;
}

export function AdminRoute({ children }: AdminRouteProps) {
  const navigate = useNavigate();
  const { user, isLoading: authLoading } = useAuth();
  const [isChecking, setIsChecking] = useState(true);
  const [isAuthorized, setIsAuthorized] = useState(false);

  useEffect(() => {
    if (authLoading) return;

    const checkAdminSession = () => {
      // Check for admin session token
      const adminSession = sessionStorage.getItem("admin_session");
      const timestamp = sessionStorage.getItem("admin_session_timestamp");

      if (!adminSession) {
        // No admin session, redirect to admin login
        navigate("/admin/login", { replace: true });
        return;
      }

      // Check session expiry (15 minutes)
      if (timestamp) {
        const lastActivity = parseInt(timestamp, 10);
        const now = Date.now();
        const TIMEOUT_MS = 15 * 60 * 1000;
        
        if (now - lastActivity > TIMEOUT_MS) {
          // Session expired
          sessionStorage.removeItem("admin_session");
          sessionStorage.removeItem("admin_session_timestamp");
          navigate("/admin/login", { replace: true });
          return;
        }
      }

      // Verify session format
      try {
        const decoded = atob(adminSession);
        if (!decoded.includes("-admin")) {
          navigate("/admin/login", { replace: true });
          return;
        }
      } catch {
        navigate("/admin/login", { replace: true });
        return;
      }

      // Check user account type
      if (!user || user.account_type !== "admin") {
        sessionStorage.removeItem("admin_session");
        sessionStorage.removeItem("admin_session_timestamp");
        navigate("/admin/login", { replace: true });
        return;
      }

      // All checks passed
      setIsAuthorized(true);
      setIsChecking(false);
    };

    checkAdminSession();
  }, [authLoading, user, navigate]);

  // Show loading state while checking
  if (authLoading || isChecking) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background">
        <div className="text-center">
          <div className="w-16 h-16 mx-auto mb-4 rounded-full bg-primary/10 flex items-center justify-center">
            <Shield className="h-8 w-8 text-primary animate-pulse" />
          </div>
          <Loader2 className="h-6 w-6 animate-spin mx-auto text-primary" />
          <p className="mt-4 text-muted-foreground">Verifying admin access...</p>
        </div>
      </div>
    );
  }

  if (!isAuthorized) {
    return null;
  }

  return <>{children}</>;
}
