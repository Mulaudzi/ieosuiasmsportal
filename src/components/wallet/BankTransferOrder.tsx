import { useState } from 'react';
import { api, handleApiError } from '@/lib/api';
import { PurchaseOrder, purchaseStatus, rand } from '@/lib/credit-purchases';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { toast } from '@/hooks/use-toast';
import { Copy, Upload } from 'lucide-react';
import { PaymentProofPreview } from './PaymentProofPreview';

export function BankTransferOrder({ order, onChange }: { order: PurchaseOrder; onChange: () => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const pending = ['awaiting_payment', 'awaiting_verification'].includes(order.status);
  const upload = async () => {
    if (!file) return;
    if (!['application/pdf', 'image/jpeg', 'image/png'].includes(file.type) || file.size > 5 * 1024 * 1024 || !file.size) {
      toast({ title: 'Choose a PDF, JPG or PNG up to 5 MB', variant: 'destructive' }); return;
    }
    setBusy(true);
    try {
      const data = new FormData(); data.append('proof', file);
      await api.upload(`/wallet/purchase-orders/${order.id}/proof`, data);
      setFile(null); onChange();
      toast({ title: 'Proof received', description: 'Credits will be added after your cleared payment is verified.' });
    } catch (e) { handleApiError(e); } finally { setBusy(false); }
  };
  const copy = async (value: string) => {
    try { await navigator.clipboard.writeText(value); toast({ title: 'Copied' }); } catch { toast({ title: 'Please select and copy the value below.' }); }
  };
  return <div className="space-y-4 rounded-xl border bg-card p-4 text-sm">
    <div className="flex flex-wrap justify-between gap-2"><strong className="break-all">{order.reference}</strong><span>{purchaseStatus(order.status)}</span></div>
    <p>{Number(order.credits).toLocaleString()} credits · Pay <strong>{rand(Number(order.total_cents))}</strong> · Saving {rand(Number(order.discount_cents))}</p>
    {order.cash_adjustment && <div className="rounded-lg border p-3 space-y-1"><p>SMS purchase value: {rand(Number(order.cash_adjustment.sms_total_cents))}</p><p>Cash balance applied: {rand(Number(order.cash_adjustment.applied_cents))}</p>{order.cash_adjustment.received_cents!==null&&<><p>Cleared payment: {rand(Number(order.cash_adjustment.received_cents))}</p><p>Rand balance change: {rand(Number(order.cash_adjustment.received_cents)-Number(order.cash_adjustment.sms_total_cents))}. Positive is cash credit; negative is owed. Your SMS quantity is unchanged.</p></>}</div>}
    {order.status === 'voided' && <p role="status">This unpaid request is closed. Do not transfer money using this reference. No credits were added or removed. If you already paid, contact <a className="underline" href={`mailto:${order.proof_email}`}>{order.proof_email}</a> with your reference and proof of payment.</p>}
    {pending && <>
      {order.expired && <p role="alert" className="text-destructive">This order is older than 72 hours. Contact {order.proof_email} before making a new transfer. If you already paid, upload your proof below so we can reconcile it.</p>}
      <dl className="space-y-2">{Object.entries({ Bank: order.bank_details?.bank_name, 'Account holder': order.bank_details?.account_name, 'Account number': order.bank_details?.account_number, 'Branch code': order.bank_details?.branch_code, Reference: order.reference }).map(([label, value]) => <div key={label} className="flex flex-wrap items-center justify-between gap-2"><dt className="text-muted-foreground">{label}</dt><dd className="flex min-w-0 items-center gap-2"><span className="break-all font-medium">{value}</span><Button variant="ghost" size="icon" aria-label={`Copy ${label}`} onClick={() => void copy(value || '')}><Copy className="h-4 w-4" /></Button></dd></div>)}</dl>
      <p>Use the order reference above. If your bank cannot accept it, use your account email and include the order reference with your proof.</p>
      <p className="text-muted-foreground">Pay within 72 hours of ordering. Select immediate payment in your banking app for faster clearance; your bank may charge a fee. Credits are added once cleared funds have been verified.</p>
      <label className="block space-y-2"><span>Upload proof of payment (PDF, JPG or PNG, up to 5 MB)</span><Input type="file" accept="application/pdf,image/jpeg,image/png" disabled={busy} onChange={e => setFile(e.target.files?.[0] || null)} /></label>
      <div className="flex flex-wrap items-center gap-3"><Button disabled={!file || busy} onClick={() => void upload()} className="gap-2"><Upload className="h-4 w-4" />{busy ? 'Uploading...' : 'Upload proof'}</Button><a className="break-all text-primary underline" href={`mailto:${order.proof_email}?subject=${encodeURIComponent(`Proof of payment ${order.reference}`)}&body=${encodeURIComponent(`Order reference: ${order.reference}\nAmount: ${rand(Number(order.total_cents))}\nPlease attach your proof of payment.`)}`}>Or email {order.proof_email}</a></div>
      <p className="text-xs text-muted-foreground">Uploading a receipt does not add credits automatically. Your order is saved here if you leave or refresh.</p>
    </>}
    {!!order.proofs.length && <div className="flex flex-wrap gap-3">{order.proofs.map((p, i) => <PaymentProofPreview key={p.id} proof={p} reference={order.reference} label={`View proof ${order.proofs.length - i}`} />)}</div>}
    {order.review_note && <p>{order.review_note}</p>}
  </div>;
}
