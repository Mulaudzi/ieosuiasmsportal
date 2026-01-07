import { useState } from "react";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { MetricCard } from "@/components/dashboard/MetricCard";
import {
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  BarChart,
  Bar,
} from "recharts";
import {
  Download,
  Calendar,
  MessageSquare,
  Mail,
  CheckCircle,
  XCircle,
  Clock,
  Loader2,
} from "lucide-react";
import { toast } from "@/hooks/use-toast";
import { exportReport } from "@/lib/api";

const dailyData = [
  { date: "Jan 1", sms: 12500, email: 8200, delivered: 19800, failed: 900 },
  { date: "Jan 2", sms: 15200, email: 9100, delivered: 23100, failed: 1200 },
  { date: "Jan 3", sms: 11800, email: 7500, delivered: 18200, failed: 1100 },
  { date: "Jan 4", sms: 18900, email: 11200, delivered: 28500, failed: 1600 },
  { date: "Jan 5", sms: 14200, email: 8800, delivered: 21800, failed: 1200 },
  { date: "Jan 6", sms: 16500, email: 10500, delivered: 25600, failed: 1400 },
  { date: "Jan 7", sms: 13800, email: 9200, delivered: 21800, failed: 1200 },
];

const deliveryData = [
  { status: "Delivered", count: 94 },
  { status: "Pending", count: 3 },
  { status: "Failed", count: 2 },
  { status: "Bounced", count: 1 },
];

export default function Reports() {
  const [dateRange, setDateRange] = useState("7d");
  const [isExporting, setIsExporting] = useState(false);

  const handleExport = async () => {
    setIsExporting(true);
    try {
      const response = await exportReport("delivery", dateRange);
      if (response.success) {
        toast({
          title: "Report exported",
          description: "Your report has been generated and is ready for download.",
        });
      }
    } catch (error) {
      toast({
        title: "Export failed",
        description: "Please try again.",
        variant: "destructive",
      });
    } finally {
      setIsExporting(false);
    }
  };

  return (
    <DashboardLayout
      title="Reports"
      subtitle="Analytics and delivery reports for all your campaigns"
      actions={
        <div className="flex gap-3">
          <Select value={dateRange} onValueChange={setDateRange}>
            <SelectTrigger className="w-40">
              <Calendar className="mr-2 h-4 w-4" />
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="24h">Last 24 hours</SelectItem>
              <SelectItem value="7d">Last 7 days</SelectItem>
              <SelectItem value="30d">Last 30 days</SelectItem>
              <SelectItem value="90d">Last 90 days</SelectItem>
            </SelectContent>
          </Select>
          <Button variant="outline" className="gap-2" onClick={handleExport} disabled={isExporting}>
            {isExporting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
            Export Report
          </Button>
        </div>
      }
    >
      {/* Summary Stats */}
      <div className="grid gap-6 md:grid-cols-4">
        <MetricCard
          title="Total Messages"
          value={284650}
          change="+12.5% vs last period"
          changeType="positive"
          icon={MessageSquare}
          iconColor="primary"
        />
        <MetricCard
          title="Delivered"
          value={269217}
          change="94.6% success rate"
          changeType="positive"
          icon={CheckCircle}
          iconColor="success"
        />
        <MetricCard
          title="Failed"
          value={8540}
          change="-2.1% vs last period"
          changeType="positive"
          icon={XCircle}
          iconColor="destructive"
        />
        <MetricCard
          title="Avg. Delivery Time"
          value="1.2s"
          change="-0.3s improvement"
          changeType="positive"
          icon={Clock}
          iconColor="info"
        />
      </div>

      {/* Charts */}
      <Tabs defaultValue="volume" className="mt-8">
        <TabsList>
          <TabsTrigger value="volume">Message Volume</TabsTrigger>
          <TabsTrigger value="delivery">Delivery Status</TabsTrigger>
        </TabsList>

        <TabsContent value="volume" className="mt-6">
          <div className="rounded-xl border border-border bg-card p-6">
            <div className="mb-6 flex items-center justify-between">
              <div>
                <h3 className="text-lg font-semibold text-foreground">
                  Daily Message Volume
                </h3>
                <p className="text-sm text-muted-foreground">
                  SMS and Email messages sent per day
                </p>
              </div>
              <div className="flex items-center gap-4">
                <div className="flex items-center gap-2">
                  <div className="h-3 w-3 rounded-full bg-primary" />
                  <span className="text-sm text-muted-foreground">SMS</span>
                </div>
                <div className="flex items-center gap-2">
                  <div className="h-3 w-3 rounded-full bg-accent" />
                  <span className="text-sm text-muted-foreground">Email</span>
                </div>
              </div>
            </div>

            <ResponsiveContainer width="100%" height={350}>
              <AreaChart data={dailyData}>
                <defs>
                  <linearGradient id="smsGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="hsl(221, 83%, 53%)" stopOpacity={0.3} />
                    <stop offset="95%" stopColor="hsl(221, 83%, 53%)" stopOpacity={0} />
                  </linearGradient>
                  <linearGradient id="emailGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="hsl(173, 80%, 40%)" stopOpacity={0.3} />
                    <stop offset="95%" stopColor="hsl(173, 80%, 40%)" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="hsl(215, 20%, 91%)" />
                <XAxis dataKey="date" stroke="hsl(215, 16%, 47%)" fontSize={12} />
                <YAxis stroke="hsl(215, 16%, 47%)" fontSize={12} tickFormatter={(v) => `${v/1000}k`} />
                <Tooltip
                  contentStyle={{
                    backgroundColor: "hsl(0, 0%, 100%)",
                    border: "1px solid hsl(215, 20%, 91%)",
                    borderRadius: "0.5rem",
                  }}
                />
                <Area
                  type="monotone"
                  dataKey="sms"
                  stroke="hsl(221, 83%, 53%)"
                  strokeWidth={2}
                  fill="url(#smsGrad)"
                />
                <Area
                  type="monotone"
                  dataKey="email"
                  stroke="hsl(173, 80%, 40%)"
                  strokeWidth={2}
                  fill="url(#emailGrad)"
                />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </TabsContent>

        <TabsContent value="delivery" className="mt-6">
          <div className="rounded-xl border border-border bg-card p-6">
            <div className="mb-6">
              <h3 className="text-lg font-semibold text-foreground">
                Delivery Status Breakdown
              </h3>
              <p className="text-sm text-muted-foreground">
                Message delivery status distribution
              </p>
            </div>

            <ResponsiveContainer width="100%" height={350}>
              <BarChart data={deliveryData} layout="vertical">
                <CartesianGrid strokeDasharray="3 3" stroke="hsl(215, 20%, 91%)" />
                <XAxis type="number" stroke="hsl(215, 16%, 47%)" fontSize={12} />
                <YAxis dataKey="status" type="category" stroke="hsl(215, 16%, 47%)" fontSize={12} width={100} />
                <Tooltip
                  contentStyle={{
                    backgroundColor: "hsl(0, 0%, 100%)",
                    border: "1px solid hsl(215, 20%, 91%)",
                    borderRadius: "0.5rem",
                  }}
                />
                <Bar dataKey="count" fill="hsl(221, 83%, 53%)" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </TabsContent>
      </Tabs>

      {/* Detailed Stats */}
      <div className="mt-8 grid gap-6 md:grid-cols-2">
        {/* SMS Stats */}
        <div className="rounded-xl border border-border bg-card p-6">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
              <MessageSquare className="h-5 w-5" />
            </div>
            <div>
              <h3 className="font-semibold text-foreground">SMS Performance</h3>
              <p className="text-sm text-muted-foreground">Last 7 days</p>
            </div>
          </div>

          <div className="mt-6 space-y-4">
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Total Sent</span>
              <span className="font-semibold text-foreground">102,900</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Delivered</span>
              <span className="font-semibold text-success">97,755 (95.0%)</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Failed</span>
              <span className="font-semibold text-destructive">3,087 (3.0%)</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Pending</span>
              <span className="font-semibold text-warning">2,058 (2.0%)</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Credits Used</span>
              <span className="font-semibold text-foreground">102,900</span>
            </div>
          </div>
        </div>

        {/* Email Stats */}
        <div className="rounded-xl border border-border bg-card p-6">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-accent/10 text-accent">
              <Mail className="h-5 w-5" />
            </div>
            <div>
              <h3 className="font-semibold text-foreground">Email Performance</h3>
              <p className="text-sm text-muted-foreground">Last 7 days</p>
            </div>
          </div>

          <div className="mt-6 space-y-4">
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Total Sent</span>
              <span className="font-semibold text-foreground">64,500</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Delivered</span>
              <span className="font-semibold text-success">62,565 (97.0%)</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Opened</span>
              <span className="font-semibold text-info">24,390 (39.0%)</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Clicked</span>
              <span className="font-semibold text-primary">4,838 (7.7%)</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Bounced</span>
              <span className="font-semibold text-destructive">1,290 (2.0%)</span>
            </div>
          </div>
        </div>
      </div>
    </DashboardLayout>
  );
}
