/** The callback owns validation, including after it removes the URL fragment. */
export function isCentralAuthHandoff(pathname: string, hash: string): boolean {
  const path = pathname.replace(/\/$/, "");
  const params = new URLSearchParams(hash.replace(/^#/, ""));
  return path === "/auth/callback" || path === "/guymhan/auth/callback"
    || params.has("ieosuia_token") || params.has("ieosuia_admin_token");
}

export function centralCallbackToken(hash: string, admin: boolean): string | null {
  const params = new URLSearchParams(hash.replace(/^#/, ""));
  const customer = params.getAll("ieosuia_token");
  const administrator = params.getAll("ieosuia_admin_token");
  const expected = admin ? administrator : customer;
  if (expected.length !== 1 || (admin ? customer : administrator).length) return null;
  return expected[0]?.trim() || null;
}

export function safeAuthDestination(requested: string | null): string {
  if (!requested || !requested.startsWith("/") || requested.startsWith("//")) return "/dashboard";
  try {
    const decoded = decodeURIComponent(requested);
    if (decoded.startsWith("//") || /[\\\s\u0000-\u001f]/.test(decoded)
      || /^\/(?:login|register|auth|guymhan)(?:[/?#]|$)/i.test(decoded)) return "/dashboard";
    const url = new URL(requested, "https://sms.ieosuia.com");
    const path = decodeURIComponent(url.pathname);
    if (url.origin !== "https://sms.ieosuia.com" || path.startsWith("//")
      || /^\/(?:login|register|auth|guymhan)(?:\/|$)/i.test(path)) return "/dashboard";
    return url.pathname + url.search + url.hash;
  } catch {
    return "/dashboard";
  }
}
