import { useState, useEffect, useCallback } from "react";
import { Link } from "react-router-dom";
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
  BarChart3,
  RefreshCw,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { getReportStats, getReportChartData, getDeliveryBreakdown, exportReport, handleApiError } from "@/lib/api";

interface ReportStats {
  total_messages: number;
  delivered: number;
  failed: number;
  avg_delivery_time: string;
  delivery_rate: number;
}

interface ChartDataPoint {
  date: string;
  sms: number;
  email: number;
  delivered: number;
  failed: number;
}

interface DeliveryData {
  status: string;
  count: number;
}

interface SmsStats {
  total_sent: number;
  delivered: number;
  failed: number;
  pending: number;
  credits_used: number;
}

interface EmailStats {
  total_sent: number;
  delivered: number;
  opened: number;
  clicked: number;
  bounced: number;
}

export default function Reports() {
  const [dateRange, setDateRange] = useState("7d");
  const [loading, setLoading] = useState(true);
  const [isExporting, setIsExporting] = useState(false);
  const [stats, setStats] = useState<ReportStats>({
    total_messages: 0,
    delivered: 0,
    failed: 0,
    avg_delivery_time: "0s",
    delivery_rate: 0,
  });
  const [chartData, setChartData] = useState<ChartDataPoint[]>([]);
  const [deliveryData, setDeliveryData] = useState<DeliveryData[]>([]);
  const [smsStats, setSmsStats] = useState<SmsStats>({
    total_sent: 0,
    delivered: 0,
    failed: 0,
    pending: 0,
    credits_used: 0,
  });
  const [emailStats, setEmailStats] = useState<EmailStats>({
    total_sent: 0,
    delivered: 0,
    opened: 0,
    clicked: 0,
    bounced: 0,
  });

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const [statsRes, chartRes, deliveryRes] = await Promise.all([
        getReportStats(dateRange),
        getReportChartData(dateRange),
        getDeliveryBreakdown(dateRange),
      ]);

      if (statsRes.success && statsRes.data) {
        setStats(statsRes.data.summary || stats);
        setSmsStats(statsRes.data.sms || smsStats);
        setEmailStats(statsRes.data.email || emailStats);
      }

      if (chartRes.success && chartRes.data) {
        setChartData(chartRes.data.chart || []);
      }

      if (deliveryRes.success && deliveryRes.data) {
        setDeliveryData(deliveryRes.data.breakdown || []);
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoading(false);
    }
  }, [dateRange]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const handleExport = async () => {
    setIsExporting(true);
    try {
      const response = await exportReport("campaigns");
      if (response.success) {
        toast({
          title: "Report exported",
          description: "Your report has been generated and is ready for download.",
        });
      }
    } catch (error) {
      handleApiError(error);
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
          <Button variant="outline" size="icon" onClick={loadData} disabled={loading}>
            <RefreshCw className={cn("h-4 w-4", loading && "animate-spin")} />
          </Button>
          <Link to="/reports/compare">
            <Button variant="outline" className="gap-2">
              <BarChart3 className="h-4 w-4" />
              Compare Campaigns
            </Button>
          </Link>
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
      {loading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-primary" />
        </div>
      ) : (
        <>
          {/* Summary Stats */}
          <div className="grid gap-6 md:grid-cols-4">
            <MetricCard
              title="Total Messages"
              value={stats.total_messages}
              change={`${stats.delivery_rate.toFixed(1)}% delivery rate`}
              changeType="positive"
              icon={MessageSquare}
              iconColor="primary"
            />
            <MetricCard
              title="Delivered"
              value={stats.delivered}
              change={`${((stats.delivered / stats.total_messages) * 100 || 0).toFixed(1)}% success rate`}
              changeType="positive"
              icon={CheckCircle}
              iconColor="success"
            />
            <MetricCard
              title="Failed"
              value={stats.failed}
              change={`${((stats.failed / stats.total_messages) * 100 || 0).toFixed(1)}% failure rate`}
              changeType={stats.failed > 0 ? "negative" : "positive"}
              icon={XCircle}
              iconColor="destructive"
            />
            <MetricCard
              title="Avg. Delivery Time"
              value={stats.avg_delivery_time}
              change="Average speed"
              changeType="neutral"
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
                    <h3 className="text-lg font-semibold text-foreground">Daily Message Volume</h3>
                    <p className="text-sm text-muted-foreground">SMS and Email messages sent per day</p>
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

                {chartData.length > 0 ? (
                  <ResponsiveContainer width="100%" height={350}>
                    <AreaChart data={chartData}>
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
                      <Area type="monotone" dataKey="sms" stroke="hsl(221, 83%, 53%)" strokeWidth={2} fill="url(#smsGrad)" />
                      <Area type="monotone" dataKey="email" stroke="hsl(173, 80%, 40%)" strokeWidth={2} fill="url(#emailGrad)" />
                    </AreaChart>
                  </ResponsiveContainer>
                ) : (
                  <div className="flex h-[350px] items-center justify-center">
                    <p className="text-muted-foreground">No data available for this period</p>
                  </div>
                )}
              </div>
            </TabsContent>

            <TabsContent value="delivery" className="mt-6">
              <div className="rounded-xl border border-border bg-card p-6">
                <div className="mb-6">
                  <h3 className="text-lg font-semibold text-foreground">Delivery Status Breakdown</h3>
                  <p className="text-sm text-muted-foreground">Message delivery status distribution</p>
                </div>

                {deliveryData.length > 0 ? (
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
                ) : (
                  <div className="flex h-[350px] items-center justify-center">
                    <p className="text-muted-foreground">No data available for this period</p>
                  </div>
                )}
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
                  <p className="text-sm text-muted-foreground">Last {dateRange === "24h" ? "24 hours" : dateRange === "7d" ? "7 days" : dateRange === "30d" ? "30 days" : "90 days"}</p>
                </div>
              </div>

              <div className="mt-6 space-y-4">
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Total Sent</span>
                  <span className="font-semibold text-foreground">{smsStats.total_sent.toLocaleString()}</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Delivered</span>
                  <span className="font-semibold text-success">
                    {smsStats.delivered.toLocaleString()} ({smsStats.total_sent > 0 ? ((smsStats.delivered / smsStats.total_sent) * 100).toFixed(1) : 0}%)
                  </span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Failed</span>
                  <span className="font-semibold text-destructive">
                    {smsStats.failed.toLocaleString()} ({smsStats.total_sent > 0 ? ((smsStats.failed / smsStats.total_sent) * 100).toFixed(1) : 0}%)
                  </span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Pending</span>
                  <span className="font-semibold text-warning">
                    {smsStats.pending.toLocaleString()} ({smsStats.total_sent > 0 ? ((smsStats.pending / smsStats.total_sent) * 100).toFixed(1) : 0}%)
                  </span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Credits Used</span>
                  <span className="font-semibold text-foreground">{smsStats.credits_used.toLocaleString()}</span>
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
                  <p className="text-sm text-muted-foreground">Last {dateRange === "24h" ? "24 hours" : dateRange === "7d" ? "7 days" : dateRange === "30d" ? "30 days" : "90 days"}</p>
                </div>
              </div>

              <div className="mt-6 space-y-4">
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Total Sent</span>
                  <span className="font-semibold text-foreground">{emailStats.total_sent.toLocaleString()}</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Delivered</span>
                  <span className="font-semibold text-success">
                    {emailStats.delivered.toLocaleString()} ({emailStats.total_sent > 0 ? ((emailStats.delivered / emailStats.total_sent) * 100).toFixed(1) : 0}%)
                  </span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Opened</span>
                  <span className="font-semibold text-info">
                    {emailStats.opened.toLocaleString()} ({emailStats.delivered > 0 ? ((emailStats.opened / emailStats.delivered) * 100).toFixed(1) : 0}%)
                  </span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Clicked</span>
                  <span className="font-semibold text-primary">
                    {emailStats.clicked.toLocaleString()} ({emailStats.opened > 0 ? ((emailStats.clicked / emailStats.opened) * 100).toFixed(1) : 0}%)
                  </span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Bounced</span>
                  <span className="font-semibold text-destructive">
                    {emailStats.bounced.toLocaleString()} ({emailStats.total_sent > 0 ? ((emailStats.bounced / emailStats.total_sent) * 100).toFixed(1) : 0}%)
                  </span>
                </div>
              </div>
            </div>
          </div>
        </>
      )}
    </DashboardLayout>
  );
}