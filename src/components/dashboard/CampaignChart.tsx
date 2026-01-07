import {
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from "recharts";

const data = [
  { name: "Mon", sms: 4000, email: 2400 },
  { name: "Tue", sms: 3000, email: 1398 },
  { name: "Wed", sms: 5000, email: 3800 },
  { name: "Thu", sms: 2780, email: 3908 },
  { name: "Fri", sms: 6890, email: 4800 },
  { name: "Sat", sms: 2390, email: 3800 },
  { name: "Sun", sms: 3490, email: 4300 },
];

export function CampaignChart() {
  return (
    <div className="metric-card h-[400px]">
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h3 className="text-lg font-semibold text-foreground">
            Message Volume
          </h3>
          <p className="text-sm text-muted-foreground">
            SMS and Email sends over the past week
          </p>
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
            tickFormatter={(value) => `${value / 1000}k`}
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
