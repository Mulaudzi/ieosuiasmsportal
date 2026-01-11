import { useState, useEffect, useCallback } from "react";
import { useParams, useNavigate, Link, useLocation } from "react-router-dom";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  ArrowLeft,
  MessageSquare,
  Mail,
  CheckCircle,
  XCircle,
  Clock,
  Search,
  Download,
  RefreshCw,
  Loader2,
  Users,
  TrendingUp,
  Pause,
  Play,
  RotateCcw,
} from "lucide-react";
import {
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell,
} from "recharts";
import { cn } from "@/lib/utils";
import { getSmsCampaign, getEmailCampaign, exportCampaignMessages, retryCampaign } from "@/lib/api";
import { toast } from "@/hooks/use-toast";
import { useDlrPolling } from "@/hooks/useDlrPolling";

interface MessageLog {
  id: string;
  recipient: string;
  status: "Delivered" | "Pending" | "Failed" | "Awaiting DLR" | "Opted-Out";
  sent_at: string;
  delivered_at?: string;
  cost: number;
  error_message?: string;
}

const statusColors = {
  Delivered: "hsl(142, 76%, 36%)",
  Pending: "hsl(38, 92%, 50%)",
  Failed: "hsl(0, 84%, 60%)",
  "Awaiting DLR": "hsl(221, 83%, 53%)",
  "Opted-Out": "hsl(215, 16%, 47%)",
};

const statusConfig = {
  Delivered: { class: "status-delivered", icon: CheckCircle },
  Pending: { class: "status-pending", icon: Clock },
  Failed: { class: "status-failed", icon: XCircle },
  "Awaiting DLR": { class: "status-queued", icon: Clock },
  "Opted-Out": { class: "bg-muted text-muted-foreground", icon: XCircle },
};

export default function CampaignDetails() {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();
  const navigate = useNavigate();
  const [campaign, setCampaign] = useState<any>(null);
  const [messages, setMessages] = useState<MessageLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [retrying, setRetrying] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [pollingEnabled, setPollingEnabled] = useState(true);

  // Determine type from URL path
  const isSms = location.pathname.includes("/sms-campaigns");
  const type = isSms ? "sms" : "email";

  // Real-time DLR polling every 10 seconds
  const handlePollingUpdate = useCallback((data: any) => {
    if (data?.campaign) {
      setCampaign((prev: any) => ({ ...prev, ...data.campaign }));
    }
    if (data?.messages) {
      setMessages(data.messages);
    }
  }, []);

  const { stopPolling, startPolling, refetch } = useDlrPolling({
    campaignId: id || "",
    enabled: pollingEnabled && !loading,
    interval: 10000,
    onUpdate: handlePollingUpdate,
    onError: (error) => {
      console.error("Polling error:", error);
    },
  });

  useEffect(() => {
    loadCampaign();
  }, [id, type]);

  const loadCampaign = async () => {
    setLoading(true);
    try {
      const fetchFn = isSms ? getSmsCampaign : getEmailCampaign;
      const response = await fetchFn(id || "");
      if (response.success && response.data) {
        const campaignData = response.data.campaign || response.data;
        setCampaign(campaignData);
        setMessages(campaignData.messages || []);
      }
    } catch (error) {
      toast({
        title: "Error loading campaign",
        description: "Please try again.",
        variant: "destructive",
      });
    } finally {
      setLoading(false);
    }
  };

  const handleRefresh = async () => {
    setRefreshing(true);
    await loadCampaign();
    await refetch();
    setRefreshing(false);
    toast({
      title: "Campaign refreshed",
      description: "Delivery status updated.",
    });
  };

  const handleExport = () => {
    exportCampaignMessages(id || "", type);
    toast({
      title: "Export started",
      description: "Your CSV download will begin shortly.",
    });
  };

  const handleRetryFailed = async () => {
    setRetrying(true);
    try {
      const response = await retryCampaign(id || "");
      if (response.success) {
        toast({
          title: "Retry initiated",
          description: `Retrying ${response.data?.retried || 0} failed messages.`,
        });
        await loadCampaign();
      }
    } catch (error) {
      toast({
        title: "Retry failed",
        description: "Could not retry failed messages.",
        variant: "destructive",
      });
    } finally {
      setRetrying(false);
    }
  };

  const togglePolling = () => {
    if (pollingEnabled) {
      stopPolling();
    } else {
      startPolling();
    }
    setPollingEnabled(!pollingEnabled);
  };

  const filteredLogs = messages.filter((log) => {
    const matchesSearch = log.recipient.toLowerCase().includes(searchQuery.toLowerCase());
    const matchesStatus = statusFilter === "all" || log.status === statusFilter;
    return matchesSearch && matchesStatus;
  });

  const statusBreakdown = [
    { name: "Delivered", value: campaign?.delivered_count || 0, color: statusColors.Delivered },
    { name: "Pending", value: campaign?.pending_count || 0, color: statusColors.Pending },
    { name: "Failed", value: campaign?.failed_count || 0, color: statusColors.Failed },
    { name: "Awaiting DLR", value: messages.filter(m => m.status === "Awaiting DLR").length, color: statusColors["Awaiting DLR"] },
  ].filter(s => s.value > 0);

  // Generate delivery timeline from actual messages
  const deliveryTimeline = (() => {
    if (!messages.length) return [];
    const timeline: Record<string, { time: string; delivered: number; pending: number }> = {};
    messages.forEach(msg => {
      const time = msg.sent_at ? new Date(msg.sent_at).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : 'N/A';
      if (!timeline[time]) timeline[time] = { time, delivered: 0, pending: 0 };
      if (msg.status === 'Delivered') timeline[time].delivered++;
      else timeline[time].pending++;
    });
    return Object.values(timeline).slice(0, 10);
  })();
  
  const failedCount = campaign?.failed_count || messages.filter(m => m.status === "Failed").length;

  if (loading) {
    return (
      <DashboardLayout title="Loading..." subtitle="">
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-primary" />
        </div>
      </DashboardLayout>
    );
  }

  return (
    <DashboardLayout
      title={campaign?.name || "Campaign Details"}
      subtitle={`Campaign ID: ${id}`}
      actions={
        <div className="flex flex-wrap gap-2">
          {failedCount > 0 && (
            <Button 
              variant="outline" 
              size="sm"
              className="gap-2" 
              onClick={handleRetryFailed}
              disabled={retrying}
            >
              {retrying ? <Loader2 className="h-4 w-4 animate-spin" /> : <RotateCcw className="h-4 w-4" />}
              Retry Failed ({failedCount})
            </Button>
          )}
          <Button 
            variant="outline" 
            size="sm"
            className="gap-2" 
            onClick={togglePolling}
          >
            {pollingEnabled ? <Pause className="h-4 w-4" /> : <Play className="h-4 w-4" />}
            {pollingEnabled ? "Pause" : "Resume"}
          </Button>
          <Button variant="outline" size="sm" className="gap-2" onClick={handleRefresh} disabled={refreshing}>
            {refreshing ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
            Refresh
          </Button>
          <Button variant="outline" size="sm" className="gap-2" onClick={handleExport}>
            <Download className="h-4 w-4" />
            Export CSV
          </Button>
        </div>
      }
    >
      {/* Back Button */}
      <div className="mb-6">
        <Link
          to={isSms ? "/sms-campaigns" : "/email-campaigns"}
          className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-4 w-4" />
          Back to {isSms ? "SMS" : "Email"} Campaigns
        </Link>
      </div>

      {/* Campaign Overview */}
      <div className="grid gap-6 lg:grid-cols-4">
        <div className="lg:col-span-3">
          <div className="rounded-xl border border-border bg-card p-6">
            <div className="flex items-start gap-4">
              <div className={cn(
                "flex h-12 w-12 items-center justify-center rounded-xl",
                isSms ? "bg-primary/10 text-primary" : "bg-accent/10 text-accent"
              )}>
                {isSms ? <MessageSquare className="h-6 w-6" /> : <Mail className="h-6 w-6" />}
              </div>
              <div className="flex-1">
                <div className="flex items-center gap-3">
                  <h2 className="text-xl font-semibold text-foreground">{campaign?.name}</h2>
                  <span className={cn("status-badge", statusConfig[campaign?.status as keyof typeof statusConfig]?.class || "status-delivered")}>
                    {campaign?.status}
                  </span>
                </div>
                <p className="mt-2 text-sm text-muted-foreground">
                  {campaign?.started_at ? `Sent on ${new Date(campaign.started_at).toLocaleString()}` : `Created ${new Date(campaign?.created_at).toLocaleString()}`}
                  {campaign?.sender_id && ` • Sender ID: ${campaign.sender_id}`}
                </p>
                <div className="mt-4 rounded-lg bg-muted/50 p-4">
                  <p className="text-sm text-foreground">{campaign?.message || "No message content"}</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Quick Stats */}
        <div className="space-y-4">
          <div className="rounded-xl border border-border bg-card p-4">
            <div className="flex items-center gap-3">
              <Users className="h-5 w-5 text-muted-foreground" />
              <div>
                <p className="text-sm text-muted-foreground">Recipients</p>
                <p className="text-xl font-bold text-foreground">{(campaign?.total_recipients || messages.length)?.toLocaleString()}</p>
              </div>
            </div>
          </div>
          <div className="rounded-xl border border-border bg-card p-4">
            <div className="flex items-center gap-3">
              <CheckCircle className="h-5 w-5 text-success" />
              <div>
                <p className="text-sm text-muted-foreground">Delivered</p>
                <p className="text-xl font-bold text-success">{(campaign?.delivered_count || 0)?.toLocaleString()}</p>
              </div>
            </div>
          </div>
          <div className="rounded-xl border border-border bg-card p-4">
            <div className="flex items-center gap-3">
              <TrendingUp className="h-5 w-5 text-primary" />
              <div>
                <p className="text-sm text-muted-foreground">Delivery Rate</p>
                <p className="text-xl font-bold text-foreground">
                  {campaign?.total_recipients ? (((campaign?.delivered_count || 0) / campaign.total_recipients) * 100).toFixed(1) : 0}%
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <Tabs defaultValue="overview" className="mt-8">
        <TabsList>
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="logs">Message Logs</TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="mt-6">
          <div className="grid gap-6 lg:grid-cols-2">
            {/* Delivery Timeline */}
            <div className="rounded-xl border border-border bg-card p-6">
              <h3 className="text-lg font-semibold text-foreground">Delivery Timeline</h3>
              <p className="text-sm text-muted-foreground">Messages delivered over time</p>
              <div className="mt-6">
                <ResponsiveContainer width="100%" height={250}>
                  <AreaChart data={deliveryTimeline}>
                    <defs>
                      <linearGradient id="deliveredGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="hsl(142, 76%, 36%)" stopOpacity={0.3} />
                        <stop offset="95%" stopColor="hsl(142, 76%, 36%)" stopOpacity={0} />
                      </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="hsl(215, 20%, 91%)" />
                    <XAxis dataKey="time" stroke="hsl(215, 16%, 47%)" fontSize={12} />
                    <YAxis stroke="hsl(215, 16%, 47%)" fontSize={12} />
                    <Tooltip
                      contentStyle={{
                        backgroundColor: "hsl(0, 0%, 100%)",
                        border: "1px solid hsl(215, 20%, 91%)",
                        borderRadius: "0.5rem",
                      }}
                    />
                    <Area
                      type="monotone"
                      dataKey="delivered"
                      stroke="hsl(142, 76%, 36%)"
                      strokeWidth={2}
                      fill="url(#deliveredGrad)"
                    />
                  </AreaChart>
                </ResponsiveContainer>
              </div>
            </div>

            {/* Status Breakdown */}
            <div className="rounded-xl border border-border bg-card p-6">
              <h3 className="text-lg font-semibold text-foreground">Status Breakdown</h3>
              <p className="text-sm text-muted-foreground">Message delivery status distribution</p>
              <div className="mt-6 flex items-center gap-8">
                <ResponsiveContainer width={180} height={180}>
                  <PieChart>
                    <Pie
                      data={statusBreakdown}
                      cx="50%"
                      cy="50%"
                      innerRadius={50}
                      outerRadius={80}
                      paddingAngle={2}
                      dataKey="value"
                    >
                      {statusBreakdown.map((entry, index) => (
                        <Cell key={`cell-${index}`} fill={entry.color} />
                      ))}
                    </Pie>
                    <Tooltip />
                  </PieChart>
                </ResponsiveContainer>
                <div className="space-y-3">
                  {statusBreakdown.map((item) => (
                    <div key={item.name} className="flex items-center gap-3">
                      <div
                        className="h-3 w-3 rounded-full"
                        style={{ backgroundColor: item.color }}
                      />
                      <span className="text-sm text-muted-foreground">{item.name}</span>
                      <span className="font-medium text-foreground">{item.value.toLocaleString()}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </TabsContent>

        <TabsContent value="logs" className="mt-6">
          {/* Filters */}
          <div className="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="relative flex-1 max-w-md">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search by phone number..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-10"
              />
            </div>
            <Select value={statusFilter} onValueChange={setStatusFilter}>
              <SelectTrigger className="w-40">
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="Delivered">Delivered</SelectItem>
                <SelectItem value="Pending">Pending</SelectItem>
                <SelectItem value="Failed">Failed</SelectItem>
                <SelectItem value="Awaiting DLR">Awaiting DLR</SelectItem>
                <SelectItem value="Opted-Out">Opted-Out</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Message Logs Table */}
          <div className="rounded-xl border border-border bg-card overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-border bg-muted/50">
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Recipient</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Status</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Sent At</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Delivered At</th>
                    <th className="px-6 py-4 text-right text-sm font-medium text-muted-foreground">Cost</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {filteredLogs.map((log) => {
                    const StatusIcon = statusConfig[log.status]?.icon || Clock;
                    return (
                      <tr key={log.id} className="transition-colors hover:bg-muted/30">
                        <td className="px-6 py-4 font-medium text-foreground">{log.recipient}</td>
                        <td className="px-6 py-4">
                          <span className={cn("status-badge inline-flex items-center gap-1", statusConfig[log.status]?.class)}>
                            <StatusIcon className="h-3 w-3" />
                            {log.status}
                          </span>
                        </td>
                        <td className="px-6 py-4 text-muted-foreground">{log.sent_at || "—"}</td>
                        <td className="px-6 py-4 text-muted-foreground">{log.delivered_at || "—"}</td>
                        <td className="px-6 py-4 text-right text-foreground">
                          {log.cost > 0 ? `R${Number(log.cost).toFixed(2)}` : "—"}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>

          {/* Pagination */}
          <div className="mt-4 flex items-center justify-between">
            <p className="text-sm text-muted-foreground">
              Showing {filteredLogs.length} of {messages.length} messages
            </p>
            <div className="flex items-center gap-2">
              <Button variant="outline" size="sm" disabled>
                Previous
              </Button>
              <Button variant="outline" size="sm">
                Next
              </Button>
            </div>
          </div>
        </TabsContent>
      </Tabs>
    </DashboardLayout>
  );
}
