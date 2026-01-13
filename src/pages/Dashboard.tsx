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
import { getDashboardStats } from "@/lib/api";
import { OnboardingTrigger } from "@/components/onboarding/OnboardingTrigger";
import { 
  FeatureTooltip, 
  DashboardTutorial, 
  useDashboardTutorial 
} from "@/components/dashboard/FeatureTooltip";

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
  
  const { showTutorial, completeTutorial } = useDashboardTutorial();

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
      {showTutorial && <DashboardTutorial onComplete={completeTutorial} />}
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
        <div id="metrics-section" className="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
          {loading ? (
            <>
              <MetricCardSkeleton />
              <MetricCardSkeleton />
              <MetricCardSkeleton />
              <MetricCardSkeleton />
            </>
          ) : (
            <>
              <FeatureTooltip
                title="SMS Performance"
                description="Total SMS messages sent across all your campaigns. Track month-over-month growth."
                tip="Send at optimal times (10am-2pm weekdays) for best engagement!"
              >
                <MetricCard
                  title="Total SMS Sent"
                  value={data.smsSent}
                  change={data.smsChange}
                  changeType="positive"
                  icon={MessageSquare}
                  iconColor="primary"
                />
              </FeatureTooltip>
              <FeatureTooltip
                title="Email Performance"
                description="Total emails delivered to your contacts. Monitor campaign effectiveness."
                tip="Personalize subject lines to boost open rates by up to 26%!"
              >
                <MetricCard
                  title="Total Emails Sent"
                  value={data.emailsSent}
                  change={data.emailChange}
                  changeType="positive"
                  icon={Mail}
                  iconColor="accent"
                />
              </FeatureTooltip>
              <FeatureTooltip
                title="Contact Database"
                description="Your total subscriber base. A healthy list grows steadily with quality contacts."
                tip="Clean your list regularly to maintain high deliverability!"
              >
                <MetricCard
                  title="Total Contacts"
                  value={data.contacts}
                  change={data.contactsChange}
                  changeType="positive"
                  icon={Users}
                  iconColor="info"
                />
              </FeatureTooltip>
              <FeatureTooltip
                title="Delivery Health"
                description="Percentage of messages successfully delivered. Above 95% is excellent!"
                tip="Keep delivery rates high by removing invalid numbers promptly."
              >
                <MetricCard
                  title="Delivery Rate"
                  value={data.deliveryRate}
                  change={data.deliveryChange}
                  changeType="positive"
                  icon={CheckCircle}
                  iconColor="success"
                />
              </FeatureTooltip>
            </>
          )}
        </div>

        {/* Charts Row */}
        <div id="chart-section" className="mt-6 grid gap-6 lg:grid-cols-3">
          {loading ? (
            <>
              <div className="lg:col-span-2">
                <ChartSkeleton />
              </div>
              <ChartSkeleton />
            </>
          ) : (
            <>
              <FeatureTooltip
                title="Campaign Trends"
                description="Visualize your messaging volume over time. Identify peak sending periods and seasonal patterns."
                tip="Use this data to plan campaigns around your busiest periods!"
                side="bottom"
                className="lg:col-span-2"
              >
                <CampaignChart />
              </FeatureTooltip>
              <FeatureTooltip
                title="Delivery Breakdown"
                description="See the status distribution of your messages: delivered, pending, and failed."
                tip="Investigate failed messages to improve future delivery rates."
                side="left"
              >
                <DeliveryStats />
              </FeatureTooltip>
            </>
          )}
        </div>

        {/* Recent Campaigns */}
        <div id="campaigns-section" className="mt-6">
          {loading ? (
            <CampaignListSkeleton rows={3} />
          ) : (
            <FeatureTooltip
              title="Recent Activity"
              description="Your latest campaigns with quick access to details, status, and performance metrics."
              tip="Click any campaign to see detailed analytics and recipient responses."
              side="top"
            >
              <RecentCampaigns />
            </FeatureTooltip>
          )}
        </div>

        {/* Quick Actions */}
        <div id="actions-section" className="mt-6 grid gap-4 md:grid-cols-3">
          <FeatureTooltip
            title="Import Contacts"
            description="Upload your contact list from CSV or Excel files. We'll map columns automatically."
            tip="Include custom fields like names to personalize your messages!"
          >
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
          </FeatureTooltip>

          <FeatureTooltip
            title="Message Templates"
            description="Create reusable message templates with dynamic placeholders for personalization."
            tip="Templates with {{name}} personalization see 35% higher engagement!"
          >
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
          </FeatureTooltip>

          <FeatureTooltip
            title="Analytics & Reports"
            description="Deep dive into campaign performance with exportable reports and comparison tools."
            tip="Compare campaigns side-by-side to identify what works best!"
          >
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
          </FeatureTooltip>
        </div>
      </DashboardLayout>
    </>
  );
}
