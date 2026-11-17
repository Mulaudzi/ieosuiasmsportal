import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { AuthProvider, useAuth } from "@/hooks/useAuth";
import CentralAuthRedirect from "./CentralAuthRedirect";

function State() {
  const { isLoading, isAuthenticated } = useAuth();
  return <output data-testid="auth-state">{String(isLoading)}:{String(isAuthenticated)}</output>;
}
beforeEach(() => {
  localStorage.clear(); sessionStorage.clear();
  window.history.replaceState({}, "", "/auth/callback#ieosuia_token=synthetic-fixture");
});
afterEach(() => { cleanup(); vi.unstubAllGlobals(); window.history.replaceState({}, "", "/"); });

describe("single-owner central sign-in handoff", () => {
  it.each([false, true])("has one profile check with admin=%s, even with a previous stored session", async admin => {
    localStorage.setItem("auth_token", "previous-fixture");
    localStorage.setItem("auth_user", JSON.stringify({ id: "previous", email: "previous@example.invalid" }));
    if (admin) window.history.replaceState({}, "", "/guymhan/auth/callback#ieosuia_admin_token=synthetic-fixture");
    const fetcher = vi.fn<typeof fetch>(() => new Promise<Response>(() => {})); vi.stubGlobal("fetch", fetcher);
    render(<AuthProvider><State/><CentralAuthRedirect callback mode={admin ? "admin" : "login"}/></AuthProvider>);
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(1));
    expect(screen.getByTestId("auth-state")).toHaveTextContent("true:false");
    expect(window.location.hash).toBe("");
    expect(localStorage.getItem("auth_token")).toBe("previous-fixture");
    expect(new Headers(fetcher.mock.calls[0][1]?.headers).get("Authorization")).toBe("Bearer synthetic-fixture");
  });
  it.each([503, 507, 429])("retains the in-memory handoff for a %s retry without restarting SSO", async status => {
    const fetcher = vi.fn().mockResolvedValueOnce(new Response("<h1>Private host details</h1>", { status }))
      .mockImplementation(() => new Promise<Response>(() => {}));
    vi.stubGlobal("fetch", fetcher);
    render(<AuthProvider><CentralAuthRedirect callback/></AuthProvider>);
    fireEvent.click(await screen.findByRole("button", { name: "Retry sign-in check" }));
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
    expect(fetcher.mock.calls[1][1].headers.Authorization).toBe("Bearer synthetic-fixture");
    expect(window.location.pathname).toBe("/auth/callback");
    expect(localStorage.getItem("auth_token")).toBeNull();
    expect(screen.queryByText("Private host details")).not.toBeInTheDocument();
  });
  it.each([401, 403])("does not retry or restart SSO automatically after a %s rejection", async status => {
    const fetcher = vi.fn().mockResolvedValue(new Response(JSON.stringify({ success: false }), { status }));
    vi.stubGlobal("fetch", fetcher);
    render(<AuthProvider><CentralAuthRedirect callback/></AuthProvider>);
    expect(await screen.findByRole("button", { name: "Try secure sign-in again" })).toBeInTheDocument();
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(window.location.pathname).toBe("/auth/callback");
    expect(localStorage.getItem("auth_token")).toBeNull();
  });
  it("aborts its profile check on unmount", () => {
    const fetcher = vi.fn<typeof fetch>(() => new Promise<Response>(() => {})); vi.stubGlobal("fetch", fetcher);
    const view = render(<AuthProvider><CentralAuthRedirect callback/></AuthProvider>);
    const signal = fetcher.mock.calls[0][1].signal as AbortSignal;
    view.unmount(); expect(signal.aborted).toBe(true);
  });
  it("does not bootstrap stale local credentials following explicit logout", async () => {
    window.history.replaceState({}, "", "/");
    localStorage.setItem("auth_token", "previous-fixture");
    localStorage.setItem("ieosuia_explicit_logout", String(Date.now()));
    const fetcher = vi.fn(); vi.stubGlobal("fetch", fetcher);
    render(<AuthProvider><State/></AuthProvider>);
    await waitFor(() => expect(screen.getByTestId("auth-state")).toHaveTextContent("false:false"));
    expect(fetcher).not.toHaveBeenCalled();
    expect(localStorage.getItem("auth_token")).toBeNull();
  });
});
