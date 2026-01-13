import { Link, useLocation } from "react-router-dom";
import {
  LayoutDashboard,
  Users,
  Shield,
  Bug,
  Settings,
  Key,
  Mail,
  Bell,
  Activity,
  FileText,
  ChevronRight,
  ArrowLeft,
  LogOut,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { useAuth } from "@/hooks/useAuth";
import { LogoSidebar } from "@/components/layout/Logo";

const adminNavigation = [
  { 
    name: "Overview", 
    href: "/admin", 
    icon: LayoutDashboard,
    exact: true,
    description: "Dashboard & stats"
  },
  { 
    name: "Admin Users", 
    href: "/admin/users", 
    icon: Shield,
    description: "Manage administrators"
  },
  { 
    name: "User Management", 
    href: "/admin?tab=users", 
    icon: Users,
    description: "All platform users"
  },
  { 
    name: "Contact Emails", 
    href: "/admin?tab=contact-emails", 
    icon: Mail,
    description: "Form submissions"
  },
  { 
    name: "QA Console", 
    href: "/admin/qa", 
    icon: Bug,
    description: "System testing"
  },
];

const settingsNavigation = [
  { 
    name: "SMTP Settings", 
    href: "/admin?tab=settings", 
    icon: Settings,
    description: "Email configuration"
  },
  { 
    name: "Notifications", 
    href: "/admin?tab=settings", 
    icon: Bell,
    description: "Alert settings"
  },
  { 
    name: "Audit Logs", 
    href: "/admin?tab=audit", 
    icon: FileText,
    description: "System activity"
  },
  { 
    name: "System Health", 
    href: "/admin?tab=health", 
    icon: Activity,
    description: "Service status"
  },
];

export function AdminSidebar() {
  const location = useLocation();
  const { user, logout } = useAuth();

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

  const isActive = (href: string, exact?: boolean) => {
    if (href.includes('?tab=')) {
      const [path, query] = href.split('?');
      const params = new URLSearchParams(query);
      const tab = params.get('tab');
      const currentTab = new URLSearchParams(location.search).get('tab');
      return location.pathname === path && currentTab === tab;
    }
    if (exact) {
      return location.pathname === href && !location.search;
    }
    return location.pathname.startsWith(href);
  };

  return (
    <aside className="fixed left-0 top-0 z-40 h-screen w-64 bg-sidebar">
      <div className="flex h-full flex-col">
        {/* Logo */}
        <div className="flex h-16 items-center border-b border-sidebar-border px-6">
          <Link to="/admin">
            <LogoSidebar size="md" />
          </Link>
        </div>

        {/* Admin Label */}
        <div className="px-6 py-3 border-b border-sidebar-border">
          <div className="flex items-center gap-2">
            <Shield className="h-4 w-4 text-destructive" />
            <span className="text-sm font-semibold text-destructive">Admin Panel</span>
          </div>
        </div>

        {/* Navigation */}
        <nav className="flex-1 space-y-1 px-3 py-4 overflow-y-auto">
          {/* Back to Dashboard */}
          <Link
            to="/dashboard"
            className="nav-item group mb-4 border border-dashed border-sidebar-border"
          >
            <ArrowLeft className="h-5 w-5 flex-shrink-0" />
            <span className="flex-1">Back to App</span>
          </Link>

          {/* Main Navigation */}
          <div className="mb-2 px-3">
            <p className="text-xs font-semibold uppercase tracking-wider text-sidebar-muted">
              Administration
            </p>
          </div>
          
          {adminNavigation.map((item) => {
            const active = isActive(item.href, item.exact);
            return (
              <Link
                key={item.name}
                to={item.href}
                className={cn(
                  "nav-item group",
                  active && "active"
                )}
              >
                <item.icon className="h-5 w-5 flex-shrink-0" />
                <div className="flex-1 min-w-0">
                  <span className="block truncate">{item.name}</span>
                  <span className="block text-xs text-sidebar-muted truncate">
                    {item.description}
                  </span>
                </div>
                {active && (
                  <ChevronRight className="h-4 w-4 opacity-60 flex-shrink-0" />
                )}
              </Link>
            );
          })}

          {/* Settings Section */}
          <div className="mt-6 mb-2 px-3">
            <p className="text-xs font-semibold uppercase tracking-wider text-sidebar-muted">
              Configuration
            </p>
          </div>
          
          {settingsNavigation.map((item) => {
            const active = isActive(item.href);
            return (
              <Link
                key={item.name}
                to={item.href}
                className={cn(
                  "nav-item group",
                  active && "active"
                )}
              >
                <item.icon className="h-5 w-5 flex-shrink-0" />
                <div className="flex-1 min-w-0">
                  <span className="block truncate">{item.name}</span>
                  <span className="block text-xs text-sidebar-muted truncate">
                    {item.description}
                  </span>
                </div>
                {active && (
                  <ChevronRight className="h-4 w-4 opacity-60 flex-shrink-0" />
                )}
              </Link>
            );
          })}
        </nav>

        {/* User Section */}
        <div className="border-t border-sidebar-border p-3">
          <div className="flex items-center gap-3 rounded-lg px-3 py-2">
            <Link 
              to="/profile"
              className="flex h-9 w-9 items-center justify-center rounded-full bg-destructive/20 text-sm font-medium text-destructive hover:bg-destructive hover:text-destructive-foreground transition-colors"
            >
              {user ? getInitials(user.name) : 'A'}
            </Link>
            <Link to="/profile" className="flex-1 min-w-0 hover:opacity-80 transition-opacity">
              <p className="truncate text-sm font-medium text-sidebar-primary-foreground">
                {user?.name || 'Admin'}
              </p>
              <p className="truncate text-xs text-sidebar-muted">
                Administrator
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
