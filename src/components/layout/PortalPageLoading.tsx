import { useLocation } from "react-router-dom";
import { DashboardLayout } from "./DashboardLayout";
import { AdminLayout } from "./AdminLayout";
import { PageLoading, type PageSkeletonVariant } from "@/components/ui/page-loading";

/** Mounted inside the existing access guard, never in place of authorization. */
export function PortalPageLoading({ admin = false }: { admin?: boolean }) {
  const { pathname } = useLocation();
  let title = "Dashboard";
  let variant: PageSkeletonVariant = "overview";
  if (pathname.startsWith("/contacts")) { title = "Contacts"; variant = "contacts"; }
  else if (pathname === "/sms-campaigns/new") { title = "Send Messages"; variant = "wizard"; }
  else if (pathname === "/sms-campaigns") { title = "SMS Campaigns"; variant = "table"; }
  else if (pathname.startsWith("/sms-campaigns/")) title = "Campaign details";
  else if (pathname.startsWith("/email-campaigns")) { title = "Email Campaigns"; variant = "table"; }
  else if (pathname.startsWith("/templates")) { title = "Templates"; variant = "table"; }
  else if (pathname.startsWith("/wallet")) title = "Billing & Credits";
  else if (pathname.startsWith("/payment/")) { title = "Payment"; variant = "form"; }
  else if (pathname.startsWith("/reports")) title = "Reports";
  else if (pathname === "/settings" || pathname === "/profile") { title = "Settings"; variant = "form"; }
  if (admin) { title = "Platform Operations"; variant = "table"; }
  const Layout = admin ? AdminLayout : DashboardLayout;
  return <Layout title={title}><PageLoading variant={variant} label={`Loading ${title.toLowerCase()}`} /></Layout>;
}
