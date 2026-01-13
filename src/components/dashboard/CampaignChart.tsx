import { useState, useEffect } from "react";
import {
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from "recharts";
import { api } from "@/lib/api";
import { Loader2 } from "lucide-react";

interface ChartDataPoint {
  name: string;
  date: string;
  sms: number;
  email: number;
}

export function CampaignChart() {
  const [data, setData] = useState<ChartDataPoint[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    loadChartData();
  }, []);

  const loadChartData = async () => {
    try {
      const response = await api.get<{ chart: any[] }>("/dashboard/chart", { days: "7" });
      if (response.success && response.data?.chart) {
        // Transform API data to chart format
        const chartData = response.data.chart.map((item: any) => {
          const date = new Date(item.date);
          const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
          return {
            name: dayNames[date.getDay()],
            date: item.date,
            sms: parseInt(item.sent) || 0,
            email: 0, // Email data if available
          };
        });
        setData(chartData);
      } else {
        // Fallback to empty state
        setData([]);
      }
    } catch (error) {
      console.error("Failed to load chart data:", error);
      setData([]);
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="metric-card h-[400px] flex items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (data.length === 0) {
    return (
      <div className="metric-card h-[400px]">
        <div className="mb-6">
          <h3 className="text-lg font-semibold text-foreground">Message Volume</h3>
          <p className="text-sm text-muted-foreground">SMS and Email sends over the past week</p>
        </div>
        <div className="flex items-center justify-center h-[300px] text-muted-foreground">
          <div className="text-center">
            <p>No message data available yet</p>
            <p className="text-sm">Send your first campaign to see stats here</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="metric-card h-[400px]">
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h3 className="text-lg font-semibold text-foreground">Message Volume</h3>
          <p className="text-sm text-muted-foreground">SMS and Email sends over the past week</p>
        </div>
        <div className="flex items-center gap-4">
          <div className="flex items-center gap-2">
            <div className="h-3 w-3 rounded-full bg-primary" />
            <span className="text-sm text-muted-foreground">SMS</span>
          </div>
          <div className="flex items-center gap-2">
            <div className="h-3 w-3 rounded-full bg-accent" />
            <span className="text-sm text-muted-foreground">Email</span>
          </div>
        </div>
      </div>

      <ResponsiveContainer width="100%" height={300}>
        <AreaChart data={data}>
          <defs>
            <linearGradient id="smsGradient" x1="0" y1="0" x2="0" y2="1">
              <stop offset="5%" stopColor="hsl(221, 83%, 53%)" stopOpacity={0.3} />
              <stop offset="95%" stopColor="hsl(221, 83%, 53%)" stopOpacity={0} />
            </linearGradient>
            <linearGradient id="emailGradient" x1="0" y1="0" x2="0" y2="1">
              <stop offset="5%" stopColor="hsl(173, 80%, 40%)" stopOpacity={0.3} />
              <stop offset="95%" stopColor="hsl(173, 80%, 40%)" stopOpacity={0} />
            </linearGradient>
          </defs>
          <CartesianGrid strokeDasharray="3 3" stroke="hsl(215, 20%, 91%)" />
          <XAxis
            dataKey="name"
            stroke="hsl(215, 16%, 47%)"
            fontSize={12}
            tickLine={false}
            axisLine={false}
          />
          <YAxis
            stroke="hsl(215, 16%, 47%)"
            fontSize={12}
            tickLine={false}
            axisLine={false}
            tickFormatter={(value) => value >= 1000 ? `${value / 1000}k` : `${value}`}
          />
          <Tooltip
            contentStyle={{
              backgroundColor: "hsl(0, 0%, 100%)",
              border: "1px solid hsl(215, 20%, 91%)",
              borderRadius: "0.5rem",
              boxShadow: "0 4px 6px -1px rgba(0, 0, 0, 0.1)",
            }}
          />
          <Area
            type="monotone"
            dataKey="sms"
            stroke="hsl(221, 83%, 53%)"
            strokeWidth={2}
            fillOpacity={1}
            fill="url(#smsGradient)"
          />
          <Area
            type="monotone"
            dataKey="email"
            stroke="hsl(173, 80%, 40%)"
            strokeWidth={2}
            fillOpacity={1}
            fill="url(#emailGradient)"
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}
