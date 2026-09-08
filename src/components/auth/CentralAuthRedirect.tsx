import { useEffect, useState } from "react";

const API_URL = import.meta.env.VITE_API_URL || "https://sms.ieosuia.com/api";

export default function CentralAuthRedirect({ mode = "login", callback = false }: { mode?: "login" | "signup" | "admin"; callback?: boolean }) {
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams(window.location.hash.slice(1));
    const customerToken = params.get("ieosuia_token");
    const adminToken = params.get("ieosuia_admin_token");
    const token = customerToken || adminToken;
    if (!token) {
      if (callback) { setError("We could not complete the secure sign-in. Please try again."); return; }
      const query = mode === "signup" ? "?screen_hint=signup" : mode === "admin" ? "?account_type=admin" : "";
      window.location.replace(`/api/auth/ieosuia/start${query}`);
      return;
    }

    localStorage.setItem("auth_token", token);
    localStorage.setItem("auth_token_issued", Date.now().toString());
    localStorage.removeItem("auth_user");
    if (adminToken) sessionStorage.setItem("admin_session_timestamp", Date.now().toString());
    window.history.replaceState({}, document.title, window.location.pathname + window.location.search);

    fetch(`${API_URL}/auth/user`, { headers: { Authorization: `Bearer ${token}`, Accept: "application/json" } })
      .then(async (response) => {
        const data = await response.json();
        if (!response.ok || !data.success || !data.user) throw new Error("Account validation failed");
        if (cancelled) return;
        localStorage.setItem("auth_user", JSON.stringify(data.user));
        const requested = sessionStorage.getItem("redirectAfterLogin");
        sessionStorage.removeItem("redirectAfterLogin");
        const safeRequested = requested?.startsWith("/") && !requested.startsWith("/login") && !requested.startsWith("/auth/") ? requested : null;
        window.location.replace(adminToken ? "/guymhan" : safeRequested || "/dashboard");
      })
      .catch(() => {
        if (cancelled) return;
        localStorage.removeItem("auth_token"); localStorage.removeItem("auth_token_issued"); localStorage.removeItem("auth_user");
        setError("Your account exists, but SMS could not finish signing you in. Please try again.");
      });
    return () => { cancelled = true; };
  }, [mode, callback]);

  if (error) return <main className="min-h-screen grid place-items-center bg-slate-950 px-6 text-white"><div className="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-8 text-center"><h1 className="text-xl font-semibold">Secure sign-in interrupted</h1><p className="mt-3 text-sm text-slate-300">{error}</p><button className="mt-6 rounded-xl bg-blue-600 px-5 py-3 font-semibold" onClick={() => window.location.replace(`/api/auth/ieosuia/start${mode === "admin" ? "?account_type=admin" : ""}`)}>Try secure sign-in again</button></div></main>;
  return <main className="min-h-screen grid place-items-center bg-slate-950" aria-live="polite"><div className="w-full max-w-sm space-y-5 px-6"><div className="mx-auto h-14 w-14 animate-pulse rounded-2xl bg-slate-800"/><div className="mx-auto h-5 w-44 animate-pulse rounded bg-slate-800"/><div className="h-12 animate-pulse rounded-xl bg-slate-800"/><div className="h-12 animate-pulse rounded-xl bg-slate-800"/><p className="text-center text-sm text-slate-500">Finishing your secure sign-in…</p></div></main>;
}
