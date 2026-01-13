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
  Download,
  Clock,
  CheckCircle,
  Loader2,
  ShoppingCart,
  RefreshCw,
  XCircle,
  History,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { getTransactions, getCreditPackages, handleApiError } from "@/lib/api";
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

interface CreditPackage {
  credits: number;
  price: number;
  popular?: boolean;
}

export default function Wallet() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [isExporting, setIsExporting] = useState(false);
  const [showBuyModal, setShowBuyModal] = useState(false);
  const [selectedPackage, setSelectedPackage] = useState<{ credits: number; price: number } | null>(null);

  // Use the shared wallet stats hook
  const { balance, usedThisMonth, totalSpent, isLoading: statsLoading, refetch: refetchStats } = useWalletStats();

  // Transactions query
  const { data: transactionsData, isLoading: transactionsLoading, refetch: refetchTransactions } = useQuery({
    queryKey: ["wallet-transactions"],
    queryFn: async () => {
      const res = await getTransactions({ limit: 10 });
      return res.data?.transactions || [];
    },
  });

  // Packages query
  const { data: packages = [] } = useQuery({
    queryKey: ["credit-packages"],
    queryFn: async () => {
      const res = await getCreditPackages();
      return res.data?.packages || [
        { credits: 1000, price: 270, popular: false },
        { credits: 5000, price: 1350, popular: true },
        { credits: 10000, price: 2700, popular: false },
        { credits: 25000, price: 6750, popular: false },
      ];
    },
    staleTime: 5 * 60 * 1000, // Cache packages for 5 minutes
  });

  const transactions = transactionsData || [];
  const loading = statsLoading || transactionsLoading;

  const refreshAll = useCallback(() => {
    refetchStats();
    refetchTransactions();
    // Also invalidate the sidebar wallet query
    queryClient.invalidateQueries({ queryKey: ["wallet"] });
  }, [refetchStats, refetchTransactions, queryClient]);

  const handleBuyCredits = (credits: number, price: number) => {
    setSelectedPackage({ credits, price });
    setShowBuyModal(true);
  };

  const handleExport = async () => {
    setIsExporting(true);
    try {
      // TODO: Implement transaction export
      toast({ title: "Export ready", description: "Your transaction history has been exported." });
    } catch (error) {
      handleApiError(error);
    } finally {
      setIsExporting(false);
    }
  };

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
      title="Wallet"
      subtitle="Manage your credits and view transaction history"
      actions={
        <div className="flex gap-3">
          <Button variant="outline" size="icon" onClick={refreshAll} disabled={loading}>
            <RefreshCw className={cn("h-4 w-4", loading && "animate-spin")} />
          </Button>
          <Button variant="outline" className="gap-2" onClick={handleExport} disabled={isExporting}>
            {isExporting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
            Export
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
        selectedCredits={selectedPackage?.credits} 
        selectedPrice={selectedPackage?.price} 
      />

      {loading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-primary" />
        </div>
      ) : (
        <>
          <div className="grid gap-6 md:grid-cols-3">
            <MetricCard title="Available Credits" value={balance} icon={WalletIcon} iconColor="primary" />
            <MetricCard 
              title="Credits Used This Month" 
              value={usedThisMonth} 
              change={balance > 0 ? `${((usedThisMonth / (balance + usedThisMonth)) * 100).toFixed(0)}% of total` : "0%"} 
              changeType="neutral" 
              icon={TrendingUp} 
              iconColor="accent" 
            />
            <MetricCard 
              title="Total Spent" 
              value={`R ${totalSpent.toLocaleString()}`} 
              change="Lifetime value" 
              changeType="neutral" 
              icon={CreditCard} 
              iconColor="info" 
            />
          </div>

          <div className="mt-8" id="buy-credits">
            <h2 className="mb-4 text-lg font-semibold text-foreground">Buy Credits</h2>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {packages.map((pkg) => (
                <div 
                  key={pkg.credits} 
                  className={cn(
                    "relative rounded-xl border-2 bg-card p-6 transition-all hover:shadow-lg", 
                    pkg.popular ? "border-primary shadow-md" : "border-border hover:border-primary/50"
                  )}
                >
                  {pkg.popular && (
                    <div className="absolute -top-3 left-1/2 -translate-x-1/2">
                      <span className="rounded-full bg-primary px-3 py-1 text-xs font-medium text-primary-foreground">
                        Most Popular
                      </span>
                    </div>
                  )}
                  <div className="text-center">
                    <p className="text-3xl font-bold text-foreground">{pkg.credits.toLocaleString()}</p>
                    <p className="text-sm text-muted-foreground">credits</p>
                    <p className="mt-4 text-2xl font-bold text-primary">R{pkg.price}</p>
                    <p className="text-xs text-muted-foreground">R{(pkg.price / pkg.credits).toFixed(2)} per SMS</p>
                    <Button 
                      className={cn("mt-4 w-full")} 
                      variant={pkg.popular ? "default" : "outline"} 
                      onClick={() => handleBuyCredits(pkg.credits, pkg.price)}
                    >
                      Buy Now
                    </Button>
                  </div>
                </div>
              ))}
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
                <Button variant="outline" size="sm">View All</Button>
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