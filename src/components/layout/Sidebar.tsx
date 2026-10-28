import { Link, useLocation } from "react-router-dom";
import {
  LayoutDashboard,
  MessageSquare,
  Send,
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
  X,
  PanelLeftClose, PanelLeftOpen, CircleHelp,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { useAuth } from "@/hooks/useAuth";
import { useWallet } from "@/hooks/useWallet";
import { LogoSidebar } from "@/components/layout/Logo";

const navigation = [
  { name: "Dashboard", href: "/dashboard", icon: LayoutDashboard },
  { name: "Send Messages", href: "/sms-campaigns/new", icon: Send },
  { name: "SMS Campaigns", href: "/sms-campaigns", icon: MessageSquare },
  { name: "Contacts", href: "/contacts", icon: Users },
  { name: "Templates", href: "/templates", icon: FileText },
  { name: "Reports", href: "/reports", icon: BarChart3 },
  { name: "Billing & Credits", href: "/wallet", icon: Wallet },
  ...(import.meta.env.DEV ? [{ name: "Tests", href: "/test-dashboard", icon: Bug }] : []),
  { name: "Settings", href: "/settings", icon: Settings },
];

const adminNavigation = [
  { name: "Operations", href: "/guymhan", icon: Shield },
  { name: "Platform Managers", href: "/guymhan/users", icon: Users },
];

interface SidebarProps {
  mobile?: boolean;
  onNavigate?: () => void;
  collapsed?: boolean;
  onCollapse?: () => void;
}

export function Sidebar({ mobile = false, onNavigate, collapsed = false, onCollapse }: SidebarProps) {
  const location = useLocation();
  const { user, logout } = useAuth();
  const { smsCredits, pricePerCredit, isLoading: walletLoading } = useWallet();

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
    <aside className={cn(
      'portal-sidebar', collapsed && 'is-collapsed', mobile ? "h-full w-full bg-sidebar" : "fixed left-0 top-0 z-40 hidden h-[100dvh] w-64 bg-sidebar lg:block"
    )}>
      <div className="flex h-full flex-col">
        {/* Logo */}
        <div className="sidebar-logo flex h-16 items-center justify-between border-b border-sidebar-border px-4 sm:px-6">
          <Link to="/dashboard" onClick={onNavigate}>
            <LogoSidebar size="md" />
          </Link>
          {!mobile && <button type="button" title={collapsed?'Expand sidebar':'Collapse sidebar'} aria-label={collapsed?'Expand sidebar':'Collapse sidebar'} onClick={onCollapse} className="rounded p-1 text-sidebar-foreground hover:bg-sidebar-accent">{collapsed?<PanelLeftOpen size={18}/>:<PanelLeftClose size={18}/>}</button>}
          {mobile && (
            <button
              type="button"
              onClick={onNavigate}
              className="grid h-10 w-10 place-items-center rounded-xl text-sidebar-foreground transition-colors hover:bg-sidebar-accent"
              aria-label="Close navigation"
            >
              <X className="h-5 w-5" />
            </button>
          )}
        </div>

        {/* Navigation */}
        <nav className="flex-1 space-y-1 px-3 py-4 overflow-y-auto">
          {navigation.map((item) => {
            const isActive =
              item.href === "/dashboard" || item.href === "/sms-campaigns/new"
                ? location.pathname === item.href
                : location.pathname.startsWith(item.href) && !(item.href === '/sms-campaigns' && location.pathname === '/sms-campaigns/new');

            return (
              <Link
                key={item.name}
                data-tour={item.name==='Contacts'?'contacts':undefined}
                title={item.name}
                aria-current={isActive?'page':undefined}
                to={item.href}
                onClick={onNavigate}
                className={cn(
                  "nav-item group",
                  isActive && "active"
                )}
              >
                <item.icon className="h-5 w-5 flex-shrink-0" />
                <span className="sidebar-label flex-1">{item.name}</span>
                {isActive && (
                  <ChevronRight className="sidebar-chevron h-4 w-4 opacity-60" />
                )}
              </Link>
            );
          })}
          
          {/* Admin Section - Only show for admin users */}
          {user?.role === "admin" && (
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
                    title={item.name}
                    to={item.href}
                    onClick={onNavigate}
                    className={cn(
                      "nav-item group",
                      isActive && "active"
                    )}
                  >
                    <item.icon className="h-5 w-5 flex-shrink-0" />
                    <span className="sidebar-label flex-1">{item.name}</span>
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
        <Link to="/support" title="Need help" className="nav-item mx-3 mb-2"><CircleHelp className="h-5 w-5 shrink-0"/><span className="sidebar-label">Need help</span></Link>
        <div data-tour="credits" className="sidebar-credit mx-3 mb-4 rounded-xl bg-sidebar-accent p-4">
          <div className="flex items-center justify-between">
            <span className="text-sm text-sidebar-foreground/80">
              SMS Credits
            </span>
            <Wallet className="h-4 w-4 text-primary" />
          </div>
          <p className="credit-balance mt-1 text-2xl font-bold text-sidebar-primary-foreground">
            {walletLoading ? (
              <Loader2 className="h-6 w-6 animate-spin" />
            ) : (
              smsCredits.toLocaleString()
            )}
          </p>
          <Link
            to="/wallet"
            title="Buy Credits"
            onClick={onNavigate}
            className="mt-3 flex w-full items-center justify-center gap-2 rounded-lg bg-primary py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
          >
            Buy Credits
          </Link>
        </div>

        {/* User Section */}
        <div className="border-t border-sidebar-border p-3">
          <div className="sidebar-user flex items-center gap-3 rounded-lg px-3 py-2">
            <Link 
              to="/settings"
              onClick={onNavigate}
              className="flex h-9 w-9 items-center justify-center rounded-full bg-sidebar-accent text-sm font-medium text-sidebar-primary-foreground hover:bg-primary hover:text-primary-foreground transition-colors"
            >
              {user ? getInitials(user.name) : 'U'}
            </Link>
            <Link to="/settings" onClick={onNavigate} className="sidebar-user-details flex-1 min-w-0 hover:opacity-80 transition-opacity">
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
