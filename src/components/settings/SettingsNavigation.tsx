import { Link } from 'react-router-dom';
import { Building2, ChevronRight, CircleHelp, House, Link2, MessageSquare, ShieldCheck, UserRound } from 'lucide-react';

export const settingsSections = [
  { id: 'overview', label: 'Overview', description: 'Find what you need', group: '', icon: House },
  { id: 'profile', label: 'My profile', description: 'Personal details & photo', group: 'YOUR ACCOUNT', icon: UserRound },
  { id: 'organization', label: 'Business details', description: 'Organization & branding', group: 'YOUR ACCOUNT', icon: Building2 },
  { id: 'security', label: 'Sign-in & security', description: 'Verification & password', group: 'YOUR ACCOUNT', icon: ShieldCheck },
  { id: 'compliance', label: 'SMS preferences', description: 'Opt-out text & guidance', group: 'MESSAGING', icon: MessageSquare },
  { id: 'integrations', label: 'Connected apps', description: 'API access & WhatsApp', group: 'MESSAGING', icon: Link2 },
  { id: 'get-started', label: 'Help & getting started', description: 'A little guidance', group: 'SUPPORT', icon: CircleHelp },
] as const;
export type SettingsSection = typeof settingsSections[number]['id'];
export type SettingsView = SettingsSection | 'api' | 'whatsapp';
export function settingsView(raw: string | null): SettingsView {
  return raw === 'api' || raw === 'whatsapp' || settingsSections.some(section => section.id === raw) ? raw as SettingsView : 'overview';
}
export function settingsSection(view: SettingsView): SettingsSection { return view === 'api' || view === 'whatsapp' ? 'integrations' : view; }
export const settingsTitles: Record<SettingsView, [string, string]> = {
  overview: ['Account overview', 'A clear starting point for your account, messaging and connections.'],
  profile: ['My profile', 'Your personal details and the photo shown in the portal.'],
  organization: ['Business details', 'Keep your organization information and branding up to date.'],
  security: ['Sign-in & security', 'Control how you sign in and manage your shared IEOSUIA password.'],
  compliance: ['SMS preferences', 'Choose the opt-out wording available when you compose an SMS.'],
  integrations: ['Connected apps', 'Connect other software without mixing it with your everyday settings.'],
  api: ['API connections', 'Let your application send SMS using this account’s credits.'],
  whatsapp: ['WhatsApp connection', 'Link your business number. Linking does not start a campaign.'],
  'get-started': ['Help & getting started', 'Quick pointers and useful places to go next.'],
};

export function SettingsNavigation({ view, hrefFor, onSelect }: { view: SettingsView; hrefFor: (view: SettingsView) => string; onSelect: (view: SettingsView) => void }) {
  const selected = settingsSection(view);
  return <>
    <div className="settings-mobile-navigation">
      <label htmlFor="settings-section">Settings section</label>
      <select id="settings-section" value={selected} onChange={event => onSelect(event.target.value as SettingsSection)}>
        {settingsSections.map(item => <option key={item.id} value={item.id}>{item.label}</option>)}
      </select>
    </div>
    <aside className="settings-navigation"><nav aria-label="Settings sections">
      {settingsSections.map((item, index) => <div key={item.id}>
        {item.group && item.group !== settingsSections[index - 1]?.group && <p className="settings-nav-group">{item.group}</p>}
        <Link to={hrefFor(item.id)} aria-label={item.label} aria-describedby={`settings-nav-${item.id}`} aria-current={selected === item.id ? 'page' : undefined} className="settings-nav-link">
          <item.icon size={18} aria-hidden="true"/><span><strong>{item.label}</strong><small id={`settings-nav-${item.id}`}>{item.description}</small></span><ChevronRight size={14} aria-hidden="true"/>
        </Link>
      </div>)}
    </nav><div className="settings-nav-support"><CircleHelp size={18} aria-hidden="true"/><p>Need a hand?<Link to="/support">Contact support</Link></p></div></aside>
  </>;
}
