import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { guardedReadPolling, isAccessBlocked } from '@/lib/api-error';
import { rand } from '@/lib/credit-purchases';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

interface Payment { id: number; customer_name: string; customer_email: string; merchant_reference: string; gateway: string; status: string; amount: number; currency: string; credits_added: number; credits_ordered: number | null; order_id: number | null; created_at: string }
interface History { payments: Payment[]; pagination: { page: number; last_page: number; total: number } }
export function CreditPaymentsHistory({ onViewTransfer }: { onViewTransfer: (reference: string) => void }) {
  const [page, setPage] = useState(1); const [search, setSearch] = useState(''); const [status, setStatus] = useState('all'); const [gateway, setGateway] = useState('all');
  const { data, error, isLoading, refetch } = useQuery({ queryKey: ['credit-payment-history', page, search, status, gateway], ...guardedReadPolling, queryFn: async () => {
    const r = await api.get<History>('/admin/operations/payments', { page: String(page), per_page: '20', search, status, gateway }); return (r.data ?? r) as History;
  } });
  return <section className="space-y-3 rounded-xl border bg-card p-4" aria-label="Credit payment history">
    <h2 className="font-semibold">All credit payments</h2><p className="text-sm text-muted-foreground">Review payments across all customers. Online payments are credited automatically after provider confirmation. Only verified direct bank transfers use manual approval. Pending amounts are amounts due, not verified receipts.</p>
    <div className="flex flex-wrap gap-3"><Input aria-label="Search credit payments" className="max-w-md" placeholder="Search customer, email or payment reference" value={search} onChange={e => { setPage(1); setSearch(e.target.value); }} />
      <label className="text-sm">Payment status<select className="ml-2 rounded-md border bg-background p-2" value={status} onChange={e => { setPage(1); setStatus(e.target.value); }}>{['all','pending','completed','failed','cancelled','refunded'].map(s => <option key={s} value={s}>{s === 'all' ? 'All statuses' : s}</option>)}</select></label>
      <label className="text-sm">Payment method<select className="ml-2 rounded-md border bg-background p-2" value={gateway} onChange={e => { setPage(1); setGateway(e.target.value); }}><option value="all">All methods</option><option value="eft">Direct bank transfer</option><option value="payos">PayOS</option><option value="payfast">PayFast</option><option value="paystack">Paystack</option><option value="ozow">Ozow</option></select></label>
      <Button variant="outline" onClick={() => void refetch()}>Refresh payments</Button>
    </div>
    {error ? <p role="alert">Could not load payment history. {isAccessBlocked(error) ? error.message : 'Please retry.'}</p> : isLoading ? <p role="status">Loading payments...</p> : <>
      <Table><TableHeader><TableRow><TableHead>Customer</TableHead><TableHead>Reference</TableHead><TableHead>Method</TableHead><TableHead>Status</TableHead><TableHead>Amount</TableHead><TableHead>Credits ordered</TableHead><TableHead>Credits added</TableHead><TableHead>Created</TableHead><TableHead>Action</TableHead></TableRow></TableHeader>
        <TableBody>{data?.payments.map(p => <TableRow key={p.id}><TableCell><p>{p.customer_name}</p><p className="text-xs text-muted-foreground">{p.customer_email}</p></TableCell><TableCell>{p.merchant_reference}</TableCell><TableCell>{p.gateway === 'eft' ? 'Direct transfer' : p.gateway}</TableCell><TableCell>{p.status}</TableCell><TableCell>{p.currency === 'ZAR' ? rand(Math.round(Number(p.amount) * 100)) : `${p.currency} ${p.amount}`}</TableCell><TableCell>{p.credits_ordered == null ? '—' : Number(p.credits_ordered).toLocaleString()}</TableCell><TableCell>{Number(p.credits_added).toLocaleString()}</TableCell><TableCell>{p.created_at}</TableCell><TableCell>{p.gateway === 'eft' && p.order_id ? <Button variant="outline" onClick={() => onViewTransfer(p.merchant_reference)}>View transfer</Button> : <span className="text-xs text-muted-foreground">Automatic verification</span>}</TableCell></TableRow>)}</TableBody>
      </Table>
      {data && !data.payments.length && <p className="text-sm">No payments match these filters.</p>}
      {data && <div className="flex flex-wrap items-center gap-3 text-sm"><span>{data.pagination.total} payments</span><Button variant="outline" disabled={page === 1} onClick={() => setPage(p => p - 1)}>Previous payments</Button><span>Page {page} of {data.pagination.last_page}</span><Button variant="outline" disabled={page >= data.pagination.last_page} onClick={() => setPage(p => p + 1)}>Next payments</Button></div>}
    </>}
  </section>;
}
