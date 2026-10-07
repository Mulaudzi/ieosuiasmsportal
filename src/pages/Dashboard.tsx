import { useState, useEffect } from "react";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { MetricCard } from "@/components/dashboard/MetricCard";
import { CampaignChart } from "@/components/dashboard/CampaignChart";
import { RecentCampaigns } from "@/components/dashboard/RecentCampaigns";
import { DeliveryStats } from "@/components/dashboard/DeliveryStats";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { 
  MetricCardSkeleton, 
  ChartSkeleton, 
  CampaignListSkeleton 
} from "@/components/ui/loading-skeleton";
import {
  MessageSquare,
  Users,
  CheckCircle,
  Plus,
  ArrowRight,
} from "lucide-react";
import { Link } from "react-router-dom";
import { getDashboardStats } from "@/lib/api";
import { DashboardTour } from '@/components/dashboard/DashboardTour';
import { GetStartedChecklist } from '@/components/dashboard/GetStartedChecklist';

interface DashboardData {
  smsSent: number;
  contacts: number;
  deliveryRate: string;
  smsChange: string;
  contactsChange: string;
  deliveryChange: string;
}

export default function Dashboard() {
  const [dateRange, setDateRange] = useState("7d");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [data, setData] = useState<DashboardData>({
    smsSent: 0,
    contacts: 0,
    deliveryRate: "0%",
    smsChange: "No data yet",
    contactsChange: "Add contacts to get started",
    deliveryChange: "Send messages to see stats",
  });
  

  useEffect(() => {
    void loadDashboardData();
    const timer=window.setInterval(()=>void loadDashboardData(false),30000);
    return()=>window.clearInterval(timer);
  }, [dateRange]);

  const loadDashboardData = async (showLoading=true) => {
    try {
      setError(null);
      if(showLoading)setLoading(true);
      const response = await getDashboardStats(dateRange);
      
      if (response.success) {
        // Response::success merges associative PHP arrays into the top level.
        // Accept a nested payload too so this remains compatible if the API is
        // normalised in the future.
        const stats = (response.data ?? response) as any;
        setData({
          smsSent: stats.total_sent || 0,
          contacts: stats.total_contacts || 0,
          deliveryRate: stats.delivery_rate 
            ? `${stats.delivery_rate}%` 
            : "0%",
          smsChange: stats.total_sent > 0 
            ? `${stats.total_delivered || 0} delivered` 
            : "No messages sent yet",
          contactsChange: stats.total_contacts > 0 
            ? `${stats.active_campaigns || 0} active campaigns` 
            : "Import contacts to start",
          deliveryChange: stats.total_sent > 0 
            ? `${stats.total_failed || 0} Not Delivered`
            : "No delivery data",
        });
      } else {
        console.warn("Dashboard API returned no data:", response);
        // Keep default values, don't show error for empty data
      }
    } catch (err) {
      console.error("Dashboard error:", err);
      setError("Failed to load dashboard data. Please refresh the page.");
    } finally {
      if(showLoading)setLoading(false);
    }
  };

  return (
    <>
      <DashboardTour />
      <DashboardLayout
        title="Dashboard"
        subtitle="Welcome back! Here's an overview of your messaging performance."
        actions={
          <div className="flex flex-wrap gap-3">
            <Select value={dateRange} onValueChange={setDateRange}>
              <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="24h">Last 24 hours</SelectItem>
                <SelectItem value="7d">Last 7 days</SelectItem>
                <SelectItem value="30d">Last 30 days</SelectItem>
                <SelectItem value="90d">Last 90 days</SelectItem>
              </SelectContent>
            </Select>
            <Link to="/sms-campaigns/new" data-tour="campaign">
              <Button className="gap-2">
                <Plus className="h-4 w-4" />
                New SMS Campaign
              </Button>
            </Link>
          </div>
        }
      >
        {/* Quick Stats */}
        <GetStartedChecklist dashboard />
        <div id="metrics-section" className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {loading ? (
            <>
              <MetricCardSkeleton />
              <MetricCardSkeleton />
              <MetricCardSkeleton />
            </>
          ) : (
            <>
              <div>
                <MetricCard
                  title="Total Sent"
                  value={data.smsSent}
                  change={data.smsChange}
                  changeType="positive"
                  icon={MessageSquare}
                  iconColor="primary"
                />
              </div>
              <div>
                <MetricCard
                  title="Total Contacts"
                  value={data.contacts}
                  change={data.contactsChange}
                  changeType="positive"
                  icon={Users}
                  iconColor="info"
                />
              </div>
              <div>
                <MetricCard
                  title="Delivery Rate"
                  value={data.deliveryRate}
                  change={data.deliveryChange}
                  changeType="positive"
                  icon={CheckCircle}
                  iconColor="success"
                />
              </div>
            </>
          )}
        </div>

        {/* Charts Row */}
        <div id="chart-section" className="mt-6 grid gap-4 lg:grid-cols-3">
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
                <CampaignChart range={dateRange} />
              </div>
              <div>
                <div data-tour="delivery"><DeliveryStats range={dateRange} /></div>
              </div>
            </>
          )}
        </div>

        {/* Recent Campaigns */}
        <div id="campaigns-section" className="mt-6">
          {loading ? (
            <CampaignListSkeleton rows={3} />
          ) : (
            <div>
              <RecentCampaigns />
            </div>
          )}
        </div>

        {/* Quick Actions */}
        <div id="actions-section" className="mt-6 grid gap-4 md:grid-cols-3">
          <div>
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
          </div>

          <div>
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
          </div>

          <div>
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
        </div>
      </DashboardLayout>
    </>
  );
}
