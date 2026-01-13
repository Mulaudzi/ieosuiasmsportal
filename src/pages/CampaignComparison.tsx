import { useState, useEffect } from "react";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  ArrowLeft,
  BarChart3,
  Check,
  Crown,
  Loader2,
  MessageSquare,
  Mail,
  TrendingUp,
  TrendingDown,
  Clock,
  DollarSign,
  Users,
  Zap,
  RefreshCw,
} from "lucide-react";
import { Link } from "react-router-dom";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { api, handleApiError } from "@/lib/api";
import { format } from "date-fns";

interface Campaign {
  id: string;
  name: string;
  type: "sms" | "email";
  status: string;
  total_recipients: number;
  sent_count: number;
  delivered_count: number;
  failed_count: number;
  delivery_rate: number;
  failure_rate: number;
  actual_cost: number;
  created_at: string;
  started_at: string | null;
  completed_at: string | null;
  avg_delivery_time_seconds: number | null;
  hourly_distribution: { hour: number; count: number; delivered: number }[];
}

interface ComparisonData {
  campaigns: Campaign[];
  comparison: {
    best_delivery_rate: number;
    best_delivery_campaign: string;
    fastest_delivery: number;
    fastest_campaign: string;
    lowest_cost_per_delivery: number;
    best_value_campaign: string;
  };
}

interface CampaignOption {
  id: string;
  name: string;
  type: string;
  created_at: string;
  sent_count: number;
}

export default function CampaignComparison() {
  const [loading, setLoading] = useState(false);
  const [loadingCampaigns, setLoadingCampaigns] = useState(true);
  const [availableCampaigns, setAvailableCampaigns] = useState<CampaignOption[]>([]);
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [comparisonData, setComparisonData] = useState<ComparisonData | null>(null);
  const [typeFilter, setTypeFilter] = useState<string>("all");

  useEffect(() => {
    loadAvailableCampaigns();
  }, []);

  const loadAvailableCampaigns = async () => {
    setLoadingCampaigns(true);
    try {
      const res = await api.get<{ campaigns: CampaignOption[] }>("/reports/campaigns?start_date=2020-01-01");
      if (res.success && res.data?.campaigns) {
        // Only show completed campaigns with sent messages
        const completed = res.data.campaigns.filter(c => c.sent_count > 0);
        setAvailableCampaigns(completed);
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoadingCampaigns(false);
    }
  };

  const toggleCampaign = (id: string) => {
    if (selectedIds.includes(id)) {
      setSelectedIds(selectedIds.filter(i => i !== id));
    } else if (selectedIds.length < 5) {
      setSelectedIds([...selectedIds, id]);
    } else {
      toast({
        title: "Maximum 5 campaigns",
        description: "You can compare up to 5 campaigns at a time.",
        variant: "destructive",
      });
    }
  };

  const loadComparison = async () => {
    if (selectedIds.length < 2) {
      toast({
        title: "Select more campaigns",
        description: "Please select at least 2 campaigns to compare.",
        variant: "destructive",
      });
      return;
    }

    setLoading(true);
    try {
      const res = await api.get<ComparisonData>(`/reports/compare?ids=${selectedIds.join(",")}`);
      if (res.success && res.data) {
        setComparisonData(res.data);
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoading(false);
    }
  };

  const filteredCampaigns = availableCampaigns.filter(c => 
    typeFilter === "all" || c.type === typeFilter
  );

  const formatDuration = (seconds: number | null) => {
    if (seconds === null) return "N/A";
    if (seconds < 60) return `${Math.round(seconds)}s`;
    if (seconds < 3600) return `${Math.round(seconds / 60)}m`;
    return `${Math.round(seconds / 3600)}h`;
  };

  return (
    <DashboardLayout
      title="Campaign Comparison"
      subtitle="Compare performance metrics across multiple campaigns"
      actions={
        <Link to="/reports">
          <Button variant="outline" className="gap-2">
            <ArrowLeft className="h-4 w-4" />
            Back to Reports
          </Button>
        </Link>
      }
    >
      {/* Campaign Selection */}
      <div className="mb-8 rounded-xl border border-border bg-card p-6">
        <div className="flex items-center justify-between mb-4">
          <div>
            <h3 className="font-semibold text-foreground">Select Campaigns to Compare</h3>
            <p className="text-sm text-muted-foreground">Choose 2-5 campaigns</p>
          </div>
          <div className="flex items-center gap-4">
            <Select value={typeFilter} onValueChange={setTypeFilter}>
              <SelectTrigger className="w-32">
                <SelectValue placeholder="Type" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Types</SelectItem>
                <SelectItem value="sms">SMS</SelectItem>
                <SelectItem value="email">Email</SelectItem>
              </SelectContent>
            </Select>
            <Button 
              onClick={loadComparison} 
              disabled={loading || selectedIds.length < 2}
              className="gap-2"
            >
              {loading ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <BarChart3 className="h-4 w-4" />
              )}
              Compare ({selectedIds.length})
            </Button>
          </div>
        </div>

        {loadingCampaigns ? (
          <div className="flex items-center justify-center py-8">
            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
          </div>
        ) : (
          <div className="grid gap-2 max-h-64 overflow-y-auto">
            {filteredCampaigns.map((campaign) => (
              <label
                key={campaign.id}
                className={cn(
                  "flex items-center gap-3 rounded-lg border p-3 cursor-pointer transition-colors",
                  selectedIds.includes(campaign.id)
                    ? "border-primary bg-primary/5"
                    : "border-border hover:border-primary/50"
                )}
              >
                <Checkbox
                  checked={selectedIds.includes(campaign.id)}
                  onCheckedChange={() => toggleCampaign(campaign.id)}
                />
                <div className={cn(
                  "flex h-8 w-8 items-center justify-center rounded-lg",
                  campaign.type === "sms" ? "bg-primary/10 text-primary" : "bg-accent/10 text-accent"
                )}>
                  {campaign.type === "sms" ? <MessageSquare className="h-4 w-4" /> : <Mail className="h-4 w-4" />}
                </div>
                <div className="flex-1 min-w-0">
                  <p className="font-medium text-foreground truncate">{campaign.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {format(new Date(campaign.created_at), "MMM d, yyyy")} • {campaign.sent_count.toLocaleString()} sent
                  </p>
                </div>
              </label>
            ))}
            {filteredCampaigns.length === 0 && (
              <p className="text-center py-8 text-muted-foreground">
                No campaigns with sent messages found.
              </p>
            )}
          </div>
        )}
      </div>

      {/* Comparison Results */}
      {comparisonData && (
        <div className="space-y-6">
          {/* Highlights */}
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="rounded-xl border border-success/30 bg-success/5 p-4">
              <div className="flex items-center gap-2 mb-2">
                <Crown className="h-5 w-5 text-success" />
                <span className="font-medium text-foreground">Best Delivery Rate</span>
              </div>
              <p className="text-2xl font-bold text-success">
                {comparisonData.comparison.best_delivery_rate}%
              </p>
              <p className="text-sm text-muted-foreground">
                {comparisonData.campaigns.find(c => c.id === comparisonData.comparison.best_delivery_campaign)?.name}
              </p>
            </div>

            <div className="rounded-xl border border-primary/30 bg-primary/5 p-4">
              <div className="flex items-center gap-2 mb-2">
                <Zap className="h-5 w-5 text-primary" />
                <span className="font-medium text-foreground">Fastest Delivery</span>
              </div>
              <p className="text-2xl font-bold text-primary">
                {formatDuration(comparisonData.comparison.fastest_delivery)}
              </p>
              <p className="text-sm text-muted-foreground">
                {comparisonData.campaigns.find(c => c.id === comparisonData.comparison.fastest_campaign)?.name || "N/A"}
              </p>
            </div>

            <div className="rounded-xl border border-accent/30 bg-accent/5 p-4">
              <div className="flex items-center gap-2 mb-2">
                <DollarSign className="h-5 w-5 text-accent" />
                <span className="font-medium text-foreground">Best Value</span>
              </div>
              <p className="text-2xl font-bold text-accent">
                R{comparisonData.comparison.lowest_cost_per_delivery?.toFixed(4) || "N/A"}
              </p>
              <p className="text-sm text-muted-foreground">
                per delivered message
              </p>
            </div>
          </div>

          {/* Side by Side Comparison Table */}
          <div className="rounded-xl border border-border bg-card overflow-hidden">
            <div className="border-b border-border px-6 py-4">
              <h3 className="font-semibold text-foreground">Detailed Comparison</h3>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-border bg-muted/50">
                    <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">Metric</th>
                    {comparisonData.campaigns.map((campaign) => (
                      <th key={campaign.id} className="px-4 py-3 text-center text-sm font-medium text-foreground min-w-[150px]">
                        <div className="flex flex-col items-center gap-1">
                          <div className={cn(
                            "flex h-6 w-6 items-center justify-center rounded",
                            campaign.type === "sms" ? "bg-primary/10 text-primary" : "bg-accent/10 text-accent"
                          )}>
                            {campaign.type === "sms" ? <MessageSquare className="h-3 w-3" /> : <Mail className="h-3 w-3" />}
                          </div>
                          <span className="truncate max-w-[140px]">{campaign.name}</span>
                        </div>
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {/* Status */}
                  <tr>
                    <td className="px-4 py-3 text-sm text-muted-foreground">Status</td>
                    {comparisonData.campaigns.map((c) => (
                      <td key={c.id} className="px-4 py-3 text-center">
                        <span className={cn(
                          "inline-flex rounded-full px-2 py-0.5 text-xs font-medium",
                          c.status === "Completed" ? "bg-success/10 text-success" :
                          c.status === "Sending" ? "bg-primary/10 text-primary" :
                          "bg-muted text-muted-foreground"
                        )}>
                          {c.status}
                        </span>
                      </td>
                    ))}
                  </tr>

                  {/* Recipients */}
                  <tr>
                    <td className="px-4 py-3 text-sm text-muted-foreground flex items-center gap-2">
                      <Users className="h-4 w-4" /> Recipients
                    </td>
                    {comparisonData.campaigns.map((c) => (
                      <td key={c.id} className="px-4 py-3 text-center font-medium text-foreground">
                        {c.total_recipients.toLocaleString()}
                      </td>
                    ))}
                  </tr>

                  {/* Sent */}
                  <tr>
                    <td className="px-4 py-3 text-sm text-muted-foreground">Sent</td>
                    {comparisonData.campaigns.map((c) => (
                      <td key={c.id} className="px-4 py-3 text-center font-medium text-foreground">
                        {c.sent_count.toLocaleString()}
                      </td>
                    ))}
                  </tr>

                  {/* Delivered */}
                  <tr>
                    <td className="px-4 py-3 text-sm text-muted-foreground flex items-center gap-2">
                      <Check className="h-4 w-4 text-success" /> Delivered
                    </td>
                    {comparisonData.campaigns.map((c) => (
                      <td key={c.id} className="px-4 py-3 text-center font-medium text-success">
                        {c.delivered_count.toLocaleString()}
                      </td>
                    ))}
                  </tr>

                  {/* Failed */}
                  <tr>
                    <td className="px-4 py-3 text-sm text-muted-foreground">Failed</td>
                    {comparisonData.campaigns.map((c) => (
                      <td key={c.id} className="px-4 py-3 text-center font-medium text-destructive">
                        {c.failed_count.toLocaleString()}
                      </td>
                    ))}
                  </tr>

                  {/* Delivery Rate */}
                  <tr className="bg-muted/30">
                    <td className="px-4 py-3 text-sm font-medium text-foreground flex items-center gap-2">
                      <TrendingUp className="h-4 w-4" /> Delivery Rate
                    </td>
                    {comparisonData.campaigns.map((c) => (
                      <td key={c.id} className="px-4 py-3 text-center">
                        <div className="flex items-center justify-center gap-1">
                          <span className={cn(
                            "text-lg font-bold",
                            c.id === comparisonData.comparison.best_delivery_campaign ? "text-success" : "text-foreground"
                          )}>
                            {c.delivery_rate}%
                          </span>
                          {c.id === comparisonData.comparison.best_delivery_campaign && (
                            <Crown className="h-4 w-4 text-success" />
                          )}
                        </div>
                      </td>
                    ))}
                  </tr>

                  {/* Avg Delivery Time */}
                  <tr>
                    <td className="px-4 py-3 text-sm text-muted-foreground flex items-center gap-2">
                      <Clock className="h-4 w-4" /> Avg Delivery Time
                    </td>
                    {comparisonData.campaigns.map((c) => (
                      <td key={c.id} className="px-4 py-3 text-center">
                        <span className={cn(
                          "font-medium",
                          c.id === comparisonData.comparison.fastest_campaign ? "text-primary" : "text-foreground"
                        )}>
                          {formatDuration(c.avg_delivery_time_seconds)}
                        </span>
                      </td>
                    ))}
                  </tr>

                  {/* Cost */}
                  <tr>
                    <td className="px-4 py-3 text-sm text-muted-foreground flex items-center gap-2">
                      <DollarSign className="h-4 w-4" /> Total Cost
                    </td>
                    {comparisonData.campaigns.map((c) => (
                      <td key={c.id} className="px-4 py-3 text-center font-medium text-foreground">
                        R{(c.actual_cost || 0).toFixed(2)}
                      </td>
                    ))}
                  </tr>

                  {/* Cost per Delivery */}
                  <tr>
                    <td className="px-4 py-3 text-sm text-muted-foreground">Cost per Delivery</td>
                    {comparisonData.campaigns.map((c) => {
                      const costPer = c.delivered_count > 0 ? c.actual_cost / c.delivered_count : 0;
                      return (
                        <td key={c.id} className="px-4 py-3 text-center">
                          <span className={cn(
                            "font-medium",
                            c.id === comparisonData.comparison.best_value_campaign ? "text-accent" : "text-foreground"
                          )}>
                            R{costPer.toFixed(4)}
                          </span>
                        </td>
                      );
                    })}
                  </tr>

                  {/* Date */}
                  <tr>
                    <td className="px-4 py-3 text-sm text-muted-foreground">Created</td>
                    {comparisonData.campaigns.map((c) => (
                      <td key={c.id} className="px-4 py-3 text-center text-sm text-muted-foreground">
                        {format(new Date(c.created_at), "MMM d, yyyy")}
                      </td>
                    ))}
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          {/* Insights */}
          <div className="rounded-xl border border-border bg-gradient-to-br from-primary/5 to-accent/5 p-6">
            <h3 className="font-semibold text-foreground mb-4 flex items-center gap-2">
              <TrendingUp className="h-5 w-5 text-primary" />
              Performance Insights
            </h3>
            <div className="space-y-3 text-sm">
              {comparisonData.comparison.best_delivery_campaign && (
                <p className="text-muted-foreground">
                  <span className="font-medium text-foreground">
                    {comparisonData.campaigns.find(c => c.id === comparisonData.comparison.best_delivery_campaign)?.name}
                  </span>{" "}
                  achieved the highest delivery rate at{" "}
                  <span className="text-success font-medium">{comparisonData.comparison.best_delivery_rate}%</span>.
                </p>
              )}
              
              {comparisonData.comparison.fastest_campaign && (
                <p className="text-muted-foreground">
                  <span className="font-medium text-foreground">
                    {comparisonData.campaigns.find(c => c.id === comparisonData.comparison.fastest_campaign)?.name}
                  </span>{" "}
                  had the fastest average delivery time at{" "}
                  <span className="text-primary font-medium">{formatDuration(comparisonData.comparison.fastest_delivery)}</span>.
                </p>
              )}

              {comparisonData.comparison.best_value_campaign && (
                <p className="text-muted-foreground">
                  <span className="font-medium text-foreground">
                    {comparisonData.campaigns.find(c => c.id === comparisonData.comparison.best_value_campaign)?.name}
                  </span>{" "}
                  provided the best value at{" "}
                  <span className="text-accent font-medium">R{comparisonData.comparison.lowest_cost_per_delivery}</span> per delivered message.
                </p>
              )}
            </div>
          </div>
        </div>
      )}
    </DashboardLayout>
  );
}
