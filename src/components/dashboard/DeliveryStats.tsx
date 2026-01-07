import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip } from "recharts";

const data = [
  { name: "Delivered", value: 89, color: "hsl(160, 84%, 39%)" },
  { name: "Pending", value: 6, color: "hsl(38, 92%, 50%)" },
  { name: "Failed", value: 3, color: "hsl(0, 72%, 51%)" },
  { name: "Bounced", value: 2, color: "hsl(215, 16%, 47%)" },
];

export function DeliveryStats() {
  return (
    <div className="metric-card h-full">
      <div className="mb-4">
        <h3 className="text-lg font-semibold text-foreground">
          Delivery Rate
        </h3>
        <p className="text-sm text-muted-foreground">
          Overall message delivery performance
        </p>
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
              <p className="text-2xl font-bold text-foreground">89%</p>
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
                <span className="text-sm text-muted-foreground">
                  {item.name}
                </span>
              </div>
              <span className="text-sm font-medium text-foreground">
                {item.value}%
              </span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
