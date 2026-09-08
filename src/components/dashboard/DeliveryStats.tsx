import { useState, useEffect } from "react";
import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip } from "recharts";
import { api } from "@/lib/api";
import { Loader2 } from "lucide-react";

interface DeliveryData {
  name: string;
  value: number;
  color: string;
}

interface DashboardStats {
  total_sent: number;
  total_delivered: number;
  total_failed: number;
  total_pending: number;
  total_awaiting_delivery: number;
  total_dlr_unavailable: number;
  delivery_rate: number;
}

export function DeliveryStats({ range = "7d" }: { range?: string }) {
  const [data, setData] = useState<DeliveryData[]>([]);
  const [successRate, setSuccessRate] = useState(0);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    void loadDeliveryStats();const timer=window.setInterval(()=>void loadDeliveryStats(false),30000);return()=>window.clearInterval(timer);
  }, [range]);

  const loadDeliveryStats = async (showLoading=true) => {
    try {
      const response = await api.get<DashboardStats>("/dashboard/stats", { range });
      if (response.success) {
        const stats = (response.data??response) as unknown as DashboardStats;
        const total = stats.total_sent || 1;
        const delivered = stats.total_delivered || 0;
        const failed = stats.total_failed || 0;
        const awaiting = stats.total_awaiting_delivery || 0;
        const unavailable = stats.total_dlr_unavailable || 0;
        const pending = stats.total_pending || 0;

        const deliveredPct = Math.round((delivered / total) * 100);
        const failedPct = Math.round((failed / total) * 100);
        const pendingPct = Math.round((pending / total) * 100);
        const awaitingPct = Math.round((awaiting / total) * 100);
        const unavailablePct = Math.round((unavailable / total) * 100);

        setData([
          { name: "Delivered", value: deliveredPct, color: "hsl(160, 84%, 39%)" },
          { name: "Pending dispatch", value: Math.max(0,pendingPct-awaitingPct-unavailablePct), color: "hsl(38, 92%, 50%)" },
          { name: "Awaiting DLR", value: awaitingPct, color: "hsl(38, 92%, 50%)" },
          { name: "DLR unavailable", value: unavailablePct, color: "hsl(0, 72%, 51%)" },
          { name: "Failed", value: failedPct, color: "hsl(0, 72%, 51%)" },
        ].filter(d => d.value > 0));
        
        setSuccessRate(stats.delivery_rate || deliveredPct);
      }
    } catch (error) {
      console.error("Failed to load delivery stats:", error);
      setData([]);
    } finally {
      if(showLoading)setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="metric-card h-full flex items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (data.length === 0) {
    return (
      <div className="metric-card h-full">
        <div className="mb-4">
          <h3 className="text-lg font-semibold text-foreground">Delivery Rate</h3>
          <p className="text-sm text-muted-foreground">Overall message delivery performance</p>
        </div>
        <div className="flex items-center justify-center h-40 text-muted-foreground">
          <div className="text-center">
            <p>No delivery data yet</p>
            <p className="text-sm">Send messages to see stats</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="metric-card h-full">
      <div className="mb-4">
        <h3 className="text-lg font-semibold text-foreground">Delivery Rate</h3>
        <p className="text-sm text-muted-foreground">Overall message delivery performance</p>
      </div>

      <div className="flex items-center gap-6">
        <div className="relative h-40 w-40">
          <ResponsiveContainer width="100%" height="100%">
            <PieChart>
              <Pie
                data={data}
                cx="50%"
                cy="50%"
                innerRadius={50}
                outerRadius={70}
                paddingAngle={2}
                dataKey="value"
              >
                {data.map((entry, index) => (
                  <Cell key={`cell-${index}`} fill={entry.color} />
                ))}
              </Pie>
              <Tooltip
                contentStyle={{
                  backgroundColor: "hsl(0, 0%, 100%)",
                  border: "1px solid hsl(215, 20%, 91%)",
                  borderRadius: "0.5rem",
                }}
              />
            </PieChart>
          </ResponsiveContainer>
          <div className="absolute inset-0 flex items-center justify-center">
            <div className="text-center">
              <p className="text-2xl font-bold text-foreground">{successRate}%</p>
              <p className="text-xs text-muted-foreground">Success</p>
            </div>
          </div>
        </div>

        <div className="flex-1 space-y-3">
          {data.map((item) => (
            <div key={item.name} className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <div
                  className="h-3 w-3 rounded-full"
                  style={{ backgroundColor: item.color }}
                />
                <span className="text-sm text-muted-foreground">{item.name}</span>
              </div>
              <span className="text-sm font-medium text-foreground">{item.value}%</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
