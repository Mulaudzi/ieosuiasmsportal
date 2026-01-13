import { useState, useEffect } from "react";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { MetricCard } from "@/components/dashboard/MetricCard";
import { CampaignChart } from "@/components/dashboard/CampaignChart";
import { RecentCampaigns } from "@/components/dashboard/RecentCampaigns";
import { DeliveryStats } from "@/components/dashboard/DeliveryStats";
import { Button } from "@/components/ui/button";
import { 
  MetricCardSkeleton, 
  ChartSkeleton, 
  CampaignListSkeleton 
} from "@/components/ui/loading-skeleton";
import {
  MessageSquare,
  Mail,
  Users,
  CheckCircle,
  Plus,
  ArrowRight,
} from "lucide-react";
import { Link } from "react-router-dom";
import { getDashboardStats, handleApiError } from "@/lib/api";
import { OnboardingTrigger } from "@/components/onboarding/OnboardingTrigger";

interface DashboardData {
  smsSent: number;
  emailsSent: number;
  contacts: number;
  deliveryRate: string;
  smsChange: string;
  emailChange: string;
  contactsChange: string;
  deliveryChange: string;
}

export default function Dashboard() {
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState<DashboardData>({
    smsSent: 284650,
    emailsSent: 156890,
    contacts: 45280,
    deliveryRate: "94.8%",
    smsChange: "+12.5% from last month",
    emailChange: "+8.2% from last month",
    contactsChange: "+2,340 new this month",
    deliveryChange: "+1.2% improvement",
  });

  useEffect(() => {
    loadDashboardData();
  }, []);

  const loadDashboardData = async () => {
    try {
      const response = await getDashboardStats();
      if (response.success && response.data) {
        setData({
          smsSent: response.data.sms_sent || response.data.smsSent || 284650,
          emailsSent: response.data.emails_sent || response.data.emailsSent || 156890,
          contacts: response.data.contacts || 45280,
          deliveryRate: response.data.delivery_rate 
            ? `${response.data.delivery_rate}%` 
            : "94.8%",
          smsChange: response.data.sms_change || "+12.5% from last month",
          emailChange: response.data.email_change || "+8.2% from last month",
          contactsChange: response.data.contacts_change || "+2,340 new this month",
          deliveryChange: response.data.delivery_change || "+1.2% improvement",
        });
      }
    } catch (error) {
      // Use default mock data on error - don't show error toast for demo
      console.log("Using default dashboard data");
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      <OnboardingTrigger />
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
        {loading ? (
          <>
            <MetricCardSkeleton />
            <MetricCardSkeleton />
            <MetricCardSkeleton />
            <MetricCardSkeleton />
          </>
        ) : (
          <>
            <MetricCard
              title="Total SMS Sent"
              value={data.smsSent}
              change={data.smsChange}
              changeType="positive"
              icon={MessageSquare}
              iconColor="primary"
            />
            <MetricCard
              title="Total Emails Sent"
              value={data.emailsSent}
              change={data.emailChange}
              changeType="positive"
              icon={Mail}
              iconColor="accent"
            />
            <MetricCard
              title="Total Contacts"
              value={data.contacts}
              change={data.contactsChange}
              changeType="positive"
              icon={Users}
              iconColor="info"
            />
            <MetricCard
              title="Delivery Rate"
              value={data.deliveryRate}
              change={data.deliveryChange}
              changeType="positive"
              icon={CheckCircle}
              iconColor="success"
            />
          </>
        )}
      </div>

      {/* Charts Row */}
      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        {loading ? (
          <>
            <div className="lg:col-span-2">
              <ChartSkeleton />
            </div>
            <ChartSkeleton />
          </>
        ) : (
          <>
            <div className="lg:col-span-2">
              <CampaignChart />
            </div>
            <DeliveryStats />
          </>
        )}
      </div>

      {/* Recent Campaigns */}
      <div className="mt-6">
        {loading ? (
          <CampaignListSkeleton rows={3} />
        ) : (
          <RecentCampaigns />
        )}
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
    </>
  );
}
