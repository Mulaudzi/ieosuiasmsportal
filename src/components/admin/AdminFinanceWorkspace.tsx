import { useEffect, useState, type ReactNode } from 'react';
import { ArrowLeftRight, Banknote, CreditCard, History, ShieldCheck } from 'lucide-react';
const sections = [
  { value: 'payments', label: 'Payment review', icon: CreditCard, detail: 'Review bank-transfer evidence and allocate credits.' },
  { value: 'cash', label: 'Rand balances', icon: Banknote, detail: 'Assign monetary value to a customer account.' },
  { value: 'transfers', label: 'Credit transfers', icon: ArrowLeftRight, detail: 'Move SMS credits with the existing verification controls.' },
  { value: 'history', label: 'Payment history', icon: History, detail: 'Review purchase records and financial totals.' },
  { value: 'security', label: 'Approval security', icon: ShieldCheck, detail: 'Manage verification for sensitive financial actions.' },
] as const;
type Section = typeof sections[number]['value'];
export function AdminFinanceWorkspace({ panels, reviewReference }: { panels: Record<Section, ReactNode>; reviewReference?: object }) {
  const [view, setView] = useState<Section>('payments');
  const [visited, setVisited] = useState<Section[]>(['payments']);
  const select = (value: Section) => { setVisited(previous => previous.includes(value) ? previous : [...previous, value]); setView(value); };
  useEffect(() => { if (reviewReference) setView('payments'); }, [reviewReference]);
  const selected = sections.find(section => section.value === view)!;
  return <section aria-label="Finance workspace" className="admin-finance-workspace">
    <nav aria-label="Finance sections">{sections.map(section => <button type="button" key={section.value} onClick={() => select(section.value)} aria-current={view === section.value ? 'page' : undefined}><section.icon size={18} aria-hidden="true"/>{section.label}</button>)}</nav>
    <div className="admin-finance-panel"><div className="admin-finance-heading"><selected.icon size={22} aria-hidden="true"/><div><h3>{selected.label}</h3><p>{selected.detail}</p></div></div>{visited.map(section => <div key={section} hidden={section !== view}>{panels[section]}</div>)}</div>
  </section>;
}
