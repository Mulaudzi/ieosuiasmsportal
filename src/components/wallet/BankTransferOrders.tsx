import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuth } from '@/hooks/useAuth';
import { PurchaseOrder } from '@/lib/credit-purchases';
import { BankTransferOrder } from './BankTransferOrder';
import { Button } from '@/components/ui/button';

export function BankTransferOrders() {
  const { user } = useAuth(); const client = useQueryClient(); const [page, setPage] = useState(1);
  const { data, error, refetch } = useQuery({ queryKey: ['credit-orders', user?.id, page], enabled: !!user, refetchInterval: 30000, queryFn: async () => {
    const r = await api.get<{ orders: PurchaseOrder[]; total: number }>('/wallet/purchase-orders', { page: String(page) });
    return (r.data ?? r) as { orders: PurchaseOrder[]; total: number };
  } });
  const refresh = () => { void refetch(); for (const key of ['wallet', 'wallet-stats', 'wallet-activity']) void client.invalidateQueries({ queryKey: [key] }); };
  if (!error && !data?.orders.length) return null;
  return <section className="mt-6 space-y-3" aria-label="Bank transfer orders"><h2 className="text-lg font-semibold">Bank transfer orders</h2>
    {error ? <p role="alert">Could not load transfer orders. <Button variant="link" onClick={() => void refetch()}>Retry</Button></p> : data?.orders.map(order => <BankTransferOrder key={order.id} order={order} onChange={refresh} />)}
    {data && data.total > 20 && <div className="flex items-center gap-3"><Button variant="outline" disabled={page === 1} onClick={() => setPage(p => p - 1)}>Previous</Button><span>Page {page}</span><Button variant="outline" disabled={page * 20 >= data.total} onClick={() => setPage(p => p + 1)}>Next</Button></div>}
  </section>;
}
