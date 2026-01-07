import { MessageSquare, Mail, MoreHorizontal, Eye } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";

interface Campaign {
  id: string;
  name: string;
  type: "sms" | "email";
  status: "delivered" | "pending" | "queued" | "failed";
  recipients: number;
  delivered: number;
  date: string;
}

const campaigns: Campaign[] = [
  {
    id: "1",
    name: "Summer Sale Announcement",
    type: "sms",
    status: "delivered",
    recipients: 5420,
    delivered: 5380,
    date: "2 hours ago",
  },
  {
    id: "2",
    name: "Product Launch Newsletter",
    type: "email",
    status: "delivered",
    recipients: 12500,
    delivered: 11890,
    date: "5 hours ago",
  },
  {
    id: "3",
    name: "Flash Sale Alert",
    type: "sms",
    status: "queued",
    recipients: 8200,
    delivered: 0,
    date: "Scheduled for 3:00 PM",
  },
  {
    id: "4",
    name: "Weekly Digest",
    type: "email",
    status: "pending",
    recipients: 4500,
    delivered: 2100,
    date: "In progress",
  },
  {
    id: "5",
    name: "Account Verification",
    type: "sms",
    status: "failed",
    recipients: 150,
    delivered: 0,
    date: "1 day ago",
  },
];

const statusConfig = {
  delivered: { label: "Delivered", class: "status-delivered" },
  pending: { label: "Sending", class: "status-pending" },
  queued: { label: "Queued", class: "status-queued" },
  failed: { label: "Failed", class: "status-failed" },
};

export function RecentCampaigns() {
  return (
    <div className="metric-card">
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h3 className="text-lg font-semibold text-foreground">
            Recent Campaigns
          </h3>
          <p className="text-sm text-muted-foreground">
            Your latest messaging campaigns
          </p>
        </div>
        <Button variant="outline" size="sm">
          View All
        </Button>
      </div>

      <div className="space-y-4">
        {campaigns.map((campaign) => (
          <div
            key={campaign.id}
            className="flex items-center gap-4 rounded-lg border border-border p-4 transition-colors hover:bg-muted/50"
          >
            <div
              className={cn(
                "flex h-10 w-10 items-center justify-center rounded-lg",
                campaign.type === "sms"
                  ? "bg-primary/10 text-primary"
                  : "bg-accent/10 text-accent"
              )}
            >
              {campaign.type === "sms" ? (
                <MessageSquare className="h-5 w-5" />
              ) : (
                <Mail className="h-5 w-5" />
              )}
            </div>

            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2">
                <p className="truncate font-medium text-foreground">
                  {campaign.name}
                </p>
                <span
                  className={cn(
                    "status-badge",
                    statusConfig[campaign.status].class
                  )}
                >
                  {statusConfig[campaign.status].label}
                </span>
              </div>
              <p className="mt-0.5 text-sm text-muted-foreground">
                {campaign.delivered.toLocaleString()} /{" "}
                {campaign.recipients.toLocaleString()} recipients • {campaign.date}
              </p>
            </div>

            <div className="flex items-center gap-2">
              <Button variant="ghost" size="icon">
                <Eye className="h-4 w-4" />
              </Button>
              <Button variant="ghost" size="icon">
                <MoreHorizontal className="h-4 w-4" />
              </Button>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
