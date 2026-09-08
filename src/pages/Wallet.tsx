import { useState, useCallback } from "react";
import { useNavigate } from "react-router-dom";
import { useQueryClient } from "@tanstack/react-query";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { MetricCard } from "@/components/dashboard/MetricCard";
import { BuyCreditsModal } from "@/components/wallet/BuyCreditsModal";
import {
  Wallet as WalletIcon,
  CreditCard,
  TrendingUp,
  ArrowUpRight,
  ArrowDownLeft,
  Clock,
  CheckCircle,
  Loader2,
  ShoppingCart,
  RefreshCw,
  XCircle,
  History,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { getTransactions } from "@/lib/api";
import { useWalletStats } from "@/hooks/useWallet";
import { format } from "date-fns";
import { useQuery } from "@tanstack/react-query";

interface Transaction {
  id: string;
  type: "purchase" | "usage" | "refund";
  description: string;
  amount: number;
  created_at: string;
  status: "completed" | "pending" | "failed";
}

export default function Wallet() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [showBuyModal, setShowBuyModal] = useState(false);

  // Use the shared wallet stats hook
  const { balance, usedThisMonth, totalSpent, smsCredits, pricePerCredit, isLoading: statsLoading, refetch: refetchStats } = useWalletStats();

  // Transactions query
  const { data: transactionsData, isLoading: transactionsLoading, refetch: refetchTransactions } = useQuery({
    queryKey: ["wallet-transactions"],
    queryFn: async () => {
      const res = await getTransactions({ limit: 10 });
      return res.data?.transactions || [];
    },
  });

  const transactions = transactionsData || [];
  const loading = statsLoading || transactionsLoading;

  const refreshAll = useCallback(() => {
    refetchStats();
    refetchTransactions();
    // Also invalidate the sidebar wallet query
    queryClient.invalidateQueries({ queryKey: ["wallet"] });
  }, [refetchStats, refetchTransactions, queryClient]);

  const formatDate = (dateString: string) => {
    try {
      return format(new Date(dateString), "MMM d, yyyy");
    } catch {
      return dateString;
    }
  };

  const formatAmount = (amount: number, type: string) => {
    const prefix = type === "usage" ? "-" : "+";
    return `${prefix}${Math.abs(amount).toLocaleString()}`;
  };

  return (
    <DashboardLayout
      title="SMS Credits"
      subtitle="Buy SMS credits and view your transaction history"
      actions={
        <div className="flex gap-3">
          <Button variant="outline" size="icon" onClick={refreshAll} disabled={loading}>
            <RefreshCw className={cn("h-4 w-4", loading && "animate-spin")} />
          </Button>
          <Button className="gap-2" onClick={() => setShowBuyModal(true)}>
            <ShoppingCart className="h-4 w-4" />
            Buy Credits
          </Button>
        </div>
      }
    >
      <BuyCreditsModal 
        open={showBuyModal} 
        onOpenChange={(open) => { setShowBuyModal(open); if (!open) refreshAll(); }} 
        onPurchaseComplete={refreshAll}
      />

      {loading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-primary" />
        </div>
      ) : (
        <>
          <div className="grid gap-6 md:grid-cols-3">
            <MetricCard title="Available SMS Credits" value={smsCredits.toLocaleString()} change={`1 credit = 1 segment · R${pricePerCredit.toFixed(2)}`} changeType="neutral" icon={WalletIcon} iconColor="primary" />
            <MetricCard 
              title="SMS Credits Used This Month"
              value={Math.floor(usedThisMonth).toLocaleString()}
              change="Billable segments sent this month"
              changeType="neutral" 
              icon={TrendingUp} 
              iconColor="accent" 
            />
            <MetricCard 
              title="Total Payments" 
              value={`R ${totalSpent.toLocaleString()}`} 
              change="Lifetime value" 
              changeType="neutral" 
              icon={CreditCard} 
              iconColor="info" 
            />
          </div>

          <div className="mt-8" id="buy-credits">
            <h2 className="mb-4 text-lg font-semibold text-foreground">Buy SMS Credits</h2>
            <div className="rounded-xl border bg-card p-6">
              <p className="text-sm text-muted-foreground">Choose a ZAR payment amount. Each R{pricePerCredit.toFixed(2)} purchases one SMS credit, and one credit sends one billable segment.</p>
              <Button className="mt-4" onClick={() => setShowBuyModal(true)}>Buy SMS credits</Button>
            </div>
          </div>

          <div className="mt-8">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-semibold text-foreground">Transaction History</h2>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" onClick={() => navigate('/wallet/payments')}>
                  <History className="h-4 w-4 mr-2" />
                  Payment History
                </Button>
              </div>
            </div>
            <div className="rounded-xl border border-border bg-card overflow-hidden">
              {transactions.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-12">
                  <WalletIcon className="h-12 w-12 text-muted-foreground/50" />
                  <p className="mt-4 text-lg font-medium text-foreground">No transactions yet</p>
                  <p className="mt-1 text-sm text-muted-foreground">Your transaction history will appear here</p>
                </div>
              ) : (
                <div className="divide-y divide-border">
                  {transactions.map((tx) => (
                    <div key={tx.id} className="flex items-center gap-4 p-4 transition-colors hover:bg-muted/30">
                      <div className={cn(
                        "flex h-10 w-10 items-center justify-center rounded-full",
                        tx.type === "purchase" && "bg-success/10 text-success",
                        tx.type === "usage" && "bg-destructive/10 text-destructive",
                        tx.type === "refund" && "bg-info/10 text-info"
                      )}>
                        {tx.type === "purchase" && <ArrowDownLeft className="h-5 w-5" />}
                        {tx.type === "usage" && <ArrowUpRight className="h-5 w-5" />}
                        {tx.type === "refund" && <ArrowDownLeft className="h-5 w-5" />}
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="font-medium text-foreground">{tx.description}</p>
                        <div className="mt-0.5 flex items-center gap-2 text-sm text-muted-foreground">
                          <Clock className="h-3.5 w-3.5" />
                          {formatDate(tx.created_at)}
                        </div>
                      </div>
                      <div className="text-right">
                        <p className={cn(
                          "text-lg font-semibold",
                          tx.type === "usage" ? "text-destructive" : "text-success"
                        )}>
                          {formatAmount(tx.amount, tx.type)}
                        </p>
                        <div className="mt-0.5 flex items-center justify-end gap-1 text-xs text-muted-foreground">
                          {tx.status === "completed" && <CheckCircle className="h-3 w-3 text-success" />}
                          {tx.status === "pending" && <Clock className="h-3 w-3 text-warning" />}
                          {tx.status === "failed" && <XCircle className="h-3 w-3 text-destructive" />}
                          <span className="capitalize">{tx.status}</span>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        </>
      )}
    </DashboardLayout>
  );
}
