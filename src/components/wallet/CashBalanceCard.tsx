import { useAuth } from '@/hooks/useAuth';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { type PurchaseOptions, rand } from '@/lib/credit-purchases';

export function CashBalanceCard() {
  const {user}=useAuth();
  const { data, error }=useQuery({queryKey:['customer-cash-balance',user?.id],enabled:!!user,retry:1,queryFn:async()=>{const r=await api.get<PurchaseOptions>('/wallet/purchase-options');return ((r.data??r) as PurchaseOptions).cash_balance;}});
  if(error)return <p role="status" className="text-sm">Your rand balance could not be loaded. It will be checked again before your next purchase.</p>;
  if(!data?.enabled)return null;
  return <section aria-label="Rand account balance" className="space-y-2 rounded-xl border bg-card p-4"><h2 className="font-semibold">Rand account balance</h2><p className="text-xl font-semibold">{rand(Math.abs(data.balance_cents))} {data.balance_cents<0?'owed':'cash credit'}</p><p className="text-sm text-muted-foreground">Separate from SMS credits. Overpayments stay as cash credit; shortfalls are shown as owed. On your next purchase, choose to keep the SMS quantity and adjust the payment, or keep your payment budget and adjust the SMS quantity.</p>{Boolean(data.reserved_cents)&&<p className="text-sm">{rand(Math.abs(data.reserved_cents))} is allocated to open purchases. It cannot be applied twice. Contact support if an old order needs closing.</p>}</section>;
}
