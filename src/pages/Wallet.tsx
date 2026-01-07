import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { MetricCard } from "@/components/dashboard/MetricCard";
import {
  Wallet as WalletIcon,
  CreditCard,
  TrendingUp,
  ArrowUpRight,
  ArrowDownLeft,
  Plus,
  Download,
  Clock,
  CheckCircle,
} from "lucide-react";
import { cn } from "@/lib/utils";

const packages = [
  { credits: 1000, price: 25, popular: false },
  { credits: 5000, price: 100, popular: true },
  { credits: 10000, price: 180, popular: false },
  { credits: 25000, price: 400, popular: false },
];

const transactions = [
  {
    id: "1",
    type: "purchase",
    description: "Credit Purchase - 5,000 credits",
    amount: "+5,000",
    date: "Jan 7, 2026",
    status: "completed",
  },
  {
    id: "2",
    type: "usage",
    description: "SMS Campaign - Summer Sale",
    amount: "-1,250",
    date: "Jan 7, 2026",
    status: "completed",
  },
  {
    id: "3",
    type: "usage",
    description: "SMS Campaign - Flash Sale",
    amount: "-3,200",
    date: "Jan 6, 2026",
    status: "completed",
  },
  {
    id: "4",
    type: "refund",
    description: "Refund - Failed Campaign",
    amount: "+150",
    date: "Jan 5, 2026",
    status: "completed",
  },
  {
    id: "5",
    type: "purchase",
    description: "Credit Purchase - 10,000 credits",
    amount: "+10,000",
    date: "Jan 1, 2026",
    status: "completed",
  },
];

export default function Wallet() {
  return (
    <DashboardLayout
      title="Wallet"
      subtitle="Manage your credits and view transaction history"
      actions={
        <div className="flex gap-3">
          <Button variant="outline" className="gap-2">
            <Download className="h-4 w-4" />
            Export
          </Button>
          <Button className="gap-2">
            <Plus className="h-4 w-4" />
            Buy Credits
          </Button>
        </div>
      }
    >
      {/* Balance Cards */}
      <div className="grid gap-6 md:grid-cols-3">
        <MetricCard
          title="Available Credits"
          value={12450}
          icon={WalletIcon}
          iconColor="primary"
        />
        <MetricCard
          title="Credits Used This Month"
          value={8750}
          change="35% of total"
          changeType="neutral"
          icon={TrendingUp}
          iconColor="accent"
        />
        <MetricCard
          title="Total Spent"
          value="$425.00"
          change="Lifetime value"
          changeType="neutral"
          icon={CreditCard}
          iconColor="info"
        />
      </div>

      {/* Buy Credits */}
      <div className="mt-8">
        <h2 className="mb-4 text-lg font-semibold text-foreground">
          Buy Credits
        </h2>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {packages.map((pkg) => (
            <div
              key={pkg.credits}
              className={cn(
                "relative rounded-xl border-2 bg-card p-6 transition-all hover:shadow-lg",
                pkg.popular
                  ? "border-primary shadow-md"
                  : "border-border hover:border-primary/50"
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
                <p className="text-3xl font-bold text-foreground">
                  {pkg.credits.toLocaleString()}
                </p>
                <p className="text-sm text-muted-foreground">credits</p>
                <p className="mt-4 text-2xl font-bold text-primary">
                  ${pkg.price}
                </p>
                <p className="text-xs text-muted-foreground">
                  ${(pkg.price / pkg.credits * 100).toFixed(2)} per 100 credits
                </p>
                <Button
                  className={cn(
                    "mt-4 w-full",
                    pkg.popular ? "" : "variant-outline"
                  )}
                  variant={pkg.popular ? "default" : "outline"}
                >
                  Buy Now
                </Button>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Transaction History */}
      <div className="mt-8">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-foreground">
            Transaction History
          </h2>
          <Button variant="outline" size="sm">
            View All
          </Button>
        </div>

        <div className="rounded-xl border border-border bg-card overflow-hidden">
          <div className="divide-y divide-border">
            {transactions.map((tx) => (
              <div
                key={tx.id}
                className="flex items-center gap-4 p-4 transition-colors hover:bg-muted/30"
              >
                <div
                  className={cn(
                    "flex h-10 w-10 items-center justify-center rounded-full",
                    tx.type === "purchase" && "bg-success/10 text-success",
                    tx.type === "usage" && "bg-destructive/10 text-destructive",
                    tx.type === "refund" && "bg-info/10 text-info"
                  )}
                >
                  {tx.type === "purchase" && (
                    <ArrowDownLeft className="h-5 w-5" />
                  )}
                  {tx.type === "usage" && <ArrowUpRight className="h-5 w-5" />}
                  {tx.type === "refund" && <ArrowDownLeft className="h-5 w-5" />}
                </div>

                <div className="flex-1 min-w-0">
                  <p className="font-medium text-foreground">
                    {tx.description}
                  </p>
                  <div className="mt-0.5 flex items-center gap-2 text-sm text-muted-foreground">
                    <Clock className="h-3.5 w-3.5" />
                    {tx.date}
                  </div>
                </div>

                <div className="text-right">
                  <p
                    className={cn(
                      "text-lg font-semibold",
                      tx.amount.startsWith("+")
                        ? "text-success"
                        : "text-destructive"
                    )}
                  >
                    {tx.amount}
                  </p>
                  <div className="mt-0.5 flex items-center justify-end gap-1 text-xs text-muted-foreground">
                    <CheckCircle className="h-3 w-3 text-success" />
                    Completed
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Usage Breakdown */}
      <div className="mt-8 grid gap-6 md:grid-cols-2">
        <div className="rounded-xl border border-border bg-card p-6">
          <h3 className="font-semibold text-foreground">Credit Usage by Type</h3>
          <div className="mt-4 space-y-4">
            <div>
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground">SMS Campaigns</span>
                <span className="font-medium text-foreground">6,200</span>
              </div>
              <div className="mt-2 h-2 rounded-full bg-muted">
                <div
                  className="h-2 rounded-full bg-primary"
                  style={{ width: "71%" }}
                />
              </div>
            </div>
            <div>
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Email Campaigns</span>
                <span className="font-medium text-foreground">1,800</span>
              </div>
              <div className="mt-2 h-2 rounded-full bg-muted">
                <div
                  className="h-2 rounded-full bg-accent"
                  style={{ width: "21%" }}
                />
              </div>
            </div>
            <div>
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Transactional</span>
                <span className="font-medium text-foreground">750</span>
              </div>
              <div className="mt-2 h-2 rounded-full bg-muted">
                <div
                  className="h-2 rounded-full bg-info"
                  style={{ width: "8%" }}
                />
              </div>
            </div>
          </div>
        </div>

        <div className="rounded-xl border border-border bg-card p-6">
          <h3 className="font-semibold text-foreground">Credit Alerts</h3>
          <p className="mt-1 text-sm text-muted-foreground">
            Get notified when your balance is low
          </p>
          <div className="mt-4 space-y-3">
            <div className="flex items-center justify-between rounded-lg bg-muted/50 p-3">
              <span className="text-sm text-foreground">Low balance alert</span>
              <span className="text-sm font-medium text-primary">1,000 credits</span>
            </div>
            <div className="flex items-center justify-between rounded-lg bg-muted/50 p-3">
              <span className="text-sm text-foreground">Critical alert</span>
              <span className="text-sm font-medium text-destructive">100 credits</span>
            </div>
          </div>
          <Button variant="outline" className="mt-4 w-full">
            Configure Alerts
          </Button>
        </div>
      </div>
    </DashboardLayout>
  );
}
