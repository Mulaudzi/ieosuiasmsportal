import { useState, useEffect } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
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
import { getCampaign } from "@/lib/api";
import { toast } from "@/hooks/use-toast";

interface MessageLog {
  id: string;
  recipient: string;
  status: "Delivered" | "Pending" | "Failed" | "Awaiting DLR" | "Opted-Out";
  sentAt: string;
  deliveredAt?: string;
  cost: number;
}

const mockMessageLogs: MessageLog[] = [
  { id: "1", recipient: "+27 82 123 4567", status: "Delivered", sentAt: "2026-01-07 10:30:00", deliveredAt: "2026-01-07 10:30:02", cost: 0.12 },
  { id: "2", recipient: "+27 83 234 5678", status: "Delivered", sentAt: "2026-01-07 10:30:01", deliveredAt: "2026-01-07 10:30:03", cost: 0.12 },
  { id: "3", recipient: "+27 84 345 6789", status: "Failed", sentAt: "2026-01-07 10:30:02", cost: 0 },
  { id: "4", recipient: "+27 85 456 7890", status: "Pending", sentAt: "2026-01-07 10:30:03", cost: 0.12 },
  { id: "5", recipient: "+27 86 567 8901", status: "Awaiting DLR", sentAt: "2026-01-07 10:30:04", cost: 0.12 },
  { id: "6", recipient: "+27 87 678 9012", status: "Delivered", sentAt: "2026-01-07 10:30:05", deliveredAt: "2026-01-07 10:30:07", cost: 0.12 },
  { id: "7", recipient: "+27 88 789 0123", status: "Opted-Out", sentAt: "2026-01-07 10:30:06", cost: 0 },
  { id: "8", recipient: "+27 89 890 1234", status: "Delivered", sentAt: "2026-01-07 10:30:07", deliveredAt: "2026-01-07 10:30:09", cost: 0.12 },
];

const deliveryTimeline = [
  { time: "10:30", delivered: 0, pending: 1250 },
  { time: "10:31", delivered: 320, pending: 930 },
  { time: "10:32", delivered: 680, pending: 570 },
  { time: "10:33", delivered: 950, pending: 300 },
  { time: "10:34", delivered: 1120, pending: 130 },
  { time: "10:35", delivered: 1180, pending: 70 },
];

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
  const { id, type } = useParams<{ id: string; type: string }>();
  const navigate = useNavigate();
  const [campaign, setCampaign] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");

  const isSms = type === "sms";

  useEffect(() => {
    loadCampaign();
  }, [id]);

  const loadCampaign = async () => {
    try {
      const response = await getCampaign(id || "");
      if (response.success && response.data) {
        setCampaign({
          ...response.data,
          message: "🔥 Flash Sale! Get 50% off all items for the next 24 hours. Shop now at example.com/sale",
          senderId: "IEOSUIA",
          sentAt: "Jan 7, 2026 10:30 AM",
        });
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
    setRefreshing(false);
    toast({
      title: "Campaign refreshed",
      description: "Delivery status updated.",
    });
  };

  const filteredLogs = mockMessageLogs.filter((log) => {
    const matchesSearch = log.recipient.includes(searchQuery);
    const matchesStatus = statusFilter === "all" || log.status === statusFilter;
    return matchesSearch && matchesStatus;
  });

  const statusBreakdown = [
    { name: "Delivered", value: campaign?.delivered || 1180, color: statusColors.Delivered },
    { name: "Pending", value: 45, color: statusColors.Pending },
    { name: "Failed", value: campaign?.failed || 70, color: statusColors.Failed },
    { name: "Awaiting DLR", value: 25, color: statusColors["Awaiting DLR"] },
  ];

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
        <div className="flex gap-3">
          <Button variant="outline" className="gap-2" onClick={handleRefresh} disabled={refreshing}>
            {refreshing ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
            Refresh
          </Button>
          <Button variant="outline" className="gap-2">
            <Download className="h-4 w-4" />
            Export Report
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
                  Sent on {campaign?.sentAt} • Sender ID: {campaign?.senderId}
                </p>
                <div className="mt-4 rounded-lg bg-muted/50 p-4">
                  <p className="text-sm text-foreground">{campaign?.message}</p>
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
                <p className="text-xl font-bold text-foreground">{campaign?.recipients?.toLocaleString()}</p>
              </div>
            </div>
          </div>
          <div className="rounded-xl border border-border bg-card p-4">
            <div className="flex items-center gap-3">
              <CheckCircle className="h-5 w-5 text-success" />
              <div>
                <p className="text-sm text-muted-foreground">Delivered</p>
                <p className="text-xl font-bold text-success">{campaign?.delivered?.toLocaleString()}</p>
              </div>
            </div>
          </div>
          <div className="rounded-xl border border-border bg-card p-4">
            <div className="flex items-center gap-3">
              <TrendingUp className="h-5 w-5 text-primary" />
              <div>
                <p className="text-sm text-muted-foreground">Delivery Rate</p>
                <p className="text-xl font-bold text-foreground">
                  {((campaign?.delivered / campaign?.recipients) * 100).toFixed(1)}%
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
                        <td className="px-6 py-4 text-muted-foreground">{log.sentAt}</td>
                        <td className="px-6 py-4 text-muted-foreground">{log.deliveredAt || "—"}</td>
                        <td className="px-6 py-4 text-right text-foreground">
                          {log.cost > 0 ? `R${log.cost.toFixed(2)}` : "—"}
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
              Showing {filteredLogs.length} of {mockMessageLogs.length} messages
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
