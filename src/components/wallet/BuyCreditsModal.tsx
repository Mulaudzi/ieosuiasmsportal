import { useEffect, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { api, handleApiError } from '@/lib/api';
import { creditPricing, creditQuote, PaymentMethod, PurchaseOptions, PurchaseOrder, CashBalance, CashPurchaseQuote, rand } from '@/lib/credit-purchases';
import { BankTransferOrder } from './BankTransferOrder';
import { useAuth } from '@/hooks/useAuth';
import { SaveDraftButton } from '@/components/drafts/SaveDraftButton';
import { deleteWorkDraft, draftReference, DraftReference, readPurchaseRecovery, WorkDraft } from '@/lib/work-drafts';
import { toast } from '@/hooks/use-toast';
import { CreditPurchasePolicy } from '@/components/pricing/CreditPurchasePolicy';

interface Props { open: boolean; onOpenChange: (open: boolean) => void; onPurchaseComplete?: () => void; draft?: WorkDraft<'purchase'>; onStartNew?: () => void }
export function BuyCreditsModal(props: Props) {
  const {user} = useAuth();
  if (!props.open || !user) return null;
  return <PurchaseComposer key={`${user.id}:${props.draft?.id ?? 'local'}`} {...props} userId={String(user.id)}/>;
}
function PurchaseComposer({ open, onOpenChange, onPurchaseComplete, draft, onStartNew, userId }: Props & {userId: string}) {
  const [recovery] = useState(() => {
    const local = readPurchaseRecovery(userId);
    if (!draft) return local;
    return local?.reference?.id === draft.id ? local : {payload: draft.payload, reference: draftReference(draft)};
  });
  const [credits, setCredits] = useState(recovery?.payload.credits ?? 100); const [step, setStep] = useState<'order' | 'payment'>(recovery?.payload.step ?? 'order');
  const [preferredMethod, setMethod] = useState<PaymentMethod>(recovery?.payload.method ?? 'payos'); const [working, setBusy] = useState(false);
  const directOnly = credits > creditPricing.online_maximum;
  // Enforce the current policy even when restoring an older online-payment draft.
  const method: PaymentMethod = directOnly ? 'eft' : preferredMethod;
  const [draftSaving, setDraftSaving] = useState(false), [savedDraft, setSavedDraft] = useState<DraftReference | null>(recovery?.reference ?? null);
  const [storageFailed, setStorageFailed] = useState(false);const finished = useRef(false);const busy = working || draftSaving;
  const [order, setOrder] = useState<PurchaseOrder | null>(null); const requestKey = useRef(recovery?.payload.requestKey ?? crypto.randomUUID());
  const [feedback, setFeedback] = useState(''); const client = useQueryClient();
  const { data: options, error, refetch } = useQuery({ queryKey: ['purchase-options',userId], enabled: open, staleTime: 0, retry: 1, queryFn: async () => {
    const r = await api.get<PurchaseOptions>('/wallet/purchase-options'); return (r.data ?? r) as PurchaseOptions;
  } });
  useEffect(() => {
    if (finished.current || order) return;
    try {
      localStorage.setItem(`sms-purchase-draft:v1:${userId}`, JSON.stringify({payload: {version: 1, credits, step, method, requestKey: requestKey.current}, ...(savedDraft ? {reference: savedDraft} : {})}));setStorageFailed(false);
    } catch {setStorageFailed(true);}
  }, [userId, credits, step, method, savedDraft, order]);
  const [balanceChoice, setBalanceChoice] = useState<'keep_credits' | 'keep_payment' | null>(null);
  const quote = creditQuote(credits, method); const bankQuote = creditQuote(credits, 'eft');
  const cashEnabled = options?.cash_balance?.enabled === true;
  const cashQuery=useQuery({queryKey:['cash-purchase-quote',userId,credits,method,balanceChoice],enabled:open && !order && cashEnabled && !!quote,retry:false,refetchOnWindowFocus:true,queryFn:async()=>{const r=await api.post<{quote:CashPurchaseQuote;cash_balance:CashBalance}>('/wallet/purchase-quote',{credits,payment_method:method,balance_choice:balanceChoice??'keep_credits'});return (r.data??r) as {quote:CashPurchaseQuote;cash_balance:CashBalance};}});
  const reviewedQuote=cashEnabled ? cashQuery.data?.quote : quote;
  const cashBalance=cashQuery.data?.cash_balance ?? options?.cash_balance;
  const requiresChoice=Boolean(cashBalance?.available_cents);
  const cashReady=!cashEnabled || (!cashQuery.isFetching && !cashQuery.isError && !!reviewedQuote && (!requiresChoice || balanceChoice!==null));
  const submitting=useRef(false);
  const nextDeal = quote && creditPricing.tiers.filter(t => t.min > credits).map(t => creditQuote(t.min, method)).find(q => q && q.total_cents <= quote.total_cents);
  const change = (value: number) => { if (value !== credits) {requestKey.current = crypto.randomUUID();setCredits(value);} };
  const setPayment = (value: PaymentMethod) => { if (value !== method) {requestKey.current = crypto.randomUUID();setMethod(value);} };
  const refresh = () => { void client.invalidateQueries({ queryKey: ['credit-orders'] }); onPurchaseComplete?.(); };
  const startNew = () => {
    if (!window.confirm('Start a new purchase? Any account-saved draft will remain in Saved purchase drafts.')) return;
    try {localStorage.removeItem(`sms-purchase-draft:v1:${userId}`);} catch {/* Saving again will show a storage warning. */}
    setCredits(100);setStep('order');setMethod('payos');setSavedDraft(null);requestKey.current = crypto.randomUUID();onStartNew?.();
  };
  const purchase = async () => {
    if (submitting.current || !quote || !reviewedQuote || !cashReady || !options?.purchases_available || (method === 'eft' && !options.direct_transfer_available)) return; submitting.current=true;setBusy(true);
    try {
      const r = await api.post<{ order: PurchaseOrder; payment_url?: string; instructions_email_status?: string }>('/wallet/buy', { ...quote, quoted_total_cents: reviewedQuote.total_cents, quoted_credits: reviewedQuote.credits, ...(cashEnabled ? {balance_choice:balanceChoice??'keep_credits',quoted_cash_applied_cents:cashQuery.data!.quote.cash_applied_cents}:{}), idempotency_key: requestKey.current });
      const result = (r.data ?? r) as { order: PurchaseOrder; payment_url?: string; instructions_email_status?: string };
      finished.current = true;
      try {localStorage.removeItem(`sms-purchase-draft:v1:${userId}`);} catch {/* Never retry a payment just because browser cleanup failed. */}
      if (savedDraft) {
        try {await deleteWorkDraft(savedDraft);void client.invalidateQueries({queryKey: ['work-drafts']});}
        catch {toast({title: 'Purchase started; draft cleanup pending', description: 'Your draft was edited elsewhere or could not be removed. Do not create another payment for this order.'});}
      }
      if (method === 'payos' && result.payment_url) { window.location.assign(result.payment_url); return; }
      setOrder(result.order); refresh();
      setFeedback('Your bank instructions are saved here and queued for email. Find this order again in Wallet if you leave or refresh.');
    } catch (e) { handleApiError(e);if(cashEnabled)void cashQuery.refetch(); } finally { submitting.current=false;setBusy(false); }
  };
  const refreshOrder = async () => {
    const r = await api.get<{ orders: PurchaseOrder[] }>('/wallet/purchase-orders');
    const result = (r.data ?? r) as { orders: PurchaseOrder[] };
    setOrder(result.orders.find(o => Number(o.id) === Number(order?.id)) || order); refresh();
  };
  return <Dialog open={open} onOpenChange={v => { if (!busy) { onOpenChange(v); if (!v) refresh(); } }}><DialogContent className="max-h-[90dvh] max-w-2xl overflow-y-auto">
    <DialogHeader><DialogTitle>Buy SMS Credits</DialogTitle><DialogDescription>Buy 30 or more credits. Larger purchases get a lower price per credit. One credit sends one billable SMS segment.</DialogDescription></DialogHeader>
    {order ? <><p role="status" className="text-sm">{feedback}</p><BankTransferOrder order={order} onChange={() => void refreshOrder().catch(handleApiError)} /><Button onClick={() => onOpenChange(false)}>Back to Wallet</Button></> : <div className="space-y-4">
      <div className="space-y-2 rounded-lg border p-3 text-sm"><p role="status">{storageFailed ? 'Browser recovery could not be saved. Save a draft to your account before leaving.' : recovery ? 'Your purchase draft has been restored. Review the current price before continuing. Nothing has been paid.' : 'Your choices are recovered on this browser if you close or refresh. Save a draft to keep them in your account.'}</p>{savedDraft && <p className="text-muted-foreground">Editing saved draft: <strong>{savedDraft.title}</strong>. Save again to update it.</p>}<div className="flex flex-wrap gap-2"><SaveDraftButton key={savedDraft?.id ?? 'new'} kind="purchase" payload={{version: 1, credits, step, method, requestKey: requestKey.current}} reference={savedDraft} defaultTitle={credits > 0 ? `${credits.toLocaleString()} SMS credits` : 'Credit purchase'} disabled={working || !Number.isSafeInteger(credits) || credits < 0 || credits > 1000000} onSavingChange={setDraftSaving} onSaved={setSavedDraft} onExit={() => onOpenChange(false)}/><Button variant="ghost" size="sm" disabled={busy} onClick={startNew}>Start a new purchase</Button></div></div>
      {draft && savedDraft && draft.version > savedDraft.version && <p role="alert" className="text-sm">This draft changed elsewhere. Your browser choices are kept and cannot overwrite the newer account copy. <Button variant="link" disabled={busy} onClick={() => {if(window.confirm('Replace your browser choices with the latest account copy?')){try{localStorage.removeItem(`sms-purchase-draft:v1:${userId}`);window.location.reload();}catch{setStorageFailed(true);}}}}>Load latest account copy</Button></p>}
      {step === 'order' ? <>
        <label className="block space-y-2"><span className="text-sm font-medium">Number of SMS credits</span><Input type="number" min={creditPricing.minimum} max={creditPricing.maximum} step={1} value={credits || ''} onChange={e => change(Number(e.target.value))} /></label>
        {!quote && credits <= creditPricing.maximum && <p role="alert" className="text-sm text-destructive">Choose a whole number between 30 and 500,000 credits. The minimum standard purchase is R10.50.</p>}
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">{[30, 100, 500, 1000].map(n => <Button key={n} variant={credits === n ? 'default' : 'outline'} className="h-auto flex-col py-3" onClick={() => change(n)}>{n.toLocaleString()} credits<span className="text-xs font-normal">{rand(creditQuote(n)!.total_cents)}</span></Button>)}</div>
        <details className="rounded-lg border p-3 text-sm"><summary className="cursor-pointer">View volume pricing</summary><ul className="mt-3 space-y-1">{creditPricing.tiers.map(t => <li key={t.min} className="flex justify-between gap-3"><span>{Math.max(30, t.min).toLocaleString()} to {t.max.toLocaleString()} credits</span><strong>{rand(t.cents)} each</strong></li>)}</ul><p className="mt-3 text-muted-foreground">The price for your range applies to every credit in this purchase.</p></details>
      </> : <>
        <h3 className="font-medium">Choose how to pay</h3>
        {options?.direct_transfer_available && bankQuote && <button type="button" disabled={busy} aria-pressed={method === 'eft'} onClick={() => setPayment('eft')} className={`w-full rounded-xl border p-4 text-left ${method === 'eft' ? 'border-primary bg-primary/5' : ''}`}><strong>Pay directly to our FNB account and save {rand(bankQuote.discount_cents)}</strong><span className="mt-1 block text-sm">Pay {rand(bankQuote.total_cents)} for the same {credits.toLocaleString()} credits. Upload or email your proof. Credits are added after cleared funds are verified.</span></button>}
        {!directOnly && <button type="button" disabled={busy} aria-pressed={method === 'payos'} onClick={() => setPayment('payos')} className={`w-full rounded-xl border p-4 text-left ${method === 'payos' ? 'border-primary bg-primary/5' : ''}`}><strong>Pay online</strong><span className="mt-1 block text-sm">Use the card or Instant EFT options offered by PayOS checkout. Credits are added automatically after confirmed payment.</span></button>}
        <p className="text-sm text-muted-foreground">Direct bank transfers are reviewed before credits become available.{!directOnly && ' For urgent campaigns, online checkout may be quicker.'}</p>
      </>}
      {cashEnabled && <section aria-label="Cash balance adjustment" className="space-y-3 rounded-lg border p-4 text-sm"><h3 className="font-semibold">Your rand balance (separate from SMS credits)</h3><p>{Number(cashBalance?.balance_cents ?? 0)>=0?'Cash credit':'Amount owed'}: <strong>{rand(Math.abs(Number(cashBalance?.balance_cents ?? 0)))}</strong></p>{Boolean(cashBalance?.reserved_cents)&&<p>{rand(Math.abs(Number(cashBalance?.reserved_cents)))} is already allocated to an open order and cannot be applied twice. Close or pay that order first.</p>}{requiresChoice&&<><p>{Number(cashBalance?.available_cents)>0?'Use your overpayment credit on this purchase.':'Your earlier shortfall will be settled as part of this purchase.'} Choose what stays the same:</p><label className="flex items-start gap-2"><input type="radio" name="cash-choice" checked={balanceChoice==='keep_credits'} disabled={busy} onChange={()=>{setBalanceChoice('keep_credits');requestKey.current=crypto.randomUUID();}}/><span>Keep my {credits.toLocaleString()} SMS credits; adjust what I pay.</span></label><label className="flex items-start gap-2"><input type="radio" name="cash-choice" checked={balanceChoice==='keep_payment'} disabled={busy} onChange={()=>{setBalanceChoice('keep_payment');requestKey.current=crypto.randomUUID();}}/><span>Keep my {quote?rand(quote.total_cents):''} payment budget; adjust my SMS quantity.</span></label><p>No change is applied until you choose and confirm this order. Any rounding remainder stays in your rand balance.</p></>}{cashQuery.isFetching&&<p role="status">Updating your quote...</p>}{cashQuery.isError&&<p role="alert">Your balance quote could not be calculated. Increase the budget if it cannot cover your shortfall and the minimum purchase. <Button variant="link" onClick={()=>void cashQuery.refetch()}>Retry quote</Button></p>}{reviewedQuote&&<p className="font-semibold">You will receive {reviewedQuote.credits.toLocaleString()} SMS credits and pay {rand(reviewedQuote.total_cents)}.</p>}</section>}
      <CreditPurchasePolicy/>
      {directOnly && options && !options.direct_transfer_available && <p role="alert">Direct bank transfers are currently unavailable. Please contact sales using the email above. Online checkout is not available for this quantity.</p>}
      {quote && <div className="space-y-2 rounded-xl bg-muted/50 p-4 text-sm"><p className="flex justify-between"><span>SMS credits</span><strong>{(reviewedQuote?.credits??credits).toLocaleString()}</strong></p><p className="flex justify-between"><span>Price per credit</span><strong>{rand(reviewedQuote?.unit_cents??quote.unit_cents)}</strong></p><p className="flex justify-between"><span>Standard price</span><strong>{rand(reviewedQuote?.subtotal_cents??quote.subtotal_cents)}</strong></p>{quote.discount_cents > 0 && <p className="flex justify-between text-success"><span>Bank transfer saving</span><strong>-{rand(reviewedQuote?.discount_cents??quote.discount_cents)}</strong></p>}<p className="flex justify-between border-t pt-2 text-lg font-semibold"><span>Total to pay</span><span>{rand(reviewedQuote?.total_cents??quote.total_cents)}</span></p><p className="text-xs text-muted-foreground">We do not charge VAT.</p></div>}
      {nextDeal && <p className="text-sm">Better value: <button className="text-primary underline" onClick={() => change(nextDeal.credits)}>{nextDeal.credits.toLocaleString()} credits cost {rand(nextDeal.total_cents)}</button> with the next volume discount.</p>}
      {error && <p role="alert" className="text-sm text-destructive">Could not load payment options. <Button variant="link" onClick={() => void refetch()}>Retry</Button></p>}
      {options && !options.purchases_available && <p role="status">Credit purchases are being updated. Please try again shortly.</p>}
      <div className="flex flex-wrap gap-3">{step === 'payment' && <Button variant="outline" disabled={busy} onClick={() => { setStep('order'); setPayment('payos'); }}>Back</Button>}<Button disabled={!quote || busy || (step==='payment' && !cashReady) || !options?.purchases_available || (method === 'eft' && !options.direct_transfer_available)} onClick={() => step === 'order' ? setStep('payment') : void purchase()}>{busy ? 'Saving purchase...' : step === 'order' ? 'Choose payment method' : method === 'eft' ? 'Create bank transfer order' : 'Continue to secure checkout'}</Button></div>
    </div>}
  </DialogContent></Dialog>;
}
