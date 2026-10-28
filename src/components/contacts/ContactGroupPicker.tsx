import { useId, useState } from 'react';
import { Check, Search, Users } from 'lucide-react';
import { Input } from '@/components/ui/input';

export interface PickableContactGroup { id: string; name: string; contact_count?: number; description?: string; is_virtual?: boolean }
interface Props {
  groups: PickableContactGroup[];
  selectedIds: string[];
  onChange: (ids: string[]) => void;
  multiple?: boolean;
  disabled?: boolean;
  disableEmpty?: boolean;
  hideEmptyUncategorized?: boolean;
  label?: string;
}

export function ContactGroupPicker({ groups, selectedIds, onChange, multiple = false, disabled = false, disableEmpty = false, hideEmptyUncategorized = false, label = 'Contact groups' }: Props) {
  const id = useId();
  const [search, setSearch] = useState('');
  const real = groups.filter(group => !group.is_virtual && /^[1-9]\d*$/.test(String(group.id)) && !(hideEmptyUncategorized && group.name.trim().toLowerCase() === 'uncategorized' && Number(group.contact_count) === 0));
  const matches = real.filter(group => `${group.name} ${group.description ?? ''}`.toLowerCase().includes(search.trim().toLowerCase()));
  return <section className="min-w-0 space-y-2" aria-label={label}>
    <label htmlFor={id} className="text-sm font-medium">Search groups</label>
    <div className="relative"><Search className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" aria-hidden="true" /><Input id={id} aria-label={`Search groups: ${label}`} className="pl-9" placeholder="Type a group name..." value={search} onChange={event => setSearch(event.target.value)} disabled={disabled} /></div>
    <div className="grid max-h-80 grid-cols-1 gap-2 overflow-y-auto sm:grid-cols-2">
      {matches.slice(0, 100).map(group => {
        const selected = selectedIds.includes(String(group.id));
        const empty = disableEmpty && group.contact_count === 0;
        return <button key={group.id} type="button" aria-pressed={selected} disabled={disabled || empty} onClick={() => onChange(multiple ? selected ? selectedIds.filter(value => value !== String(group.id)) : [...selectedIds, String(group.id)] : [String(group.id)])} className={`flex min-h-16 min-w-0 items-center gap-3 rounded-lg border p-3 text-left transition-colors disabled:opacity-50 ${selected ? 'border-primary bg-primary/10' : 'hover:bg-muted/50'}`}>
          {selected ? <Check className="h-5 w-5 shrink-0 text-primary" /> : <Users className="h-5 w-5 shrink-0 text-muted-foreground" />}
          <span className="min-w-0"><span className="block break-words text-sm font-medium">{group.name}</span><span className="block text-xs text-muted-foreground">{group.contact_count === undefined ? 'Contact group' : `${group.contact_count.toLocaleString()} contacts`}{empty ? ' (empty)' : ''}</span></span>
        </button>;
      })}
    </div>
    {!matches.length && <p className="text-sm text-muted-foreground">{real.length ? 'No matching groups. Try another name.' : 'No groups yet. Create a group to organise your contacts.'}</p>}
    {matches.length > 100 && <p className="text-xs text-muted-foreground">Showing the first 100 matches. Search to narrow the list; your selections are kept.</p>}
  </section>;
}
