import { Link, useLocation } from 'react-router-dom';
import { Activity, ArrowLeft, CalendarClock, ChevronRight, CreditCard, FileText, Inbox, LayoutDashboard, LogOut, Mail, MessageCircle, MessageSquare, Settings, Shield, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useAuth } from '@/hooks/useAuth';
import { LogoSidebar } from '@/components/layout/Logo';
import { adminLinkActive } from '@/lib/admin-tools';

const navigation = [
  { label: 'Workspace', items: [
    { name: 'Overview', href: '/guymhan', icon: LayoutDashboard },
    { name: 'Customers', href: '/guymhan?tab=customers', icon: Users },
    { name: 'SMS campaigns', href: '/guymhan?tab=campaigns', icon: MessageSquare },
    { name: 'Finance', href: '/guymhan?tab=finance', icon: CreditCard },
    { name: 'Support inbox', href: '/guymhan/tools?tab=contact-emails', icon: Inbox },
  ] },
  { label: 'People & channels', items: [
    { name: 'User access', href: '/guymhan/tools?tab=users', icon: Users },
    { name: 'Platform managers', href: '/guymhan/users', icon: Shield },
    { name: 'Email review', href: '/guymhan/email-campaigns', icon: Mail },
    { name: 'WhatsApp setup', href: '/guymhan/whatsapp', icon: MessageCircle },
  ] },
  { label: 'System', items: [
    { name: 'Service monitoring', href: '/guymhan?tab=monitoring', icon: Activity },
    { name: 'Scheduled messages', href: '/guymhan/tools?tab=scheduled', icon: CalendarClock },
    { name: 'Configuration', href: '/guymhan/tools?tab=settings', icon: Settings },
    { name: 'System health', href: '/guymhan/tools?tab=health', icon: Activity },
    { name: 'Audit logs', href: '/guymhan/logs', icon: FileText },
  ] },
];
export const adminSidebarLinks = navigation.flatMap(group => group.items.map(item => item.href));
interface AdminSidebarProps { mobile?: boolean; onNavigate?: () => void }
export function AdminSidebar({ mobile = false, onNavigate }: AdminSidebarProps) {
  const location = useLocation();
  const { user, logout } = useAuth();
  const initials = user?.name?.trim().split(/\s+/).map(part => part[0]).join('').slice(0, 2).toUpperCase() || 'A';
  return <aside className={cn(mobile ? 'h-full w-full bg-sidebar' : 'fixed left-0 top-0 z-40 hidden h-screen w-64 bg-sidebar lg:block')}>
    <div className="flex h-full flex-col">
      <div className="flex h-16 shrink-0 items-center border-b border-sidebar-border px-6"><Link to="/guymhan" onClick={onNavigate} aria-label="Admin home"><LogoSidebar size="md"/></Link></div>
      <div className="admin-sidebar-label"><span><Shield size={15} aria-hidden="true"/></span><span>Administration</span></div>
      <nav className="min-h-0 flex-1 overflow-y-auto px-3 py-4" aria-label="Admin navigation">
        <Link to="/dashboard" onClick={onNavigate} className="admin-sidebar-back nav-item border border-dashed border-sidebar-border"><ArrowLeft size={17} aria-hidden="true"/><span>Back to SMS Portal</span></Link>
        {navigation.map(group => <section key={group.label} className="admin-sidebar-group"><h2>{group.label}</h2>{group.items.map(item => {
          const active = adminLinkActive(item.href, location);
          return <Link key={item.href} to={item.href} onClick={onNavigate} aria-current={active ? 'page' : undefined} className={cn('nav-item', active && 'active')}><item.icon size={17} aria-hidden="true"/><span>{item.name}</span>{active && <ChevronRight size={14} aria-hidden="true"/>}</Link>;
        })}</section>)}
      </nav>
      <div className="shrink-0 border-t border-sidebar-border p-3"><div className="flex items-center gap-3 rounded-lg px-3 py-2"><Link to="/profile" onClick={onNavigate} aria-label="Your profile" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sidebar-accent text-sm font-medium text-sidebar-foreground">{initials}</Link><Link to="/profile" onClick={onNavigate} className="min-w-0 flex-1"><p className="truncate text-sm font-medium text-sidebar-primary-foreground">{user?.name || 'Admin'}</p><p className="text-xs text-sidebar-muted">Administrator</p></Link><button type="button" onClick={() => void logout()} aria-label="Logout" className="rounded-lg p-2 text-sidebar-foreground/60 hover:bg-sidebar-accent"><LogOut size={16} aria-hidden="true"/></button></div></div>
    </div>
  </aside>;
}
