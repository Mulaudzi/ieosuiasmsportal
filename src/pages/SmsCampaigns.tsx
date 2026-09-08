import { useState, useEffect, useCallback } from "react";
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
import {
  Plus,
  Search,
  Filter,
  MessageSquare,
  Eye,
  Calendar,
  Loader2,
  RefreshCw,
} from "lucide-react";
import { Link, useNavigate } from "react-router-dom";
import { cn } from "@/lib/utils";
import { getSmsCampaigns, handleApiError } from "@/lib/api";
import { format } from "date-fns";

interface Campaign {
  id: string;
  name: string;
  status: "draft" | "scheduled" | "queued" | "processing" | "completed" | "partially_failed" | "failed" | "cancelled";
  recipient_count: number;
  delivered_count: number;
  awaiting_delivery_count: number;
  failed_count: number;
  skipped_count: number;
  dlr_unavailable_count: number;
  credits_used: number;
  created_at: string;
  scheduled_at?: string;
}

interface CampaignStats {
  total: number;
  sent: number;
  scheduled: number;
  credits_used: number;
}

const statusConfig: Record<string, { label: string; class: string }> = {
  delivered: { label: "Delivered", class: "status-delivered" },
  pending: { label: "Pending", class: "status-pending" },
  sending: { label: "Sending", class: "status-pending" },
  queued: { label: "Scheduled", class: "status-queued" },
  failed: { label: "Failed", class: "status-failed" },
  draft: { label: "Draft", class: "bg-muted text-muted-foreground" },
  scheduled: { label: "Scheduled", class: "status-queued" },
  processing: { label: "Processing", class: "status-pending" },
  completed: { label: "Dispatch complete", class: "status-delivered" },
  partially_failed: { label: "Partially failed", class: "status-failed" },
  cancelled: { label: "Cancelled", class: "bg-muted text-muted-foreground" },
};

export default function SmsCampaigns() {
  const navigate = useNavigate();
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [stats, setStats] = useState<CampaignStats>({ total: 0, sent: 0, scheduled: 0, credits_used: 0 });
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const count = (value: unknown) => Number.isFinite(Number(value)) ? Number(value) : 0;

  const loadCampaigns = useCallback(async (showLoading=true) => {
    if(showLoading)setLoading(true);
    try {
      const response = await getSmsCampaigns({
        status: statusFilter !== "all" ? statusFilter : undefined,
        search: searchQuery || undefined,
      });
      
      if (response.success) {
        const data = response as any;
        setCampaigns(data.campaigns || []);
        setStats(data.stats || { total: 0, sent: 0, scheduled: 0, credits_used: 0 });
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      if(showLoading)setLoading(false);
    }
  }, [statusFilter, searchQuery]);

  useEffect(() => {
    void loadCampaigns();
    const timer=window.setInterval(()=>void loadCampaigns(false),30000);
    return()=>window.clearInterval(timer);
  }, [loadCampaigns]);

  const handleView = (id: string) => {
    navigate(`/sms-campaigns/${id}`);
  };


  const formatDate = (dateString: string) => {
    try {
      return format(new Date(dateString), "MMM d, yyyy");
    } catch {
      return dateString;
    }
  };

  const formatScheduledDate = (dateString: string) => {
    try {
      return format(new Date(dateString), "MMM d, yyyy h:mm a");
    } catch {
      return dateString;
    }
  };

  return (
    <DashboardLayout
      title="SMS Campaigns"
      subtitle="Create and manage your SMS campaigns"
      actions={
        <div className="flex gap-3">
          <Button variant="outline" size="icon" onClick={()=>void loadCampaigns()} disabled={loading}>
            <RefreshCw className={cn("h-4 w-4", loading && "animate-spin")} />
          </Button>
          <Link to="/sms-campaigns/new">
            <Button className="gap-2">
              <Plus className="h-4 w-4" />
              Create Campaign
            </Button>
          </Link>
        </div>
      }
    >
      {/* Filters */}
      <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="relative flex-1 max-w-md">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Search campaigns..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="pl-10"
          />
        </div>
        <div className="flex items-center gap-3">
          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="w-40">
              <Filter className="mr-2 h-4 w-4" />
              <SelectValue placeholder="Status" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Status</SelectItem>
              <SelectItem value="delivered">Delivered</SelectItem>
              <SelectItem value="pending">Pending</SelectItem>
              <SelectItem value="sending">Sending</SelectItem>
              <SelectItem value="queued">Scheduled</SelectItem>
              <SelectItem value="failed">Failed</SelectItem>
              <SelectItem value="draft">Draft</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      {/* Campaign Stats */}
      <div className="mb-6 grid gap-4 sm:grid-cols-4">
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm text-muted-foreground">Total Campaigns</p>
          <p className="mt-1 text-2xl font-bold">{stats.total}</p>
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm text-muted-foreground">Messages Sent</p>
          <p className="mt-1 text-2xl font-bold">{count(stats.sent).toLocaleString()}</p>
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm text-muted-foreground">Scheduled</p>
          <p className="mt-1 text-2xl font-bold">{stats.scheduled}</p>
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm text-muted-foreground">SMS Credits Used</p>
          <p className="mt-1 text-2xl font-bold">{count(stats.credits_used).toLocaleString()}</p>
        </div>
      </div>

      {/* Campaigns Table */}
      <div className="rounded-xl border border-border bg-card overflow-hidden">
        {loading ? (
          <div className="flex h-64 items-center justify-center">
            <Loader2 className="h-8 w-8 animate-spin text-primary" />
          </div>
        ) : campaigns.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-12">
            <MessageSquare className="h-12 w-12 text-muted-foreground/50" />
            <p className="mt-4 text-lg font-medium text-foreground">No campaigns found</p>
            <p className="mt-1 text-sm text-muted-foreground">
              {searchQuery || statusFilter !== "all" ? "Try adjusting your filters" : "Create your first SMS campaign"}
            </p>
            {!searchQuery && statusFilter === "all" && (
              <Link to="/sms-campaigns/new">
                <Button className="mt-4 gap-2">
                  <Plus className="h-4 w-4" />
                  Create Campaign
                </Button>
              </Link>
            )}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-border bg-muted/50">
                  <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Campaign</th>
                  <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Status</th>
                  <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Recipients</th>
                  <th className="px-4 py-4 text-left text-sm font-medium text-muted-foreground">Delivery breakdown</th>
                  <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">SMS Credits Used</th>
                  <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Created</th>
                  <th className="px-6 py-4 text-right text-sm font-medium text-muted-foreground">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {campaigns.map((campaign) => (
                  <tr key={campaign.id} className="transition-colors hover:bg-muted/30">
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                          <MessageSquare className="h-5 w-5" />
                        </div>
                        <div>
                          <p className="font-medium text-foreground">{campaign.name}</p>
                          {campaign.scheduled_at && (
                            <p className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                              <Calendar className="h-3 w-3" />
                              {formatScheduledDate(campaign.scheduled_at)}
                            </p>
                          )}
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <span className={cn("status-badge", statusConfig[campaign.status]?.class || "bg-muted")}>
                        {statusConfig[campaign.status]?.label || campaign.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-foreground">{count(campaign.recipient_count).toLocaleString()}</td>
                    <td className="px-4 py-4"><div className="flex min-w-max flex-wrap gap-2 text-xs"><span className="rounded-full bg-success/10 px-2 py-1 text-success">Delivered {count(campaign.delivered_count)}</span><span className="rounded-full bg-warning/10 px-2 py-1 text-warning">Awaiting DLR {count(campaign.awaiting_delivery_count)}</span>{count(campaign.dlr_unavailable_count)>0&&<span className="rounded-full bg-destructive/10 px-2 py-1 text-destructive">DLR unavailable {count(campaign.dlr_unavailable_count)}</span>}{count(campaign.failed_count)>0&&<span className="rounded-full bg-destructive/10 px-2 py-1 text-destructive">Failed {count(campaign.failed_count)}</span>}{count(campaign.skipped_count)>0&&<span className="rounded-full bg-muted px-2 py-1 text-muted-foreground">Skipped {count(campaign.skipped_count)}</span>}</div></td>
                    <td className="px-6 py-4 text-foreground">{count(campaign.credits_used).toLocaleString()}</td>
                    <td className="px-6 py-4 text-muted-foreground">{formatDate(campaign.created_at)}</td>
                    <td className="px-6 py-4">
                      <div className="flex items-center justify-end gap-1">
                        <Button variant="ghost" size="icon" onClick={() => handleView(campaign.id)}>
                          <Eye className="h-4 w-4" />
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </DashboardLayout>
  );
}
