import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { MetricCard } from "@/components/dashboard/MetricCard";
import { CampaignChart } from "@/components/dashboard/CampaignChart";
import { RecentCampaigns } from "@/components/dashboard/RecentCampaigns";
import { DeliveryStats } from "@/components/dashboard/DeliveryStats";
import { Button } from "@/components/ui/button";
import {
  MessageSquare,
  Mail,
  Users,
  CheckCircle,
  Plus,
  ArrowRight,
} from "lucide-react";
import { Link } from "react-router-dom";

export default function Dashboard() {
  return (
    <DashboardLayout
      title="Dashboard"
      subtitle="Welcome back! Here's an overview of your messaging performance."
      actions={
        <div className="flex gap-3">
          <Link to="/sms-campaigns/new">
            <Button className="gap-2">
              <Plus className="h-4 w-4" />
              New SMS Campaign
            </Button>
          </Link>
          <Link to="/email-campaigns/new">
            <Button variant="outline" className="gap-2">
              <Mail className="h-4 w-4" />
              New Email Campaign
            </Button>
          </Link>
        </div>
      }
    >
      {/* Quick Stats */}
      <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
        <MetricCard
          title="Total SMS Sent"
          value={284650}
          change="+12.5% from last month"
          changeType="positive"
          icon={MessageSquare}
          iconColor="primary"
        />
        <MetricCard
          title="Total Emails Sent"
          value={156890}
          change="+8.2% from last month"
          changeType="positive"
          icon={Mail}
          iconColor="accent"
        />
        <MetricCard
          title="Total Contacts"
          value={45280}
          change="+2,340 new this month"
          changeType="positive"
          icon={Users}
          iconColor="info"
        />
        <MetricCard
          title="Delivery Rate"
          value="94.8%"
          change="+1.2% improvement"
          changeType="positive"
          icon={CheckCircle}
          iconColor="success"
        />
      </div>

      {/* Charts Row */}
      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <CampaignChart />
        </div>
        <DeliveryStats />
      </div>

      {/* Recent Campaigns */}
      <div className="mt-6">
        <RecentCampaigns />
      </div>

      {/* Quick Actions */}
      <div className="mt-6 grid gap-4 md:grid-cols-3">
        <Link
          to="/contacts/import"
          className="metric-card group flex items-center justify-between"
        >
          <div>
            <h3 className="font-semibold text-foreground">Import Contacts</h3>
            <p className="text-sm text-muted-foreground">
              Upload CSV or Excel file
            </p>
          </div>
          <ArrowRight className="h-5 w-5 text-muted-foreground transition-transform group-hover:translate-x-1 group-hover:text-primary" />
        </Link>

        <Link
          to="/templates"
          className="metric-card group flex items-center justify-between"
        >
          <div>
            <h3 className="font-semibold text-foreground">Manage Templates</h3>
            <p className="text-sm text-muted-foreground">
              Create reusable messages
            </p>
          </div>
          <ArrowRight className="h-5 w-5 text-muted-foreground transition-transform group-hover:translate-x-1 group-hover:text-primary" />
        </Link>

        <Link
          to="/reports"
          className="metric-card group flex items-center justify-between"
        >
          <div>
            <h3 className="font-semibold text-foreground">View Reports</h3>
            <p className="text-sm text-muted-foreground">
              Detailed analytics & exports
            </p>
          </div>
          <ArrowRight className="h-5 w-5 text-muted-foreground transition-transform group-hover:translate-x-1 group-hover:text-primary" />
        </Link>
      </div>
    </DashboardLayout>
  );
}
