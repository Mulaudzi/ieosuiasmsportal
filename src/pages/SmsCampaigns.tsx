import { useState } from "react";
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
} from "lucide-react";
import { Link, useNavigate } from "react-router-dom";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { deleteCampaign, duplicateCampaign } from "@/lib/api";

interface Campaign {
  id: string;
  name: string;
  status: "delivered" | "pending" | "queued" | "failed" | "draft";
  recipients: number;
  delivered: number;
  credits: number;
  createdAt: string;
  scheduledFor?: string;
}

const campaigns: Campaign[] = [
  {
    id: "1",
    name: "Summer Sale Announcement",
    status: "delivered",
    recipients: 5420,
    delivered: 5380,
    credits: 5420,
    createdAt: "Jan 7, 2026",
  },
  {
    id: "2",
    name: "Flash Sale Alert",
    status: "queued",
    recipients: 8200,
    delivered: 0,
    credits: 8200,
    createdAt: "Jan 7, 2026",
    scheduledFor: "Jan 8, 2026 3:00 PM",
  },
  {
    id: "3",
    name: "New Year Greetings",
    status: "delivered",
    recipients: 12500,
    delivered: 12400,
    credits: 12500,
    createdAt: "Jan 1, 2026",
  },
  {
    id: "4",
    name: "Product Launch",
    status: "pending",
    recipients: 3200,
    delivered: 1500,
    credits: 3200,
    createdAt: "Jan 6, 2026",
  },
  {
    id: "5",
    name: "Customer Survey",
    status: "draft",
    recipients: 0,
    delivered: 0,
    credits: 0,
    createdAt: "Jan 5, 2026",
  },
  {
    id: "6",
    name: "Verification Codes",
    status: "failed",
    recipients: 150,
    delivered: 0,
    credits: 0,
    createdAt: "Jan 4, 2026",
  },
];

const statusConfig = {
  delivered: { label: "Delivered", class: "status-delivered" },
  pending: { label: "Sending", class: "status-pending" },
  queued: { label: "Scheduled", class: "status-queued" },
  failed: { label: "Failed", class: "status-failed" },
  draft: { label: "Draft", class: "bg-muted text-muted-foreground" },
};

export default function SmsCampaigns() {
  const navigate = useNavigate();
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [loadingAction, setLoadingAction] = useState<string | null>(null);

  const filteredCampaigns = campaigns.filter((campaign) => {
    const matchesSearch = campaign.name
      .toLowerCase()
      .includes(searchQuery.toLowerCase());
    const matchesStatus =
      statusFilter === "all" || campaign.status === statusFilter;
    return matchesSearch && matchesStatus;
  });

  const handleView = (id: string) => {
    navigate(`/sms-campaigns/${id}`);
  };

  const handleDuplicate = async (id: string) => {
    setLoadingAction(`copy-${id}`);
    try {
      const response = await duplicateCampaign(id);
      if (response.success) {
        toast({
          title: "Campaign duplicated",
          description: `New campaign created: ${response.data?.campaignId}`,
        });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to duplicate campaign",
        variant: "destructive",
      });
    } finally {
      setLoadingAction(null);
    }
  };

  const handleDelete = async (id: string) => {
    setLoadingAction(`delete-${id}`);
    try {
      const response = await deleteCampaign(id);
      if (response.success) {
        toast({
          title: "Campaign deleted",
          description: "The campaign has been removed.",
        });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to delete campaign",
        variant: "destructive",
      });
    } finally {
      setLoadingAction(null);
    }
  };

  return (
    <DashboardLayout
      title="SMS Campaigns"
      subtitle="Create and manage your SMS campaigns"
      actions={
        <Link to="/sms-campaigns/new">
          <Button className="gap-2">
            <Plus className="h-4 w-4" />
            Create Campaign
          </Button>
        </Link>
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
              <SelectItem value="pending">Sending</SelectItem>
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
          <p className="mt-1 text-2xl font-bold">{campaigns.length}</p>
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm text-muted-foreground">Messages Sent</p>
          <p className="mt-1 text-2xl font-bold">
            {campaigns
              .reduce((acc, c) => acc + c.delivered, 0)
              .toLocaleString()}
          </p>
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm text-muted-foreground">Scheduled</p>
          <p className="mt-1 text-2xl font-bold">
            {campaigns.filter((c) => c.status === "queued").length}
          </p>
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm text-muted-foreground">Credits Used</p>
          <p className="mt-1 text-2xl font-bold">
            {campaigns.reduce((acc, c) => acc + c.credits, 0).toLocaleString()}
          </p>
        </div>
      </div>

      {/* Campaigns Table */}
      <div className="rounded-xl border border-border bg-card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b border-border bg-muted/50">
                <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">
                  Campaign
                </th>
                <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">
                  Status
                </th>
                <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">
                  Recipients
                </th>
                <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">
                  Delivered
                </th>
                <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">
                  Credits
                </th>
                <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">
                  Created
                </th>
                <th className="px-6 py-4 text-right text-sm font-medium text-muted-foreground">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {filteredCampaigns.map((campaign) => (
                <tr
                  key={campaign.id}
                  className="transition-colors hover:bg-muted/30"
                >
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-3">
                      <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <MessageSquare className="h-5 w-5" />
                      </div>
                      <div>
                        <p className="font-medium text-foreground">
                          {campaign.name}
                        </p>
                        {campaign.scheduledFor && (
                          <p className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                            <Calendar className="h-3 w-3" />
                            {campaign.scheduledFor}
                          </p>
                        )}
                      </div>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <span
                      className={cn(
                        "status-badge",
                        statusConfig[campaign.status].class
                      )}
                    >
                      {statusConfig[campaign.status].label}
                    </span>
                  </td>
                  <td className="px-6 py-4 text-foreground">
                    {campaign.recipients.toLocaleString()}
                  </td>
                  <td className="px-6 py-4 text-foreground">
                    {campaign.delivered.toLocaleString()}
                  </td>
                  <td className="px-6 py-4 text-foreground">
                    {campaign.credits.toLocaleString()}
                  </td>
                  <td className="px-6 py-4 text-muted-foreground">
                    {campaign.createdAt}
                  </td>
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

        {filteredCampaigns.length === 0 && (
          <div className="flex flex-col items-center justify-center py-12">
            <MessageSquare className="h-12 w-12 text-muted-foreground/50" />
            <p className="mt-4 text-lg font-medium text-foreground">
              No campaigns found
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
              Try adjusting your search or filters
            </p>
          </div>
        )}
      </div>
    </DashboardLayout>
  );
}
