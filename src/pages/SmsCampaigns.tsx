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
  Copy,
  Trash2,
  Calendar,
  Loader2,
  RefreshCw,
} from "lucide-react";
import { Link, useNavigate } from "react-router-dom";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { getSmsCampaigns, deleteCampaign, duplicateCampaign, handleApiError } from "@/lib/api";
import { format } from "date-fns";

interface Campaign {
  id: string;
  name: string;
  status: "delivered" | "pending" | "queued" | "failed" | "draft" | "sending";
  recipient_count: number;
  delivered_count: number;
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
};

export default function SmsCampaigns() {
  const navigate = useNavigate();
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [stats, setStats] = useState<CampaignStats>({ total: 0, sent: 0, scheduled: 0, credits_used: 0 });
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [loadingAction, setLoadingAction] = useState<string | null>(null);

  const loadCampaigns = useCallback(async () => {
    setLoading(true);
    try {
      const response = await getSmsCampaigns({
        status: statusFilter !== "all" ? statusFilter : undefined,
        search: searchQuery || undefined,
      });
      
      if (response.success && response.data) {
        setCampaigns(response.data.campaigns || []);
        setStats(response.data.stats || { total: 0, sent: 0, scheduled: 0, credits_used: 0 });
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoading(false);
    }
  }, [statusFilter, searchQuery]);

  useEffect(() => {
    loadCampaigns();
  }, [loadCampaigns]);

  const handleView = (id: string) => {
    navigate(`/sms-campaigns/${id}`);
  };

  const handleDuplicate = async (id: string) => {
    setLoadingAction(`copy-${id}`);
    try {
      const response = await duplicateCampaign(id, 'sms');
      if (response.success) {
        toast({
          title: "Campaign duplicated",
          description: "New campaign created successfully.",
        });
        loadCampaigns();
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoadingAction(null);
    }
  };

  const handleDelete = async (id: string) => {
    setLoadingAction(`delete-${id}`);
    try {
      const response = await deleteCampaign(id, 'sms');
      if (response.success) {
        toast({
          title: "Campaign deleted",
          description: "The campaign has been removed.",
        });
        loadCampaigns();
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoadingAction(null);
    }
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
          <Button variant="outline" size="icon" onClick={loadCampaigns} disabled={loading}>
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
          <p className="mt-1 text-2xl font-bold">{stats.sent.toLocaleString()}</p>
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm text-muted-foreground">Scheduled</p>
          <p className="mt-1 text-2xl font-bold">{stats.scheduled}</p>
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm text-muted-foreground">Credits Used</p>
          <p className="mt-1 text-2xl font-bold">{stats.credits_used.toLocaleString()}</p>
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
                  <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Delivered</th>
                  <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Credits</th>
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
                    <td className="px-6 py-4 text-foreground">{campaign.recipient_count.toLocaleString()}</td>
                    <td className="px-6 py-4 text-foreground">{campaign.delivered_count.toLocaleString()}</td>
                    <td className="px-6 py-4 text-foreground">{campaign.credits_used.toLocaleString()}</td>
                    <td className="px-6 py-4 text-muted-foreground">{formatDate(campaign.created_at)}</td>
                    <td className="px-6 py-4">
                      <div className="flex items-center justify-end gap-1">
                        <Button variant="ghost" size="icon" onClick={() => handleView(campaign.id)}>
                          <Eye className="h-4 w-4" />
                        </Button>
                        <Button 
                          variant="ghost" 
                          size="icon" 
                          onClick={() => handleDuplicate(campaign.id)}
                          disabled={loadingAction === `copy-${campaign.id}`}
                        >
                          {loadingAction === `copy-${campaign.id}` ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                          ) : (
                            <Copy className="h-4 w-4" />
                          )}
                        </Button>
                        <Button 
                          variant="ghost" 
                          size="icon" 
                          onClick={() => handleDelete(campaign.id)}
                          disabled={loadingAction === `delete-${campaign.id}`}
                        >
                          {loadingAction === `delete-${campaign.id}` ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                          ) : (
                            <Trash2 className="h-4 w-4 text-destructive" />
                          )}
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