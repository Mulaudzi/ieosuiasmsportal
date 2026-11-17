import { lazy, Suspense } from "react";
import { cleanup, render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, expect, it, vi } from "vitest";
import { PortalPageLoading } from "./PortalPageLoading";

vi.mock("./DashboardLayout", () => ({ DashboardLayout: ({ title, children }) => <><nav aria-label="Portal navigation"><a href="/dashboard">Dashboard</a></nav><h1>{title}</h1><main>{children}</main></> }));
vi.mock("./AdminLayout", () => ({ AdminLayout: ({ title, children }) => <><nav aria-label="Admin navigation"/><h1>{title}</h1><main>{children}</main></> }));
afterEach(cleanup);

it.each([
  ["/contacts", "Contacts"], ["/contacts/import", "Contacts"], ["/sms-campaigns/new", "Send Messages"],
  ["/sms-campaigns", "SMS Campaigns"], ["/sms-campaigns/24", "Campaign details"], ["/settings", "Settings"],
  ["/wallet", "Billing & Credits"], ["/reports", "Reports"], ["/dashboard", "Dashboard"],
])("keeps navigation and a matching skeleton for %s", (path, title) => {
  render(<MemoryRouter initialEntries={[path]}><PortalPageLoading /></MemoryRouter>);
  expect(screen.getByRole("heading", { name: title })).toBeInTheDocument();
  expect(screen.getByRole("navigation", { name: "Portal navigation" })).toBeInTheDocument();
  expect(screen.getByRole("status")).toHaveAttribute("aria-busy", "true");
});

it("provides an admin loading layout", () => {
  render(<MemoryRouter initialEntries={["/guymhan"]}><PortalPageLoading admin /></MemoryRouter>);
  expect(screen.getByRole("navigation", { name: "Admin navigation" })).toBeInTheDocument();
  expect(screen.getByRole("heading", { name: "Platform Operations" })).toBeInTheDocument();
});

it("replaces the skeleton when a delayed page chunk resolves", async () => {
  let finish: (value: { default: () => JSX.Element }) => void;
  const Page = lazy(() => new Promise<{ default: () => JSX.Element }>(resolve => { finish = resolve; }));
  render(<MemoryRouter initialEntries={["/contacts"]}><Suspense fallback={<PortalPageLoading />}><Page /></Suspense></MemoryRouter>);
  expect(screen.getByRole("status")).toBeInTheDocument();
  finish!({ default: () => <h1>Loaded contacts</h1> });
  expect(await screen.findByRole("heading", { name: "Loaded contacts" })).toBeInTheDocument();
  expect(screen.queryByRole("status")).not.toBeInTheDocument();
});
