import { Activity, ArrowUpRight, CalendarClock, CreditCard, FileClock, Inbox, LayoutDashboard, MessageSquare, Settings2, Users } from 'lucide-react';
import { Link } from 'react-router-dom';

export const operationSections = [
  { value: 'overview', label: 'Overview', icon: LayoutDashboard, description: 'Your platform at a glance' },
  { value: 'customers', label: 'Customers', icon: Users, description: 'Accounts, balances & activity' },
  { value: 'campaigns', label: 'Campaigns', icon: MessageSquare, description: 'Sending, delivery & timing' },
  { value: 'finance', label: 'Finance', icon: CreditCard, description: 'Payments, balances & transfers' },
  { value: 'monitoring', label: 'Service monitoring', icon: Activity, description: 'Workers, delivery & provider' },
] as const;
export const toolSections = [
  { value: 'users', label: 'User access', icon: Users, description: 'Roles & account access' },
  { value: 'scheduled', label: 'Scheduled messages', icon: CalendarClock, description: 'Schedule & processor controls' },
  { value: 'activity', label: 'Activity log', icon: FileClock, description: 'Recent administrative actions' },
  { value: 'contact-emails', label: 'Support inbox', icon: Inbox, description: 'Enquiries, replies & alerts' },
  { value: 'settings', label: 'Configuration', icon: Settings2, description: 'System email & notifications' },
  { value: 'health', label: 'System health', icon: Activity, description: 'Health checks & activity trends' },
] as const;
export function operationSection(value: string | null) { return operationSections.find(section => section.value === value)?.value ?? 'overview'; }

export function AdminWorkspaceNavigation({ kind, value, onChange }: { kind: 'operations' | 'tools'; value: string; onChange: (value: string) => void }) {
  const sections = kind === 'operations' ? operationSections : toolSections;
  return <div className="admin-workspace-navigation">
    <label className="admin-workspace-mobile">Workspace section<select value={value} onChange={event => onChange(event.target.value)}>{sections.map(section => <option key={section.value} value={section.value}>{section.label}</option>)}</select></label>
    <nav aria-label={kind === 'operations' ? 'Operations sections' : 'Administration sections'}>{sections.map(section => <button key={section.value} type="button" aria-current={value === section.value ? 'page' : undefined} onClick={() => onChange(section.value)}><section.icon size={17} aria-hidden="true"/><span>{section.label}</span></button>)}</nav>
  </div>;
}

export function AdminSectionHeading({ kind, value }: { kind: 'operations' | 'tools'; value: string }) {
  const section = (kind === 'operations' ? operationSections : toolSections).find(section => section.value === value)!;
  return <div className="admin-section-intro"><span className="admin-section-icon"><section.icon size={25} aria-hidden="true"/></span><div><p className="admin-eyebrow">{kind === 'operations' ? 'PLATFORM WORKSPACE' : 'ADMINISTRATION'}</p><h2>{section.label}</h2><p>{section.description}</p></div>{kind === 'tools' && <Link to="/guymhan">Platform overview<ArrowUpRight size={15} aria-hidden="true"/></Link>}</div>;
}

export function AdminOverviewLinks() {
  return <section className="admin-overview-destinations" aria-label="Operations workspaces">{operationSections.filter(section => section.value !== 'overview').map(section => <Link key={section.value} to={'/guymhan?tab=' + section.value}><span className="admin-destination-icon"><section.icon size={23} aria-hidden="true"/></span><span><strong>{section.label}</strong><small>{section.description}</small></span><ArrowUpRight size={17} aria-hidden="true"/></Link>)}</section>;
}
