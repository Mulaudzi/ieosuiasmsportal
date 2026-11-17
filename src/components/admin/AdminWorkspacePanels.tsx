import { createContext, useContext, type ReactNode } from 'react';
// The workspace uses button/select navigation, not ARIA tabs. Only the selected
// panel mounts, preserving the previous tools' lazy control initialization.
const ActivePanel = createContext('');
export function AdminPanels({ value, id, className, children }: { value: string; id?: string; className?: string; children: ReactNode; onValueChange?: (value: string) => void }) {
  return <ActivePanel.Provider value={value}><div id={id} className={className}>{children}</div></ActivePanel.Provider>;
}
export function AdminPanel({ value, className, children }: { value: string; className?: string; children: ReactNode }) {
  const active = useContext(ActivePanel);
  return value === active ? <section className={className} data-admin-panel={value}>{children}</section> : null;
}
