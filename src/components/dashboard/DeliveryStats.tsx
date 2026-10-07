import { useState, useEffect } from "react";
import { DeliveryStatusHelp } from "@/components/dashboard/DeliveryStatusHelp";
import { deliveryChartStyle } from '@/lib/delivery-presentation';
import { DeliveryOutcomeList } from './DeliveryOutcomeList';
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
  total_skipped: number;
  delivery_rate: number;
}

export function DeliveryStats({ range = "7d" }: { range?: string }) {
  const [data, setData] = useState<DeliveryData[]>([]);
  const [successRate, setSuccessRate] = useState(0);
  const [loading, setLoading] = useState(true);
  const [waitingToSend,setWaitingToSend] = useState(0);

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
        setWaitingToSend(Math.max(0,pending-awaiting-unavailable));
        setData([
          { name: "Delivered", value: delivered },
          { name: "Delivery Confirmation Pending", value: awaiting },
          { name: "Delivery Status Unavailable", value: unavailable },
          { name: "Not Delivered", value: failed },
          { name: "Skipped", value: stats.total_skipped || 0 },
        ].map(d=>({...d,color:deliveryChartStyle(d.name).color})));
        
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

  if (data.every(item=>item.value===0) && waitingToSend===0) {
    return (
      <div className="metric-card h-full">
        <div className="mb-4">
          <h3 className="text-lg font-semibold text-foreground">Delivery Rate</h3>
          <p className="text-sm text-muted-foreground">Overall message delivery performance</p>
        </div>
        <div className="flex items-center justify-center h-40 text-muted-foreground">
          <div className="text-center">
            <p>No messages in this period</p>
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

      <div className="flex flex-col items-center gap-6 sm:flex-row">
        <div className="relative h-40 w-40">
          <ResponsiveContainer width="100%" height="100%">
            <PieChart>
              <Pie
                data={data.filter(item=>item.value>0)}
                cx="50%"
                cy="50%"
                innerRadius={50}
                outerRadius={70}
                paddingAngle={2}
                dataKey="value"
              >
                {data.filter(item=>item.value>0).map((entry, index) => (
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
          <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
            <div className="text-center">
              <p className="text-2xl font-bold text-foreground">{successRate}%</p>
              <p className="text-xs text-muted-foreground">Confirmed</p>
            </div>
          </div>
        </div>

        <div className="w-full min-w-0 flex-1 space-y-3">
          <DeliveryOutcomeList counts={data.map(item=>item.value)} tooltips={false}/>
          {waitingToSend>0&&<p className="text-xs text-muted-foreground">Waiting to send: {waitingToSend.toLocaleString()}</p>}
          <p className="text-xs text-muted-foreground">Skipped messages were not sent and are excluded from Total Sent and delivery rate.</p>
        </div>
      </div>
      <DeliveryStatusHelp />
    </div>
  );
}
