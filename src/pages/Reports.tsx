import { useState, useEffect, useCallback, useRef } from "react";
import { typicalTime } from '@/lib/portal-presentation';
import { DeliveryStatusHelp } from "@/components/dashboard/DeliveryStatusHelp";
import { deliveryChartStyle } from '@/lib/delivery-presentation';
import { DeliveryOutcomeList } from '@/components/dashboard/DeliveryOutcomeList';
import { Link, useSearchParams } from "react-router-dom";
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
  Cell,
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
import { api, getReportStats, getReportChartData, getDeliveryBreakdown, exportReport, emailReport, handleApiError } from "@/lib/api";

interface ReportStats {
  total_messages: number;
  delivered: number;
  failed: number;
  pending: number;
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
  skipped: number;
  total_sent: number;
  delivered: number;
  failed: number;
  pending: number;
  awaiting_delivery: number;
  dlr_unavailable: number;
  credits_used: number;
}

export default function Reports() {
  const [urlParams]=useSearchParams();const downloaded=useRef(false);
  const [dateRange, setDateRange] = useState(()=>["24h","7d","30d","90d"].includes(urlParams.get("range")||"")?urlParams.get("range")!:"7d");
  const [loading, setLoading] = useState(true);
  const [typical,setTypical]=useState<string|null>(null);
  useEffect(()=>{let active=true;setTypical(null);const load=async()=>{try{const r=await api.get<{seconds:number|null}>('/reports/confirmation-time',{range:dateRange});if(active)setTypical(r.success?typicalTime(((r.data??r) as {seconds:number|null}).seconds):null);}catch{if(active)setTypical(null);}};void load();const timer=setInterval(()=>void load(),30000);return()=>{active=false;clearInterval(timer);};},[dateRange]);
  const [isExporting, setIsExporting] = useState(false);
  const [exportFormat,setExportFormat]=useState<'csv'|'excel'|'pdf'>('pdf');
  const [isEmailing,setIsEmailing]=useState(false);
  const [stats, setStats] = useState<ReportStats>({
    total_messages: 0,
    delivered: 0,
    failed: 0,
    pending: 0,
    avg_delivery_time: "0s",
    delivery_rate: 0,
  });
  const [chartData, setChartData] = useState<ChartDataPoint[]>([]);
  const [deliveryData, setDeliveryData] = useState<DeliveryData[]>([]);
  const [smsStats, setSmsStats] = useState<SmsStats>({
    skipped: 0,
    total_sent: 0,
    delivered: 0,
    failed: 0,
    pending: 0,
    awaiting_delivery: 0,
    dlr_unavailable: 0,
    credits_used: 0,
  });

  const loadData = useCallback(async (showLoading = true) => {
    if (showLoading) setLoading(true);
    try {
      const [statsRes, chartRes, deliveryRes] = await Promise.all([
        getReportStats(dateRange),
        getReportChartData(dateRange),
        getDeliveryBreakdown(dateRange),
      ]);

      if (statsRes.success) {
        const payload = statsRes.data ?? statsRes;
        if (payload.summary) setStats(payload.summary);
        if (payload.sms) setSmsStats(payload.sms);
      }

      if (chartRes.success) {
        const payload = chartRes.data ?? chartRes;
        setChartData(payload.chart || []);
      }

      if (deliveryRes.success) {
        const payload = deliveryRes.data ?? deliveryRes;
        setDeliveryData((payload.breakdown || []).map(row=>({...row,status:deliveryChartStyle(row.status).label})).filter(row=>Number(row.count)>0 && !['Waiting to send','Pending dispatch'].includes(row.status)));
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      if (showLoading) setLoading(false);
    }
  }, [dateRange]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  useEffect(() => {
    const timer = window.setInterval(() => void loadData(false), 30000);
    return () => window.clearInterval(timer);
  }, [loadData]);

  useEffect(()=>{const format=urlParams.get('download');if(!downloaded.current&&(format==='csv'||format==='pdf')){downloaded.current=true;void exportReport('campaigns',format,dateRange).catch(handleApiError);}},[urlParams,dateRange]);

  const handleExport = async () => {
    setIsExporting(true);
    try {
      const response = await exportReport("campaigns",exportFormat,dateRange);
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

  const handleEmail=async()=>{setIsEmailing(true);try{await emailReport(dateRange);toast({title:"Report emailed",description:"The SMS report was sent to your registered email address."});}catch(error){handleApiError(error);}finally{setIsEmailing(false)}};

  return (
    <DashboardLayout
      title="Reports"
      subtitle="Analytics and delivery reports for all your campaigns"
      actions={
        <div className="flex flex-wrap gap-3">
          <Button aria-label="Refresh reports" variant="outline" size="icon" onClick={() => void loadData()} disabled={loading}>
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
          <Select value={exportFormat} onValueChange={value=>setExportFormat(value as 'csv'|'excel'|'pdf')}><SelectTrigger className="w-28"><SelectValue/></SelectTrigger><SelectContent><SelectItem value="pdf">PDF</SelectItem><SelectItem value="csv">CSV</SelectItem><SelectItem value="excel">Excel</SelectItem></SelectContent></Select>
          <Button variant="outline" className="gap-2" onClick={handleExport} disabled={isExporting}>
            {isExporting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
            Export Report
          </Button>
          <Button variant="outline" className="gap-2" onClick={handleEmail} disabled={isEmailing}>{isEmailing?<Loader2 className="h-4 w-4 animate-spin"/>:<Mail className="h-4 w-4"/>}Email Report</Button>
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
          <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-5">
            <MetricCard
              title="Total Sent"
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
              title="Not Delivered"
              value={stats.failed}
              change={`${((stats.failed / stats.total_messages) * 100 || 0).toFixed(1)}% failure rate`}
              changeType={stats.failed > 0 ? "negative" : "positive"}
              icon={XCircle}
              iconColor="destructive"
            />
            <MetricCard
              title="Delivery not yet confirmed"
              value={stats.pending}
              change={`${((stats.pending / stats.total_messages) * 100 || 0).toFixed(1)}% pending`}
              changeType="neutral"
              icon={Clock}
              iconColor="info"
            />
            {typical&&<MetricCard
              title="Typical confirmation time"
              value={typical}
              change={`Most messages confirmed within ${typical}`}
              changeType="neutral"
              icon={Clock}
              iconColor="info"
            />}
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
                    <p className="text-sm text-muted-foreground">SMS messages sent per day</p>
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
                    <p className="text-muted-foreground">No messages in this period</p>
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
                      <YAxis dataKey="status" type="category" stroke="hsl(215, 16%, 47%)" fontSize={11} width={175} />
                      <Tooltip
                        contentStyle={{
                          backgroundColor: "hsl(0, 0%, 100%)",
                          border: "1px solid hsl(215, 20%, 91%)",
                          borderRadius: "0.5rem",
                        }}
                      />
                      <Bar dataKey="count" name="count" radius={[0, 4, 4, 0]}>{deliveryData.map(row=><Cell key={row.status} fill={deliveryChartStyle(row.status).color}/>)}</Bar>
                    </BarChart>
                  </ResponsiveContainer>
                ) : (
                  <div className="flex h-[350px] items-center justify-center">
                    <p className="text-muted-foreground">No messages in this period</p>
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
                <DeliveryOutcomeList counts={[smsStats.delivered,smsStats.awaiting_delivery,smsStats.dlr_unavailable,smsStats.failed,smsStats.skipped ?? 0]}/>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">SMS Credits Used</span>
                  <span className="font-semibold text-foreground">{smsStats.credits_used.toLocaleString()}</span>
                </div>
              </div>
              <p className="mt-3 text-xs text-muted-foreground">Skipped messages were not sent and are excluded from Total Sent. The unconfirmed total includes messages waiting to send and both confirmation states.</p>
              <DeliveryStatusHelp />
            </div>

            {/* Email Stats */}
            <div className="rounded-xl border border-border bg-card p-6">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-accent/10 text-accent">
                  <Mail className="h-5 w-5" />
                </div>
                <div>
                  <h3 className="font-semibold text-foreground">Email Performance</h3>
                  <p className="text-sm text-muted-foreground">Coming soon</p>
                </div>
              </div>

              <div className="mt-6 rounded-lg border border-dashed p-6 text-center">
                <p className="font-medium">Email reporting is coming soon</p>
                <p className="mt-2 text-sm text-muted-foreground">This release reports only canonical SMS campaign activity.</p>
              </div>
            </div>
          </div>
        </>
      )}
    </DashboardLayout>
  );
}
