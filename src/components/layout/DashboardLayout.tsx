import { Sidebar } from "./Sidebar";
import { BarChart3, LayoutDashboard, Menu, MessageSquare, Search, Users, Wallet } from "lucide-react";
import { Input } from "@/components/ui/input";
import { NotificationBell } from "./NotificationBell";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetTrigger } from "@/components/ui/sheet";
import { Link, useLocation } from "react-router-dom";
import { cn } from "@/lib/utils";
import { useState } from "react";

const mobileNavigation = [
  { name: "Home", href: "/dashboard", icon: LayoutDashboard },
  { name: "Campaigns", href: "/sms-campaigns", icon: MessageSquare },
  { name: "Contacts", href: "/contacts", icon: Users },
  { name: "Wallet", href: "/wallet", icon: Wallet },
];

interface DashboardLayoutProps {
  children: React.ReactNode;
  title: string;
  subtitle?: string;
  actions?: React.ReactNode;
}

export function DashboardLayout({
  children,
  title,
  subtitle,
  actions,
}: DashboardLayoutProps) {
  const [menuOpen, setMenuOpen] = useState(false);
  const location = useLocation();

  return (
    <div className="min-h-[100dvh] min-w-0 bg-background">
      <Sidebar />

      {/* Main Content */}
      <div className="min-w-0 pb-[calc(5rem+env(safe-area-inset-bottom))] lg:pb-0 lg:pl-64">
        {/* Top Header */}
        <header className="sticky top-0 z-30 flex min-h-16 items-center justify-between gap-2 border-b border-border bg-background/90 px-3 py-2 backdrop-blur-xl sm:px-4 lg:px-6">
          <div className="flex min-w-0 items-center gap-2 sm:gap-4">
            <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
              <SheetTrigger asChild>
                <Button variant="ghost" size="icon" className="shrink-0 lg:hidden" aria-label="Open navigation">
                  <Menu className="h-5 w-5" />
                </Button>
              </SheetTrigger>
              <SheetContent side="left" className="w-[86vw] max-w-72 border-0 bg-sidebar p-0">
                <Sidebar mobile onNavigate={() => setMenuOpen(false)} />
              </SheetContent>
            </Sheet>
            <div className="relative hidden sm:block">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search campaigns, contacts..."
                className="w-64 pl-10 xl:w-80"
              />
            </div>
            <span className="truncate text-sm font-semibold lg:hidden">IEOSUIA SMS</span>
          </div>

          <div className="flex items-center gap-3">
            <NotificationBell />
          </div>
        </header>

        {/* Page Header */}
        <div className="border-b border-border bg-card px-4 py-4 sm:px-6 sm:py-6">
          <div className="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
              <h1 className="truncate text-xl font-bold text-foreground sm:text-2xl">{title}</h1>
              {subtitle && (
                <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>
              )}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2 sm:gap-3">{actions}</div>}
          </div>
        </div>

        {/* Page Content */}
        <main className="min-w-0 p-3 sm:p-4 lg:p-6">{children}</main>
      </div>

      <nav aria-label="Mobile dashboard navigation" className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t border-border bg-card/95 px-1 pt-1.5 backdrop-blur-xl lg:hidden" style={{ paddingBottom: "max(0.4rem, env(safe-area-inset-bottom))" }}>
        {mobileNavigation.map((item) => {
          const active = item.href === "/dashboard" ? location.pathname === item.href : location.pathname.startsWith(item.href);
          return (
            <Link key={item.href} to={item.href} aria-current={active ? "page" : undefined} className={cn("flex min-w-0 min-h-14 flex-col items-center justify-center gap-1 rounded-xl px-1 text-[10px] font-semibold", active ? "bg-primary/10 text-primary" : "text-muted-foreground")}>
              <item.icon className="h-5 w-5" />
              <span className="max-w-full truncate">{item.name}</span>
            </Link>
          );
        })}
        <button type="button" onClick={() => setMenuOpen(true)} className="flex min-w-0 min-h-14 flex-col items-center justify-center gap-1 rounded-xl px-1 text-[10px] font-semibold text-muted-foreground" aria-label="Open more navigation options">
          <BarChart3 className="h-5 w-5" />
          <span>More</span>
        </button>
      </nav>
    </div>
  );
}
