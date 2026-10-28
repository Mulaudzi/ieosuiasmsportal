import { Sidebar } from "./Sidebar";
import { PortalEntrance } from './PortalEntrance';
import { BarChart3, LayoutDashboard, Menu, MessageSquare, Search, Users, Wallet, Send } from "lucide-react";
import { Input } from "@/components/ui/input";
import { NotificationBell } from "./NotificationBell";
import { Button, PortalButtonHints } from "@/components/ui/button";
import { Sheet, SheetContent, SheetTrigger, SheetTitle, SheetDescription } from "@/components/ui/sheet";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { cn } from "@/lib/utils";
import { useEffect, useState } from "react";
import { DarkModeControl, Tutorial, usePortalPreference } from './PortalPreferences';
import './PortalWorkspace.css';
import { useAuth } from '@/hooks/useAuth';

const mobileNavigation = [
  { name: "Home", href: "/dashboard", icon: LayoutDashboard },
  { name: "Send Messages", href: "/sms-campaigns/new", icon: Send },
  { name: "Contacts", href: "/contacts", icon: Users },
  { name: "Wallet", href: "/wallet", icon: Wallet },
];

interface DashboardLayoutProps {
  children: React.ReactNode;
  title: string;
  subtitle?: string;
  actions?: React.ReactNode;
  campaignStep?: number;
}

export function DashboardLayout({
  children,
  title,
  subtitle,
  actions,
  campaignStep,
}: DashboardLayoutProps) {
  const [menuOpen, setMenuOpen] = useState(false);
  useEffect(()=>{const close=()=>setMenuOpen(false);window.addEventListener('sms:dashboard-tour',close);return()=>window.removeEventListener('sms:dashboard-tour',close);},[]);
  const location = useLocation();
  const tutorialTitle=location.pathname==='/dashboard'?'Dashboard':title;
  const navigate=useNavigate();const [search,setSearch]=useState('');
  const { user,logout } = useAuth();
  const [dark,setDark] = usePortalPreference('dark');
  const [collapsed,setCollapsed] = usePortalPreference('sidebar-collapsed');
  useEffect(()=>{document.documentElement.toggleAttribute('data-portal-dark',dark);return()=>document.documentElement.removeAttribute('data-portal-dark');},[dark]);

  return (
    <PortalButtonHints.Provider value={true}><div className="portal-shell min-h-[100dvh] min-w-0 bg-background text-foreground">
      <Sidebar collapsed={collapsed} onCollapse={()=>setCollapsed(!collapsed)} />

      {/* Main Content */}
      <div className={cn("portal-workspace min-w-0 pb-[calc(5rem+env(safe-area-inset-bottom))] lg:pb-0 lg:pl-64",collapsed&&'is-collapsed')}>
        {/* Top Header */}
        <header className="sticky top-0 z-30 flex min-h-16 items-center justify-between gap-2 border-b border-border bg-background/90 px-3 py-2 backdrop-blur-xl sm:px-4 lg:px-6">
          <div className="flex min-w-0 items-center gap-2 sm:gap-4">
            <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
              <SheetTrigger asChild>
                <Button variant="ghost" size="icon" className="shrink-0 lg:hidden" aria-label="Open navigation">
                  <Menu className="h-5 w-5" />
                </Button>
              </SheetTrigger>
              <SheetContent side="left" className="w-[86vw] max-w-72">
                <SheetTitle className="sr-only">More navigation</SheetTitle><SheetDescription className="sr-only">Portal pages, help and display preferences</SheetDescription>
                <nav aria-label="More navigation" className="flex flex-col gap-3 pt-8">
                  {['SMS Campaigns','Templates','Reports','Settings'].map(label=><Link key={label} className="rounded-lg p-3 hover:bg-muted" to={label==='SMS Campaigns'?'/sms-campaigns':'/'+label.toLowerCase()} onClick={()=>setMenuOpen(false)}>{label}</Link>)}
                  <Link className="rounded-lg p-3 hover:bg-muted" title="Need help" to="/support">Need help</Link>
                  <Tutorial title={tutorialTitle} step={campaignStep}/><DarkModeControl checked={dark} onChange={setDark}/>
                  {user?.role==='admin'&&<><Link to="/guymhan">Operations</Link><Link to="/guymhan/users">Platform Managers</Link></>}
                  <Button variant="ghost" onClick={()=>void logout()}>Sign out</Button>
                </nav>
              </SheetContent>
            </Sheet>
            <form role="search" onSubmit={event=>{event.preventDefault();if(search.trim())navigate(`/sms-campaigns?search=${encodeURIComponent(search.trim())}`);}} className="relative hidden sm:block">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                aria-label="Search campaigns" placeholder="Search campaigns and press Enter…" value={search} onChange={event=>setSearch(event.target.value)}
                className="w-56 pl-10 xl:w-96"
              />
            </form>
            <span className="truncate text-sm font-semibold lg:hidden">IEOSUIA SMS</span>
          </div>

          <div className="flex items-center gap-3">
            <div className="hidden lg:flex items-center gap-2"><DarkModeControl checked={dark} onChange={setDark}/><Tutorial title={tutorialTitle} step={campaignStep}/></div>
            <NotificationBell />
            <DropdownMenu><DropdownMenuTrigger asChild><Button variant="ghost" className="gap-2" aria-label="Account menu"><span className="flex h-8 w-8 items-center justify-center rounded-full bg-foreground text-xs text-background">{(user?.name??'U').split(' ').map(part=>part[0]).join('').slice(0,2).toUpperCase()}</span><span className="hidden max-w-32 truncate text-sm xl:block">{user?.name??'Account'}</span></Button></DropdownMenuTrigger><DropdownMenuContent align="end"><DropdownMenuItem asChild><Link to="/settings">Account settings</Link></DropdownMenuItem><DropdownMenuItem asChild><Link to="/support">Help and support</Link></DropdownMenuItem><DropdownMenuItem onSelect={()=>void logout()}>Sign out</DropdownMenuItem></DropdownMenuContent></DropdownMenu>
          </div>
        </header>

        {/* Page Header */}
        <div className="portal-page-header px-4 pt-5 pb-1 sm:px-6 sm:pt-6">
          <div className="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
              <h1 className="break-words text-xl font-bold tracking-tight text-foreground sm:text-2xl">{title}</h1>
              {subtitle && (
                <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>
              )}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2 sm:gap-3">{actions}</div>}
          </div>
        </div>

        {/* Page Content */}
        <PortalEntrance>{children}</PortalEntrance>
      </div>

      <nav aria-label="Mobile dashboard navigation" className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t border-border bg-card/95 px-1 pt-1.5 backdrop-blur-xl lg:hidden" style={{ paddingBottom: "max(0.4rem, env(safe-area-inset-bottom))" }}>
        {mobileNavigation.map((item) => {
          const active = item.href === "/dashboard" ? location.pathname === item.href : location.pathname.startsWith(item.href);
          return (
            <Link key={item.href} to={item.href} data-tour={item.href==='/wallet'?'credits':item.href==='/contacts'?'contacts':undefined} aria-current={active ? "page" : undefined} className={cn("flex min-w-0 min-h-14 flex-col items-center justify-center gap-1 rounded-xl px-1 text-[10px] font-semibold", active ? "bg-primary/10 text-primary" : "text-muted-foreground")}>
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
    </div></PortalButtonHints.Provider>
  );
}
