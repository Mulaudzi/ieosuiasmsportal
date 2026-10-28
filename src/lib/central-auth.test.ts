import { describe, expect, it } from "vitest";
import { centralCallbackToken, isCentralAuthHandoff, safeAuthDestination } from "./central-auth";

describe("central sign-in routing", () => {
  it.each(["/auth/callback", "/guymhan/auth/callback", "/auth/callback/"])("reserves %s for the callback even after removing its fragment", path => {
    expect(isCentralAuthHandoff(path, "")).toBe(true);
  });
  it("reserves legacy fragment handoffs but not ordinary portal routes", () => {
    expect(isCentralAuthHandoff("/login", "#ieosuia_token=fixture")).toBe(true);
    expect(isCentralAuthHandoff("/guymhan/login", "#ieosuia_admin_token=fixture")).toBe(true);
    expect(isCentralAuthHandoff("/dashboard", "#section")).toBe(false);
  });
  it("accepts only one token for the intended sign-in mode", () => {
    expect(centralCallbackToken("#ieosuia_token=fixture", false)).toBe("fixture");
    expect(centralCallbackToken("#ieosuia_admin_token=fixture", true)).toBe("fixture");
    for (const hash of ["", "#ieosuia_token=", "#ieosuia_token=a&ieosuia_token=b", "#ieosuia_token=a&ieosuia_admin_token=b"]) {
      expect(centralCallbackToken(hash, false)).toBeNull();
      expect(centralCallbackToken(hash, true)).toBeNull();
    }
    expect(centralCallbackToken("#ieosuia_token=fixture", true)).toBeNull();
    expect(centralCallbackToken("#ieosuia_admin_token=fixture", false)).toBeNull();
  });
  it.each([null, "https://example.invalid", "//example.invalid", "/\\example.invalid", "/%5cexample.invalid", "/%2fexample.invalid", "/login", "/auth/callback", "/guymhan", "/register", "/%61uth/callback", "/reports/../auth/callback", "/a/..//example.invalid", "/a/../%61uth/callback", "/%", "/dashboard\n"])("rejects unsafe or looping return path %s", path => {
    expect(safeAuthDestination(path)).toBe("/dashboard");
  });
  it("retains a normal local customer destination", () => {
    expect(safeAuthDestination("/sms-campaigns/11?view=recipients#details")).toBe("/sms-campaigns/11?view=recipients#details");
  });
});
