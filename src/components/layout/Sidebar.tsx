import { Link, useLocation, useNavigate } from "react-router-dom";
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
  Zap,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { logout } from "@/lib/api";

const navigation = [
  { name: "Dashboard", href: "/", icon: LayoutDashboard },
  { name: "SMS Campaigns", href: "/sms-campaigns", icon: MessageSquare },
  { name: "Email Campaigns", href: "/email-campaigns", icon: Mail },
  { name: "Contacts", href: "/contacts", icon: Users },
  { name: "Templates", href: "/templates", icon: FileText },
  { name: "Wallet", href: "/wallet", icon: Wallet },
  { name: "Reports", href: "/reports", icon: BarChart3 },
  { name: "Settings", href: "/settings", icon: Settings },
];

export function Sidebar() {
  const location = useLocation();
  const navigate = useNavigate();

  const handleLogout = async () => {
    try {
      await logout();
      toast({
        title: "Logged out",
        description: "You have been successfully logged out.",
      });
      // In a real app, this would redirect to login
    } catch (error) {
      toast({
        title: "Logout failed",
        description: "Please try again.",
        variant: "destructive",
      });
    }
  };

  return (
    <aside className="fixed left-0 top-0 z-40 h-screen w-64 bg-sidebar">
      <div className="flex h-full flex-col">
        {/* Logo */}
        <div className="flex h-16 items-center gap-3 border-b border-sidebar-border px-6">
          <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary">
            <Zap className="h-5 w-5 text-primary-foreground" />
          </div>
          <div>
            <h1 className="text-lg font-bold text-sidebar-primary-foreground">
              IEOSUIA
            </h1>
            <p className="text-xs text-sidebar-muted">SMS Portal</p>
          </div>
        </div>

        {/* Navigation */}
        <nav className="flex-1 space-y-1 px-3 py-4">
          {navigation.map((item) => {
            const isActive =
              item.href === "/"
                ? location.pathname === "/"
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
            12,450
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
            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-sidebar-accent text-sm font-medium text-sidebar-primary-foreground">
              JD
            </div>
            <div className="flex-1 min-w-0">
              <p className="truncate text-sm font-medium text-sidebar-primary-foreground">
                John Doe
              </p>
              <p className="truncate text-xs text-sidebar-muted">
                john@company.com
              </p>
            </div>
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
