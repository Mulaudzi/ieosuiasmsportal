import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ArrowRight, Coins, RefreshCw } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { SMS_REFRESH_MS } from '@/hooks/useSmsRefresh';
import { api } from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { CustomerCreditTransfer } from '@/components/wallet/CustomerCreditTransfer';

interface CreditWallet { sms_credits: number }

export function DashboardCredits() {
  const { user } = useAuth();
  const credits = useQuery({
    // Never reuse another signed-in customer's cached balance.
    queryKey: ['dashboard-credit-balance', user?.id],
    enabled: !!user,
    queryFn: async () => {
      const response = await api.get<{ wallet: CreditWallet }>('/wallet');
      const wallet = ((response.data ?? response) as { wallet?: CreditWallet }).wallet;
      const available = wallet?.sms_credits;
      if (!response.success || typeof available !== 'number' || !Number.isSafeInteger(available) || available < 0) {
        throw new Error('Credit balance unavailable');
      }
      return available;
    },
    staleTime: 0,
    retry: false,
    refetchOnWindowFocus: 'always',
    refetchInterval: SMS_REFRESH_MS,
    refetchIntervalInBackground: false,
  });
  const unavailable = credits.isError;
  const loading = !!user && credits.isPending;

  return (
    <section aria-labelledby="dashboard-credits-heading" className="mb-4 rounded-2xl border border-primary/20 bg-gradient-to-r from-primary/5 to-card p-3 sm:p-5">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex min-w-0 items-start gap-3">
          <span className="rounded-xl bg-primary/10 p-3 text-primary"><Coins aria-hidden="true" className="h-6 w-6" /></span>
          <div className="min-w-0">
            <h2 id="dashboard-credits-heading" className="text-sm font-medium text-muted-foreground">Available SMS credits</h2>
            {loading ? (
              <div role="status" aria-label="Loading available credits" className="my-2"><Skeleton className="h-9 w-32" /></div>
            ) : unavailable || !user ? (
              <p role="alert" className="my-2 text-sm">Credit balance unavailable. Please retry.</p>
            ) : (
              <p className="mt-1 break-words text-3xl font-bold tracking-tight sm:text-4xl" aria-label={`${credits.data?.toLocaleString()} available SMS credits`}>{credits.data?.toLocaleString()}</p>
            )}
            <p className="mt-1 text-xs text-muted-foreground">Reserved credits are excluded. One credit pays for one SMS segment.</p>
          </div>
        </div>
        <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">
          <Button variant="outline" size="sm" disabled={!user || credits.isFetching} onClick={() => void credits.refetch()} className="h-9 w-9 gap-2 p-0 sm:w-auto sm:px-3">
            <RefreshCw aria-hidden="true" className={`h-4 w-4 ${credits.isFetching ? 'animate-spin motion-reduce:animate-none' : ''}`} />
            <span className="sr-only sm:not-sr-only">{unavailable ? 'Retry credits' : 'Refresh credits'}</span>
          </Button>
          <Button asChild size="sm" className="gap-2"><Link to="/wallet">Buy credits<ArrowRight aria-hidden="true" className="h-4 w-4" /></Link></Button>
          {user&&<CustomerCreditTransfer availableCredits={credits.data??0} disabled={credits.isPending||credits.isError} compact/>}
        </div>
      </div>
    </section>
  );
}
