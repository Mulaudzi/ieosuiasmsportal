import { Link, useLocation } from "react-router-dom";
import {
  LayoutDashboard,
  MessageSquare,
  Mail,
  Users,
  FileText,
  Wallet,
  BarChart3,
  Settings,
  LogOut,
  ChevronRight,
  Shield,
  Bug,
  Loader2,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { useAuth } from "@/hooks/useAuth";
import { useWallet } from "@/hooks/useWallet";
import { LogoSidebar } from "@/components/layout/Logo";

const navigation = [
  { name: "Dashboard", href: "/dashboard", icon: LayoutDashboard },
  { name: "SMS Campaigns", href: "/sms-campaigns", icon: MessageSquare },
  { name: "Email Campaigns", href: "/email-campaigns", icon: Mail },
  { name: "Contacts", href: "/contacts", icon: Users },
  { name: "Templates", href: "/templates", icon: FileText },
  { name: "Wallet", href: "/wallet", icon: Wallet },
  { name: "Reports", href: "/reports", icon: BarChart3 },
  { name: "Tests", href: "/test-dashboard", icon: Bug },
  { name: "Settings", href: "/settings", icon: Settings },
];

const adminNavigation = [
  { name: "Admin Dashboard", href: "/admin", icon: Shield },
  { name: "Admin Users", href: "/admin/users", icon: Users },
];

export function Sidebar() {
  const location = useLocation();
  const { user, logout } = useAuth();
  const { balance, isLoading: walletLoading } = useWallet();

  const handleLogout = async () => {
    await logout();
  };
  
  const getInitials = (name: string) => {
    return name
      .split(' ')
      .map(n => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  };


  return (
    <aside className="fixed left-0 top-0 z-40 h-screen w-64 bg-sidebar">
      <div className="flex h-full flex-col">
        {/* Logo */}
        <div className="flex h-16 items-center border-b border-sidebar-border px-6">
          <Link to="/dashboard">
            <LogoSidebar size="md" />
          </Link>
        </div>

        {/* Navigation */}
        <nav className="flex-1 space-y-1 px-3 py-4 overflow-y-auto">
          {navigation.map((item) => {
            const isActive =
              item.href === "/dashboard"
                ? location.pathname === "/dashboard"
                : location.pathname.startsWith(item.href);

            return (
              <Link
                key={item.name}
                to={item.href}
                className={cn(
                  "nav-item group",
                  isActive && "active"
                )}
              >
                <item.icon className="h-5 w-5 flex-shrink-0" />
                <span className="flex-1">{item.name}</span>
                {isActive && (
                  <ChevronRight className="h-4 w-4 opacity-60" />
                )}
              </Link>
            );
          })}
          
          {/* Admin Section - Only show for admin users */}
          {user?.account_type === "admin" && (
            <>
              <div className="mt-4 mb-2 px-3">
                <p className="text-xs font-semibold uppercase tracking-wider text-sidebar-muted">
                  Admin
                </p>
              </div>
              {adminNavigation.map((item) => {
                const isActive = location.pathname.startsWith(item.href);

                return (
                  <Link
                    key={item.name}
                    to={item.href}
                    className={cn(
                      "nav-item group",
                      isActive && "active"
                    )}
                  >
                    <item.icon className="h-5 w-5 flex-shrink-0" />
                    <span className="flex-1">{item.name}</span>
                    {isActive && (
                      <ChevronRight className="h-4 w-4 opacity-60" />
                    )}
                  </Link>
                );
              })}
            </>
          )}
        </nav>

        {/* Credit Balance Card */}
        <div className="mx-3 mb-4 rounded-xl bg-sidebar-accent p-4">
          <div className="flex items-center justify-between">
            <span className="text-sm text-sidebar-foreground/80">
              Credit Balance
            </span>
            <Wallet className="h-4 w-4 text-primary" />
          </div>
          <p className="mt-1 text-2xl font-bold text-sidebar-primary-foreground">
            {walletLoading ? (
              <Loader2 className="h-6 w-6 animate-spin" />
            ) : (
              balance.toLocaleString()
            )}
          </p>
          <p className="text-xs text-sidebar-muted">credits available</p>
          <Link
            to="/wallet"
            className="mt-3 flex w-full items-center justify-center gap-2 rounded-lg bg-primary py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
          >
            Buy Credits
          </Link>
        </div>

        {/* User Section */}
        <div className="border-t border-sidebar-border p-3">
          <div className="flex items-center gap-3 rounded-lg px-3 py-2">
            <Link 
              to="/settings"
              className="flex h-9 w-9 items-center justify-center rounded-full bg-sidebar-accent text-sm font-medium text-sidebar-primary-foreground hover:bg-primary hover:text-primary-foreground transition-colors"
            >
              {user ? getInitials(user.name) : 'U'}
            </Link>
            <Link to="/settings" className="flex-1 min-w-0 hover:opacity-80 transition-opacity">
              <p className="truncate text-sm font-medium text-sidebar-primary-foreground">
                {user?.name || 'User'}
              </p>
              <p className="truncate text-xs text-sidebar-muted">
                {user?.email || 'user@example.com'}
              </p>
            </Link>
            <button 
              className="rounded-lg p-2 text-sidebar-foreground/60 transition-colors hover:bg-sidebar-accent hover:text-sidebar-primary-foreground"
              onClick={handleLogout}
              aria-label="Logout"
            >
              <LogOut className="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>
    </aside>
  );
}