import { useLayoutEffect, useState } from "react";
import { centralCallbackToken, safeAuthDestination } from "@/lib/central-auth";

const API_URL = import.meta.env.VITE_API_URL || "https://sms.ieosuia.com/api";

export default function CentralAuthRedirect({ mode = "login", callback = false }: { mode?: "login" | "signup" | "admin"; callback?: boolean }) {
  const [error, setError] = useState("");
  // Retain only in memory so a retry (and React effect replay) can reuse the
  // handoff after its sensitive fragment has been removed from the address bar.
  const [handoffToken] = useState(() => centralCallbackToken(window.location.hash, mode === "admin"));
  const [attempt, setAttempt] = useState(0);
  const [canRetry, setCanRetry] = useState(false);

  useLayoutEffect(() => {
    let cancelled = false;
    const controller = new AbortController();
    const signedOut = localStorage.getItem("ieosuia_explicit_logout");
    const token = handoffToken;
    const hadFragment = window.location.hash.includes("ieosuia_");
    if (hadFragment) window.history.replaceState({}, document.title, window.location.pathname + window.location.search);
    if (token && signedOut) { window.location.replace("/?signed_out=1"); return; }
    if (!token) {
      if (callback || hadFragment) { setError("We could not complete the secure sign-in. Please try again."); return; }
      const fresh = signedOut || sessionStorage.getItem('sms_session_expired') ? "prompt=login" : "";
      sessionStorage.removeItem('sms_session_expired');
      localStorage.removeItem("ieosuia_explicit_logout");
      const query = mode === "signup" ? `?screen_hint=signup${fresh ? `&${fresh}` : ""}` : mode === "admin" ? `?account_type=admin${fresh ? `&${fresh}` : ""}` : fresh ? `?${fresh}` : "";
      window.location.replace(`/api/auth/ieosuia/start${query}`);
      return;
    }

    setError("");
    setCanRetry(false);
    let retryable = true;
    const timeout = window.setTimeout(() => controller.abort(), 12000);
    const onLogout = (event: StorageEvent) => {
      if (event.key === "ieosuia_explicit_logout" && event.newValue) {
        cancelled = true;
        controller.abort();
        window.location.replace("/?signed_out=1");
      }
    };
    window.addEventListener("storage", onLogout);
    fetch(`${API_URL}/auth/user`, { signal: controller.signal, headers: { Authorization: `Bearer ${token}`, Accept: "application/json" } })
      .then(async (response) => {
        retryable = response.ok || response.status === 408 || response.status === 429 || response.status >= 500;
        const data = await response.json();
        if (!response.ok || !data.success || !data.user) {
          if (response.ok) retryable = false;
          throw new Error("Account validation failed");
        }
        if (mode === "admin" && data.user.role !== "admin") { retryable = false; throw new Error("Admin access required"); }
        if (cancelled || controller.signal.aborted) return;
        if (localStorage.getItem("ieosuia_explicit_logout")) { window.location.replace("/?signed_out=1"); return; }
        const issuedAt = Date.now().toString();
        // Commit the validated identity together before a fresh page bootstrap.
        localStorage.setItem("auth_user", JSON.stringify(data.user));
        localStorage.setItem("auth_token_issued", issuedAt);
        localStorage.setItem("auth_token", token);
        if (mode === "admin") sessionStorage.setItem("admin_session_timestamp", issuedAt);
        const requested = sessionStorage.getItem("redirectAfterLogin");
        sessionStorage.removeItem("redirectAfterLogin");
        window.location.replace(mode === "admin" ? "/guymhan" : safeAuthDestination(requested));
      })
      .catch(() => {
        if (cancelled) return;
        setCanRetry(retryable);
        setError(retryable
          ? "SMS could not finish checking your sign-in. Please retry the check. You do not need to enter your password again."
          : "SMS could not validate this sign-in. Please start secure sign-in again.");
      })
      .finally(() => window.clearTimeout(timeout));
    return () => { cancelled = true; controller.abort(); window.clearTimeout(timeout); window.removeEventListener("storage", onLogout); };
  }, [mode, callback, handoffToken, attempt]);

  if (error) return <main className="min-h-screen grid place-items-center bg-slate-950 px-6 text-white"><div className="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-8 text-center"><h1 className="text-xl font-semibold">Secure sign-in interrupted</h1><p className="mt-3 text-sm text-slate-300">{error}</p><button className="mt-6 rounded-xl bg-blue-600 px-5 py-3 font-semibold" onClick={() => canRetry ? setAttempt(value => value + 1) : window.location.replace(`/api/auth/ieosuia/start${mode === "admin" ? "?account_type=admin" : ""}`)}>{canRetry ? "Retry sign-in check" : "Try secure sign-in again"}</button></div></main>;
  return <main className="min-h-screen grid place-items-center bg-slate-950" aria-live="polite"><div className="w-full max-w-sm space-y-5 px-6"><div className="mx-auto h-14 w-14 animate-pulse rounded-2xl bg-slate-800"/><div className="mx-auto h-5 w-44 animate-pulse rounded bg-slate-800"/><div className="h-12 animate-pulse rounded-xl bg-slate-800"/><div className="h-12 animate-pulse rounded-xl bg-slate-800"/><p className="text-center text-sm text-slate-500">Finishing your secure sign-in…</p></div></main>;
}
