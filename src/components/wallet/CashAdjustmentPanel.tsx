import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, ArrowRight, BadgeCheck, Banknote, Plus, RefreshCw, Search } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { cashAdjustmentsApi, CashCustomer, CashReview, RandReceipt } from '@/lib/cash-adjustments';
import { rand } from '@/lib/credit-purchases';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FinancePasswordField } from './FinancePasswordField';
import { useCreditApprovalStatus } from './CreditApprovalSettings';
import { toast } from '@/hooks/use-toast';

const message = (e: unknown) => e instanceof Error ? e.message : 'Unable to confirm the result. Check review status before starting another credit.';

/** Separate cash ledger: never grants SMS, invents a payment or edits historical receipts. */
export function CashAdjustmentPanel({ customer, onCredited }: { customer?: CashCustomer; onCredited?: () => void }) {
  const { user } = useAuth(); const client = useQueryClient(); const security = useCreditApprovalStatus();
  const [open, setOpen] = useState(false); const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<CashCustomer | undefined>(customer);
  const [search, setSearch] = useState(''); const [accounts, setAccounts] = useState<CashCustomer[]>([]);
  const [kind, setKind] = useState<'overpayment' | 'deposit'>('overpayment');
  const [order, setOrder] = useState(''); const [bank, setBank] = useState(''); const [amount, setAmount] = useState(''); const [reason, setReason] = useState('');
  const [verified, setVerified] = useState(false); const [confirmed, setConfirmed] = useState(false); const [password, setPassword] = useState('');
  const [quote, setQuote] = useState<CashReview>(); const [receipt, setReceipt] = useState<RandReceipt>();
  const [pending, setPending] = useState(''); const [uncertain, setUncertain] = useState(false); const [expired, setExpired] = useState(false);
  const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const [requestId, setRequestId] = useState('');
  const customerId = customer?.id, customerName = customer?.name, customerEmail = customer?.email;
  const key = `rand-credit-review:${user?.id ?? 'unknown'}:${customer?.id ?? 'finance'}`;
  const history = useQuery({ queryKey: ['rand-credit-history', customer?.id, page], retry: false, queryFn: () => cashAdjustmentsApi.history(customer ? String(customer.id) : undefined, page) });

  useEffect(() => {
    setSelected(customerId !== undefined && customerName !== undefined && customerEmail !== undefined ? { id: customerId, name: customerName, email: customerEmail } : undefined);
    setQuote(undefined); setReceipt(undefined); setPassword(''); setConfirmed(false); setOpen(false); setError(''); setPage(1);
    setSearch(''); setAccounts([]); setKind('overpayment'); setOrder(''); setBank(''); setAmount(''); setReason(''); setVerified(false); setExpired(false); setRequestId('');
    try { const id = sessionStorage.getItem(key) ?? ''; setPending(id); setUncertain(!!id); if (id) setOpen(true); }
    catch { setPending(''); setUncertain(false); }
  }, [key, customerId, customerName, customerEmail]);

  function remember(id: string) {
    // Persist only an opaque review ID. Never store passwords or bank/customer details.
    sessionStorage.setItem(key, id); setPending(id);
  }
  function forget() { try { sessionStorage.removeItem(key); } catch { /* server uniqueness still protects retries */ } setPending(''); setUncertain(false); }
  async function refresh() {
    await client.invalidateQueries({ queryKey: ['rand-credit-history'] });
    await client.invalidateQueries({ queryKey: ['purchase-options'] });
    await client.invalidateQueries({ queryKey: ['customer-cash-balance'] });
    onCredited?.();
  }
  async function completed(result: RandReceipt) {
    setReceipt(result); setQuote(undefined); forget(); setPassword(''); setConfirmed(false);
    toast({ title: 'Rand balance credited', description: `${rand(result.amount_cents)} recorded. SMS credits are unchanged.` });
    await refresh();
  }
  async function recover() {
    if (!pending) return; setBusy(true); setError(''); setPassword('');
    try {
      const result = await cashAdjustmentsApi.status(pending);
      if (customer && String(result.adjustment?.user_id ?? result.quote?.user_id) !== String(customer.id)) throw new Error('The saved review belongs to a different customer. Contact the finance owner.');
      if (result.status === 'completed' && result.adjustment) await completed(result.adjustment);
      else if (result.quote) { setQuote(result.quote); setSelected(result.quote.customer); setExpired(!!result.expired); setUncertain(false); }
    } catch (e) { setError(message(e)); setUncertain(true); } finally { setBusy(false); }
  }
  function reset() { forget(); setQuote(undefined); setReceipt(undefined); setPassword(''); setConfirmed(false); setExpired(false); setRequestId(''); setError(''); setAmount(''); setBank(''); setOrder(''); setReason(''); setVerified(false); }
  async function review() {
    if (!selected) return; setBusy(true); setError('');
    try {
      const id = requestId || crypto.randomUUID(); setRequestId(id);
      const result = await cashAdjustmentsApi.quote({ user_id: String(selected.id), source_kind: kind, order_reference: kind === 'deposit' ? '' : order, bank_reference: bank, cleared_amount: amount, reason, funds_verified: verified, request_id: id });
      remember(result.id); setQuote(result); setExpired(false); setConfirmed(false);
    } catch (e) { setError(message(e)); } finally { setBusy(false); }
  }
  async function commit() {
    if (!quote || !confirmed || !password) return; setBusy(true); setError('');
    try { remember(quote.id); setUncertain(true); await completed(await cashAdjustmentsApi.commit(quote.id, password)); }
    catch (e) { setError(message(e)); /* Always inspect status before a second financial action. */ }
    finally { setPassword(''); setBusy(false); }
  }
  const edit = () => { setRequestId(''); setError(''); };
  return <section className="min-w-0 space-y-4 rounded-xl border bg-card p-4 sm:p-5" aria-label="Rand balance credits">
    <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="flex items-center gap-2 font-semibold"><Banknote className="h-5 w-5 text-primary" />Rand balance credits</h2><p className="mt-1 max-w-2xl text-sm text-muted-foreground">Record a verified overpayment or an additional cleared deposit, even when you are not reviewing an order. Money is kept separately from SMS credits.</p></div><Button variant="outline" disabled={busy} onClick={() => { setPassword(''); setConfirmed(false); setOpen(!open); }}><Plus className="mr-2 h-4 w-4" />{open ? 'Hide credit form' : 'Add rand credit'}</Button></div>
    {history.data?.cash_balance && <div className="rounded-lg border bg-muted/30 p-3 text-sm">Rand balance: <strong>{rand(history.data.cash_balance.balance_cents)}</strong><span className="ml-3 text-muted-foreground">Available {rand(history.data.cash_balance.available_cents)} · Reserved {rand(history.data.cash_balance.reserved_cents)}</span></div>}
    {history.data?.total_credited_cents !== undefined && <p className="text-sm text-muted-foreground">Standalone credits recorded: <strong className="text-foreground">{rand(history.data.total_credited_cents)}</strong>. These additional cleared funds are separate from the original purchase-payment totals.</p>}
    {history.error && <p role="alert" className="text-sm">Could not load rand-credit history. <Button variant="link" onClick={() => void history.refetch()}>Retry</Button></p>}
    {history.data && !history.data.enabled && <p role="status" className="rounded-lg border p-3 text-sm">Standalone rand credits are not active. The scoped finance database setup and activation are required. Existing balances are preserved.</p>}
    {open && <div className="space-y-4 border-t pt-4">
      {error && <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm">{error}</p>}
      {uncertain ? <div className="space-y-3 rounded-lg border border-amber-300 p-4"><h3 className="font-medium">Check the saved review first</h3><p className="text-sm">The result is not confirmed on this screen. Do not record the deposit again. Checking status does not move money.</p><Button disabled={busy} onClick={() => void recover()}><RefreshCw className="mr-2 h-4 w-4" />{busy ? 'Checking...' : 'Check review status'}</Button></div>
        : receipt ? <div role="status" className="space-y-2 rounded-lg border border-green-600/30 bg-green-600/5 p-4"><h3 className="flex items-center gap-2 font-semibold"><BadgeCheck className="h-5 w-5 text-green-600" />{rand(receipt.amount_cents)} credited</h3><p className="break-all text-sm">{receipt.reference}</p><p className="text-sm">Rand balance after this entry: {rand(receipt.balance_after_cents)}. No SMS credits were added.</p><Button variant="outline" onClick={reset}>Record another deposit</Button></div>
        : quote ? <div className="space-y-4"><h3 className="font-semibold">Review rand credit</h3><p className="break-words text-sm">{quote.customer.name} · {quote.customer.email}</p><dl className="grid gap-3 rounded-lg border bg-muted/20 p-4 sm:grid-cols-3"><div><dt className="text-xs text-muted-foreground">Total cleared funds</dt><dd className="font-semibold">{rand(quote.cleared_cents)}</dd></div><div><dt className="text-xs text-muted-foreground">Already recorded on payment</dt><dd className="font-semibold">{rand(quote.recorded_cents)}</dd></div><div><dt className="text-xs text-muted-foreground">New rand credit</dt><dd className="text-xl font-bold text-primary">{rand(quote.amount_cents)}</dd></div></dl><p className="break-all text-sm">Bank transaction: {quote.bank_reference}{quote.order_reference && ` · Order: ${quote.order_reference}`}</p><p className="whitespace-pre-wrap break-words text-sm">{quote.reason}</p><p className="text-sm text-muted-foreground">Existing rand balance: {rand(quote.cash_balance.balance_cents)}. Reserved money stays reserved. SMS credits and the original receipt are unchanged.</p>
          {expired ? <p role="alert" className="text-sm">This review expired. Go back and review the amount again.</p> : <><FinancePasswordField value={password} onChange={setPassword} disabled={busy} /><label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={confirmed} disabled={busy} onChange={e => setConfirmed(e.target.checked)} />I authorize this exact rand credit after checking cleared funds and the customer.</label></>}
          {!security.data?.configured && <p className="text-sm">The finance owner must configure the master password before a credit can be confirmed.</p>}
          <div className="sticky bottom-0 z-10 flex flex-wrap justify-between gap-3 border-t bg-card py-3"><Button variant="outline" disabled={busy} onClick={() => { forget(); setQuote(undefined); setPassword(''); setConfirmed(false); setRequestId(''); }}><ArrowLeft className="mr-2 h-4 w-4" />Back</Button><Button disabled={busy || expired || !history.data?.enabled || !security.data?.configured || !password || !confirmed} onClick={() => void commit()}>{busy ? 'Confirming...' : `Credit ${rand(quote.amount_cents)}`}<BadgeCheck className="ml-2 h-4 w-4" /></Button></div>
        </div> : <div className="space-y-4">
          {!customer && <div className="space-y-2"><label className="text-sm font-medium" htmlFor="rand-customer-search">Customer</label><div className="flex gap-2"><Input id="rand-customer-search" placeholder="Search name or email" value={search} disabled={busy} onChange={e => setSearch(e.target.value)} /><Button variant="outline" disabled={busy || search.trim().length < 2 || !history.data?.enabled} aria-label="Find customer for rand credit" onClick={async () => { setBusy(true); setError(''); try { setAccounts(await cashAdjustmentsApi.accounts(search)); } catch(e) { setError(message(e)); } finally { setBusy(false); } }}><Search className="h-4 w-4" /></Button></div>{accounts.map(a => <Button key={a.id} className="h-auto w-full justify-start whitespace-normal break-all text-left" variant={String(selected?.id) === String(a.id) ? 'secondary' : 'outline'} onClick={() => { setSelected(a); edit(); }}>{a.name} · {a.email}</Button>)}</div>}
          {selected && <p className="break-all rounded-lg bg-primary/5 p-3 text-sm">Credit to: <strong>{selected.name}</strong> · {selected.email}</p>}
          <label className="block space-y-1 text-sm font-medium">Source<select className="block w-full rounded-md border bg-background p-2" value={kind} disabled={busy} onChange={e => { setKind(e.target.value as typeof kind); edit(); }}><option value="overpayment">Overpayment on a completed EFT order</option><option value="deposit">Additional / unallocated cleared deposit</option></select></label>
          <p className="text-xs text-muted-foreground">{kind === 'overpayment' ? 'Enter the total that actually cleared. Only the amount above the original recorded payment will be added. Money already credited cannot be added again.' : 'Use only for a separate bank transaction not already used for an SMS purchase. Enter its full cleared amount.'}</p>
          <div className="grid gap-3 sm:grid-cols-2">{kind === 'overpayment' && <label className="space-y-1 text-sm">Completed EFT order reference<Input value={order} maxLength={100} disabled={busy} onChange={e => { setOrder(e.target.value); edit(); }} /></label>}<label className="space-y-1 text-sm">Total cleared amount (ZAR)<Input inputMode="decimal" placeholder="125.50" value={amount} disabled={busy} onChange={e => { setAmount(e.target.value); edit(); }} /></label><label className="space-y-1 text-sm">Unique bank transaction ID<Input value={bank} maxLength={120} disabled={busy} onChange={e => { setBank(e.target.value); edit(); }} /></label></div>
          <label className="block space-y-1 text-sm">Reason / reconciliation note<textarea className="block min-h-20 w-full rounded-md border bg-background p-2" maxLength={1000} value={reason} disabled={busy} onChange={e => { setReason(e.target.value); edit(); }} /></label><label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={verified} disabled={busy} onChange={e => { setVerified(e.target.checked); edit(); }} />I checked the bank account, confirmed cleared funds and checked that these funds have not already been credited.</label>
          <div className="sticky bottom-0 z-10 flex justify-end border-t bg-card py-3"><Button disabled={busy || !history.data?.enabled || !selected || !verified || !amount || !bank.trim() || reason.trim().length < 10 || (kind === 'overpayment' && !order.trim())} onClick={() => void review()}>{busy ? 'Checking...' : 'Review rand credit'}<ArrowRight className="ml-2 h-4 w-4" /></Button></div>
        </div>}
    </div>}
    <div className="space-y-2 border-t pt-3"><div className="flex items-center justify-between"><h3 className="text-sm font-semibold">Recorded rand credits</h3><Button variant="ghost" size="sm" disabled={busy} onClick={() => void history.refetch()}><RefreshCw className="mr-1 h-3.5 w-3.5" />Refresh</Button></div>{history.isLoading && <p className="text-sm text-muted-foreground">Checking ledger...</p>}{history.data?.adjustments.map(a => <div key={a.id} className="flex flex-wrap justify-between gap-2 rounded-lg border p-3 text-sm"><div className="min-w-0"><p className="break-all font-medium">{a.customer_name} · {a.customer_email}</p><p className="break-all text-xs text-muted-foreground">{a.reference} · {a.created_at}</p><p className="whitespace-pre-wrap break-words">{a.reason}</p></div><strong>{rand(a.amount_cents)}</strong></div>)}{history.data && !history.data.total && <p className="text-sm text-muted-foreground">No standalone rand credits recorded.</p>}{history.data && history.data.total > 20 && <div className="flex justify-between"><Button variant="outline" disabled={page === 1} onClick={() => setPage(page - 1)}>Previous</Button><span className="text-sm">Page {page}</span><Button variant="outline" disabled={page * 20 >= history.data.total} onClick={() => setPage(page + 1)}>Next</Button></div>}</div>
  </section>;
}
