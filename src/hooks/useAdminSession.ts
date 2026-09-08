import { useEffect, useRef, useCallback } from "react";
import { useNavigate } from "react-router-dom";
import { toast } from "@/hooks/use-toast";
import { useAuth } from "@/hooks/useAuth";

const ADMIN_SESSION_TIMESTAMP_KEY = "admin_session_timestamp";
const ADMIN_SESSION_TIMEOUT_MS = 5 * 60 * 1000;
const ACTIVITY_EVENTS = ["mousedown", "keydown", "scroll", "touchstart"];

export function useAdminSession() {
  const navigate = useNavigate();
  const { user, logout } = useAuth();
  const timeoutRef = useRef<NodeJS.Timeout | null>(null);
  const lastActivityRef = useRef<number>(Date.now());

  const clearAdminSession = useCallback(() => {
    sessionStorage.removeItem(ADMIN_SESSION_TIMESTAMP_KEY);
  }, []);

  const handleSessionTimeout = useCallback(() => {
    clearAdminSession();
    void logout();
    toast({
      title: "Session Expired",
      description: "Your admin session has expired due to inactivity. Please log in again.",
      variant: "destructive",
    });
    navigate("/guymhan/login", { replace: true });
  }, [clearAdminSession, logout, navigate]);

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
    const timestamp = sessionStorage.getItem(ADMIN_SESSION_TIMESTAMP_KEY);

    if (user?.role !== "admin") {
      return false;
    }

    if (timestamp) {
      const lastActivity = parseInt(timestamp, 10);
      const tokenIssuedAt = parseInt(localStorage.getItem("auth_token_issued") || "0", 10);
      const now = Date.now();
      if (tokenIssuedAt > lastActivity && now - tokenIssuedAt <= ADMIN_SESSION_TIMEOUT_MS) {
        sessionStorage.setItem(ADMIN_SESSION_TIMESTAMP_KEY, tokenIssuedAt.toString());
        return true;
      }
      if (now - lastActivity > ADMIN_SESSION_TIMEOUT_MS) {
        clearAdminSession();
        return false;
      }
    }

    return true;
  }, [clearAdminSession, user]);

  useEffect(() => {
    if (user?.role !== "admin") {
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
  }, [user, resetTimeout, isSessionValid, handleSessionTimeout]);

  return {
    isSessionValid,
    clearAdminSession,
    resetTimeout,
  };
}
