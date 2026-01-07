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
  Mail,
  MoreHorizontal,
  Eye,
  Copy,
  Trash2,
  Calendar,
} from "lucide-react";
import { Link } from "react-router-dom";
import { cn } from "@/lib/utils";

interface Campaign {
  id: string;
  name: string;
  status: "delivered" | "pending" | "queued" | "failed" | "draft";
  recipients: number;
  delivered: number;
  opened: number;
  clicked: number;
  createdAt: string;
  scheduledFor?: string;
}

const campaigns: Campaign[] = [
  {
    id: "1",
    name: "Product Launch Newsletter",
    status: "delivered",
    recipients: 12500,
    delivered: 11890,
    opened: 4521,
    clicked: 892,
    createdAt: "Jan 7, 2026",
  },
  {
    id: "2",
    name: "Weekly Digest",
    status: "queued",
    recipients: 8500,
    delivered: 0,
    opened: 0,
    clicked: 0,
    createdAt: "Jan 7, 2026",
    scheduledFor: "Jan 8, 2026 9:00 AM",
  },
  {
    id: "3",
    name: "Holiday Promotions",
    status: "delivered",
    recipients: 15200,
    delivered: 14800,
    opened: 6200,
    clicked: 1450,
    createdAt: "Dec 20, 2025",
  },
  {
    id: "4",
    name: "Customer Feedback Survey",
    status: "pending",
    recipients: 5000,
    delivered: 2100,
    opened: 450,
    clicked: 120,
    createdAt: "Jan 6, 2026",
  },
  {
    id: "5",
    name: "Welcome Series - Day 1",
    status: "draft",
    recipients: 0,
    delivered: 0,
    opened: 0,
    clicked: 0,
    createdAt: "Jan 5, 2026",
  },
];

const statusConfig = {
  delivered: { label: "Delivered", class: "status-delivered" },
  pending: { label: "Sending", class: "status-pending" },
  queued: { label: "Scheduled", class: "status-queued" },
  failed: { label: "Failed", class: "status-failed" },
  draft: { label: "Draft", class: "bg-muted text-muted-foreground" },
};

export default function EmailCampaigns() {
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");

  const filteredCampaigns = campaigns.filter((campaign) => {
    const matchesSearch = campaign.name
      .toLowerCase()
      .includes(searchQuery.toLowerCase());
    const matchesStatus =
      statusFilter === "all" || campaign.status === statusFilter;
    return matchesSearch && matchesStatus;
  });

  return (
    <DashboardLayout
      title="Email Campaigns"
      subtitle="Create and manage your email campaigns"
      actions={
        <Link to="/email-campaigns/new">
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
          <p className="text-sm text-muted-foreground">Emails Sent</p>
          <p className="mt-1 text-2xl font-bold">
            {campaigns
              .reduce((acc, c) => acc + c.delivered, 0)
              .toLocaleString()}
          </p>
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm text-muted-foreground">Avg. Open Rate</p>
          <p className="mt-1 text-2xl font-bold">38.2%</p>
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm text-muted-foreground">Avg. Click Rate</p>
          <p className="mt-1 text-2xl font-bold">7.5%</p>
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
                  Delivered
                </th>
                <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">
                  Opened
                </th>
                <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">
                  Clicked
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
                      <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-accent/10 text-accent">
                        <Mail className="h-5 w-5" />
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
                    {campaign.delivered.toLocaleString()}
                  </td>
                  <td className="px-6 py-4">
                    <div>
                      <span className="text-foreground">
                        {campaign.opened.toLocaleString()}
                      </span>
                      {campaign.delivered > 0 && (
                        <span className="ml-1 text-xs text-muted-foreground">
                          ({((campaign.opened / campaign.delivered) * 100).toFixed(1)}%)
                        </span>
                      )}
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <div>
                      <span className="text-foreground">
                        {campaign.clicked.toLocaleString()}
                      </span>
                      {campaign.delivered > 0 && (
                        <span className="ml-1 text-xs text-muted-foreground">
                          ({((campaign.clicked / campaign.delivered) * 100).toFixed(1)}%)
                        </span>
                      )}
                    </div>
                  </td>
                  <td className="px-6 py-4 text-muted-foreground">
                    {campaign.createdAt}
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex items-center justify-end gap-1">
                      <Button variant="ghost" size="icon">
                        <Eye className="h-4 w-4" />
                      </Button>
                      <Button variant="ghost" size="icon">
                        <Copy className="h-4 w-4" />
                      </Button>
                      <Button variant="ghost" size="icon">
                        <Trash2 className="h-4 w-4 text-destructive" />
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
            <Mail className="h-12 w-12 text-muted-foreground/50" />
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
