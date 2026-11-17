import { ReactNode, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";
import { useAdminSession } from "@/hooks/useAdminSession";
import { AppLoadingScreen } from "@/components/ui/page-loading";

interface AdminRouteProps {
  children: ReactNode;
}

export function AdminRoute({ children }: AdminRouteProps) {
  const navigate = useNavigate();
  const { user, isLoading: authLoading } = useAuth();
  useAdminSession();
  const [isChecking, setIsChecking] = useState(true);
  const [isAuthorized, setIsAuthorized] = useState(false);

  useEffect(() => {
    if (authLoading) return;

    const checkAdminSession = () => {
      // The signed API identity is the only authorization source. The backend
      // repeats this role check for every administrative operation.
      if (!user || user.role !== "admin") {
        navigate("/guymhan/login", { replace: true });
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
    return <AppLoadingScreen />;
  }

  if (!isAuthorized) {
    return null;
  }

  return <>{children}</>;
}
