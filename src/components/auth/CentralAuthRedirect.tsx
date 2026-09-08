import { useLayoutEffect } from "react";

export default function CentralAuthRedirect({ mode = "login", callback = false }: { mode?: "login" | "signup" | "admin"; callback?: boolean }) {
  useLayoutEffect(() => {
    const token = new URLSearchParams(window.location.hash.slice(1)).get("ieosuia_token");
    if (token) { localStorage.setItem("auth_token", token); localStorage.setItem("auth_token_issued", Date.now().toString()); window.location.replace("/dashboard"); return; }
    const adminToken = new URLSearchParams(window.location.hash.slice(1)).get("ieosuia_admin_token");
    if (adminToken) { localStorage.setItem("auth_token", adminToken); localStorage.setItem("auth_token_issued", Date.now().toString()); sessionStorage.setItem("admin_session_timestamp", Date.now().toString()); window.location.replace("/guymhan"); return; }
    if (callback) { window.location.replace("/?sso=invalid_response"); return; }
    const query = mode === "signup" ? "?screen_hint=signup" : mode === "admin" ? "?account_type=admin" : "";
    window.location.replace(`/api/auth/ieosuia/start${query}`);
  }, [mode, callback]);
  return <main className="min-h-screen grid place-items-center bg-slate-950" aria-live="polite"><div className="w-full max-w-sm space-y-5 px-6"><div className="mx-auto h-14 w-14 animate-pulse rounded-2xl bg-slate-800"/><div className="mx-auto h-5 w-44 animate-pulse rounded bg-slate-800"/><div className="h-12 animate-pulse rounded-xl bg-slate-800"/><div className="h-12 animate-pulse rounded-xl bg-slate-800"/><p className="text-center text-sm text-slate-500">Connecting securely to IEOSUIA Auth…</p></div></main>;
}
