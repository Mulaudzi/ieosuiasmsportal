import { useEffect, useRef, useCallback } from "react";
import { useNavigate } from "react-router-dom";
import { toast } from "@/hooks/use-toast";

const ADMIN_SESSION_KEY = "admin_session";
const ADMIN_SESSION_TIMESTAMP_KEY = "admin_session_timestamp";
const ADMIN_SESSION_TIMEOUT_MS = 15 * 60 * 1000; // 15 minutes of inactivity
const ACTIVITY_EVENTS = ["mousedown", "keydown", "scroll", "touchstart"];

export function useAdminSession() {
  const navigate = useNavigate();
  const timeoutRef = useRef<NodeJS.Timeout | null>(null);
  const lastActivityRef = useRef<number>(Date.now());

  const clearAdminSession = useCallback(() => {
    sessionStorage.removeItem(ADMIN_SESSION_KEY);
    sessionStorage.removeItem(ADMIN_SESSION_TIMESTAMP_KEY);
  }, []);

  const handleSessionTimeout = useCallback(() => {
    clearAdminSession();
    toast({
      title: "Session Expired",
      description: "Your admin session has expired due to inactivity. Please log in again.",
      variant: "destructive",
    });
    navigate("/login", { replace: true });
  }, [clearAdminSession, navigate]);

  const resetTimeout = useCallback(() => {
    lastActivityRef.current = Date.now();
    sessionStorage.setItem(ADMIN_SESSION_TIMESTAMP_KEY, Date.now().toString());

    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
    }

    timeoutRef.current = setTimeout(() => {
      handleSessionTimeout();
    }, ADMIN_SESSION_TIMEOUT_MS);
  }, [handleSessionTimeout]);

  const isSessionValid = useCallback(() => {
    const adminSession = sessionStorage.getItem(ADMIN_SESSION_KEY);
    const timestamp = sessionStorage.getItem(ADMIN_SESSION_TIMESTAMP_KEY);

    if (!adminSession) {
      return false;
    }

    if (timestamp) {
      const lastActivity = parseInt(timestamp, 10);
      const now = Date.now();
      if (now - lastActivity > ADMIN_SESSION_TIMEOUT_MS) {
        clearAdminSession();
        return false;
      }
    }

    try {
      const decoded = atob(adminSession);
      return decoded.includes("-admin");
    } catch {
      return false;
    }
  }, [clearAdminSession]);

  useEffect(() => {
    const adminSession = sessionStorage.getItem(ADMIN_SESSION_KEY);
    if (!adminSession) {
      return;
    }

    // Check if session is already expired
    if (!isSessionValid()) {
      handleSessionTimeout();
      return;
    }

    // Set initial timestamp if not set
    if (!sessionStorage.getItem(ADMIN_SESSION_TIMESTAMP_KEY)) {
      sessionStorage.setItem(ADMIN_SESSION_TIMESTAMP_KEY, Date.now().toString());
    }

    // Start the timeout
    resetTimeout();

    // Add activity listeners
    const handleActivity = () => {
      resetTimeout();
    };

    ACTIVITY_EVENTS.forEach((event) => {
      window.addEventListener(event, handleActivity);
    });

    return () => {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
      }
      ACTIVITY_EVENTS.forEach((event) => {
        window.removeEventListener(event, handleActivity);
      });
    };
  }, [resetTimeout, isSessionValid, handleSessionTimeout]);

  return {
    isSessionValid,
    clearAdminSession,
    resetTimeout,
  };
}
