import { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import { MessageSquare, Mail, MoreHorizontal, Eye, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api";
import { formatDistanceToNow } from "date-fns";

interface Campaign {
  id: string;
  name: string;
  type: "sms" | "email";
  status: string;
  recipients: number;
  sent_count: number;
  delivered_count: number;
  failed_count: number;
  created_at: string;
}

const statusConfig: Record<string, { label: string; class: string }> = {
  Delivered: { label: "Delivered", class: "status-delivered" },
  Sent: { label: "Sent", class: "status-delivered" },
  Sending: { label: "Sending", class: "status-pending" },
  Scheduled: { label: "Scheduled", class: "status-queued" },
  Draft: { label: "Draft", class: "status-queued" },
  Failed: { label: "Failed", class: "status-failed" },
  Cancelled: { label: "Cancelled", class: "status-failed" },
};

export function RecentCampaigns() {
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    loadCampaigns();
  }, []);

  const loadCampaigns = async () => {
    try {
      const response = await api.get<{ campaigns: Campaign[] }>("/dashboard/recent-campaigns");
      if (response.success && response.data?.campaigns) {
        setCampaigns(response.data.campaigns);
      }
    } catch (error) {
      console.error("Failed to load recent campaigns:", error);
      setCampaigns([]);
    } finally {
      setLoading(false);
    }
  };

  const formatDate = (dateString: string) => {
    try {
      return formatDistanceToNow(new Date(dateString), { addSuffix: true });
    } catch {
      return dateString;
    }
  };

  if (loading) {
    return (
      <div className="metric-card flex items-center justify-center h-[300px]">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (campaigns.length === 0) {
    return (
      <div className="metric-card">
        <div className="mb-6 flex items-center justify-between">
          <div>
            <h3 className="text-lg font-semibold text-foreground">Recent Campaigns</h3>
            <p className="text-sm text-muted-foreground">Your latest messaging campaigns</p>
          </div>
          <Link to="/sms-campaigns">
            <Button variant="outline" size="sm">View All</Button>
          </Link>
        </div>
        <div className="flex items-center justify-center h-40 text-muted-foreground">
          <div className="text-center">
            <MessageSquare className="h-12 w-12 mx-auto mb-3 opacity-50" />
            <p>No campaigns yet</p>
            <Link to="/sms-campaigns/new" className="text-primary hover:underline text-sm">
              Create your first campaign
            </Link>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="metric-card">
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h3 className="text-lg font-semibold text-foreground">Recent Campaigns</h3>
          <p className="text-sm text-muted-foreground">Your latest messaging campaigns</p>
        </div>
        <Link to="/sms-campaigns">
          <Button variant="outline" size="sm">View All</Button>
        </Link>
      </div>

      <div className="space-y-4">
        {campaigns.map((campaign) => {
          const status = statusConfig[campaign.status] || { label: campaign.status, class: "status-queued" };
          const campaignType = campaign.type || "sms";
          
          return (
            <div
              key={campaign.id}
              className="flex items-center gap-4 rounded-lg border border-border p-4 transition-colors hover:bg-muted/50"
            >
              <div
                className={cn(
                  "flex h-10 w-10 items-center justify-center rounded-lg",
                  campaignType === "sms"
                    ? "bg-primary/10 text-primary"
                    : "bg-accent/10 text-accent"
                )}
              >
                {campaignType === "sms" ? (
                  <MessageSquare className="h-5 w-5" />
                ) : (
                  <Mail className="h-5 w-5" />
                )}
              </div>

              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                  <p className="truncate font-medium text-foreground">{campaign.name}</p>
                  <span className={cn("status-badge", status.class)}>{status.label}</span>
                </div>
                <p className="mt-0.5 text-sm text-muted-foreground">
                  {(campaign.delivered_count || 0).toLocaleString()} / {(campaign.sent_count || campaign.recipients || 0).toLocaleString()} recipients • {formatDate(campaign.created_at)}
                </p>
              </div>

              <div className="flex items-center gap-2">
                <Link to={`/${campaignType}-campaigns/${campaign.id}`}>
                  <Button variant="ghost" size="icon">
                    <Eye className="h-4 w-4" />
                  </Button>
                </Link>
                <Button variant="ghost" size="icon">
                  <MoreHorizontal className="h-4 w-4" />
                </Button>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
