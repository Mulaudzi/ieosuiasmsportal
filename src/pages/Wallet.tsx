import { useState } from "react";
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
} from "lucide-react";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { getWalletHistory } from "@/lib/api";

const packages = [
  { credits: 1000, price: 270, popular: false },
  { credits: 5000, price: 1350, popular: true },
  { credits: 10000, price: 2700, popular: false },
  { credits: 25000, price: 6750, popular: false },
];

const transactions = [
  { id: "1", type: "purchase", description: "Credit Purchase - 5,000 credits", amount: "+5,000", date: "Jan 7, 2026", status: "completed" },
  { id: "2", type: "usage", description: "SMS Campaign - Summer Sale", amount: "-1,250", date: "Jan 7, 2026", status: "completed" },
  { id: "3", type: "usage", description: "SMS Campaign - Flash Sale", amount: "-3,200", date: "Jan 6, 2026", status: "completed" },
  { id: "4", type: "refund", description: "Refund - Failed Campaign", amount: "+150", date: "Jan 5, 2026", status: "completed" },
  { id: "5", type: "purchase", description: "Credit Purchase - 10,000 credits", amount: "+10,000", date: "Jan 1, 2026", status: "completed" },
];

export default function Wallet() {
  const [isExporting, setIsExporting] = useState(false);
  const [showBuyModal, setShowBuyModal] = useState(false);
  const [selectedPackage, setSelectedPackage] = useState<{ credits: number; price: number } | null>(null);

  const handleBuyCredits = (credits: number, price: number) => {
    setSelectedPackage({ credits, price });
    setShowBuyModal(true);
  };

  const handleExport = async () => {
    setIsExporting(true);
    // TODO: Implement transaction export
    setTimeout(() => {
      toast({ title: "Export ready", description: "Your transaction history has been exported." });
      setIsExporting(false);
    }, 1000);
  };

  return (
    <DashboardLayout
      title="Wallet"
      subtitle="Manage your credits and view transaction history"
      actions={
        <div className="flex gap-3">
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
      <BuyCreditsModal open={showBuyModal} onOpenChange={setShowBuyModal} selectedCredits={selectedPackage?.credits} selectedPrice={selectedPackage?.price} />

      <div className="grid gap-6 md:grid-cols-3">
        <MetricCard title="Available Credits" value={12450} icon={WalletIcon} iconColor="primary" />
        <MetricCard title="Credits Used This Month" value={8750} change="35% of total" changeType="neutral" icon={TrendingUp} iconColor="accent" />
        <MetricCard title="Total Spent" value="R 4,250.00" change="Lifetime value" changeType="neutral" icon={CreditCard} iconColor="info" />
      </div>

      <div className="mt-8" id="buy-credits">
        <h2 className="mb-4 text-lg font-semibold text-foreground">Buy Credits</h2>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {packages.map((pkg) => (
            <div key={pkg.credits} className={cn("relative rounded-xl border-2 bg-card p-6 transition-all hover:shadow-lg", pkg.popular ? "border-primary shadow-md" : "border-border hover:border-primary/50")}>
              {pkg.popular && <div className="absolute -top-3 left-1/2 -translate-x-1/2"><span className="rounded-full bg-primary px-3 py-1 text-xs font-medium text-primary-foreground">Most Popular</span></div>}
              <div className="text-center">
                <p className="text-3xl font-bold text-foreground">{pkg.credits.toLocaleString()}</p>
                <p className="text-sm text-muted-foreground">credits</p>
                <p className="mt-4 text-2xl font-bold text-primary">R{pkg.price}</p>
                <p className="text-xs text-muted-foreground">R{(pkg.price / pkg.credits).toFixed(2)} per SMS</p>
                <Button className={cn("mt-4 w-full")} variant={pkg.popular ? "default" : "outline"} onClick={() => handleBuyCredits(pkg.credits, pkg.price)}>Buy Now</Button>
              </div>
            </div>
          ))}
        </div>
      </div>

      <div className="mt-8">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-foreground">Transaction History</h2>
          <Button variant="outline" size="sm">View All</Button>
        </div>
        <div className="rounded-xl border border-border bg-card overflow-hidden">
          <div className="divide-y divide-border">
            {transactions.map((tx) => (
              <div key={tx.id} className="flex items-center gap-4 p-4 transition-colors hover:bg-muted/30">
                <div className={cn("flex h-10 w-10 items-center justify-center rounded-full", tx.type === "purchase" && "bg-success/10 text-success", tx.type === "usage" && "bg-destructive/10 text-destructive", tx.type === "refund" && "bg-info/10 text-info")}>
                  {tx.type === "purchase" && <ArrowDownLeft className="h-5 w-5" />}
                  {tx.type === "usage" && <ArrowUpRight className="h-5 w-5" />}
                  {tx.type === "refund" && <ArrowDownLeft className="h-5 w-5" />}
                </div>
                <div className="flex-1 min-w-0">
                  <p className="font-medium text-foreground">{tx.description}</p>
                  <div className="mt-0.5 flex items-center gap-2 text-sm text-muted-foreground"><Clock className="h-3.5 w-3.5" />{tx.date}</div>
                </div>
                <div className="text-right">
                  <p className={cn("text-lg font-semibold", tx.amount.startsWith("+") ? "text-success" : "text-destructive")}>{tx.amount}</p>
                  <div className="mt-0.5 flex items-center justify-end gap-1 text-xs text-muted-foreground"><CheckCircle className="h-3 w-3 text-success" />Completed</div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </DashboardLayout>
  );
}
