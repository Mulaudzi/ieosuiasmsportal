import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api, handleApiError } from '@/lib/api';
import { guardedReadPolling, isAccessBlocked } from '@/lib/api-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { toast } from '@/hooks/use-toast';

export interface CreditApprovalStatus { available: boolean; configured: boolean; is_owner: boolean; owner_email: string; updated_at: string | null }
export function useCreditApprovalStatus() {
  return useQuery({ queryKey: ['credit-approval-security'], ...guardedReadPolling, retry: (count, error) => !isAccessBlocked(error) && count < 1, queryFn: async () => {
    const r = await api.get<CreditApprovalStatus>('/admin/operations/credit-approval-security');
    return (r.data ?? r) as CreditApprovalStatus;
  } });
}

export function CreditApprovalSettings() {
  const { data, error, refetch } = useCreditApprovalStatus(); const client = useQueryClient();
  const [open, setOpen] = useState(false); const [current, setCurrent] = useState('');
  const [next, setNext] = useState(''); const [confirmation, setConfirmation] = useState(''); const [busy, setBusy] = useState(false);
  const clear = () => { setCurrent(''); setNext(''); setConfirmation(''); };
  const bytes = new TextEncoder().encode(next).length;
  const save = async (e: React.FormEvent) => {
    e.preventDefault(); setBusy(true);
    try {
      await api.put('/admin/operations/credit-approval-security', { current_password: current, new_password: next, confirm_password: confirmation });
      clear(); setOpen(false); await client.invalidateQueries({ queryKey: ['credit-approval-security'] });
      toast({ title: 'Master password saved', description: 'Manual credit approvals now require this password.' });
    } catch (e) { clear(); handleApiError(e); } finally { setBusy(false); }
  };
  return <section className="scroll-mt-24 space-y-3 rounded-xl border bg-card p-4" aria-label="Credit approval security">
    <h2 className="font-semibold">Credit approval security</h2>
    <p className="text-sm text-muted-foreground">Manual bank-transfer credits require the master password on every approval, including approvals by the owner. Confirmed online payments remain automatic.</p>
    {error ? <p role="alert">Could not check approval security. {isAccessBlocked(error) && error.message} <Button variant="link" onClick={() => void refetch()}>Retry</Button></p> : !data ? <p role="status">Checking approval security...</p> : <>
      <p className="text-sm" role="status">{!data.available ? 'Security migration required. Manual credit approval is blocked.' : data.configured ? 'Master password configured.' : 'Manual credit approval is blocked until the owner sets a master password.'}</p>
      {!data.is_owner && <p className="text-sm text-muted-foreground">Only the verified owner account {data.owner_email} can manage this password.</p>}
      {data.is_owner && data.available && <>
        <Button variant="outline" disabled={busy} onClick={() => { clear(); setOpen(v => !v); }}>{open ? 'Cancel password change' : data.configured ? 'Change master password' : 'Set master password'}</Button>
        {open && <form onSubmit={save} className="max-w-lg space-y-3">
          {data.configured && <label className="block text-sm">Current master password<Input type="password" autoComplete="off" value={current} disabled={busy} onChange={e => setCurrent(e.target.value)} /></label>}
          <label className="block text-sm">New master password<Input type="password" autoComplete="new-password" maxLength={72} value={next} disabled={busy} onChange={e => setNext(e.target.value)} /></label>
          <label className="block text-sm">Confirm new master password<Input type="password" autoComplete="new-password" maxLength={72} value={confirmation} disabled={busy} onChange={e => setConfirmation(e.target.value)} /></label>
          <p className="text-xs text-muted-foreground">Use a unique passphrase of at least 14 characters (maximum 72 bytes). This is separate from your login password. To change it later, you must enter the current master password. It cannot be viewed or recovered from the portal.</p>
          <Button type="submit" disabled={busy || Array.from(next).length < 14 || bytes > 72 || !next.trim() || next !== confirmation || (data.configured && !current)}>{busy ? 'Saving...' : 'Save master password'}</Button>
        </form>}
      </>}
    </>}
  </section>;
}
