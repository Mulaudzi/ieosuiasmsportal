import { DeliveryStatusHelp } from "@/components/dashboard/DeliveryStatusHelp";
import { deliveryChartStyle } from '@/lib/delivery-presentation';
import { DeliveryOutcomeList } from './DeliveryOutcomeList';
import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip } from "recharts";
import { Loader2 } from "lucide-react";
import { dashboardDelivery, DashboardDeliverySummary } from '@/lib/delivery-visibility';
import { DeliveryResultsPending } from './DeliveryResultsPending';
import { DeliveryBreakdown } from './DeliveryBreakdown';

interface DeliveryData {
  name: string;
  value: number;
  color: string;
}

export function DeliveryStats({stats,loading=false}:{stats:DashboardDeliverySummary|null;loading?:boolean}) {
  const visibility=stats?dashboardDelivery(stats):null;
  const data:DeliveryData[]=visibility?[
    'Delivered','Sent - Awaiting Confirmation','Sent - Confirmation Unavailable','Not Delivered','Not Sent',
  ].map((name,index)=>({name,value:visibility.counts[index],color:deliveryChartStyle(name).color})):[];
  const successRate=stats?.delivery_rate??0;

  if (loading) {
    return (
      <div className="metric-card h-full flex items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (!visibility?.available) {
    return (
      <div className="metric-card h-full">
        <div className="mb-4">
          <h3 className="text-lg font-semibold text-foreground">Delivery Results</h3>
          <p className="text-sm text-muted-foreground">Sending and delivery confirmation are separate</p>
        </div>
        {visibility?<DeliveryResultsPending counts={visibility.counts} total={visibility.total}/>:<p className="text-sm text-muted-foreground">Delivery results could not be loaded. Please refresh.</p>}
      </div>
    );
  }

  return (
    <div className="metric-card h-full">
      <div className="mb-4">
        <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="panel-title">Delivery Confirmations</h3><DeliveryBreakdown counts={visibility.counts} total={visibility.total} failedSubmissions={stats?.total_failed_submissions??0} link/></div>
        <p className="text-sm text-muted-foreground">{visibility.complete?'Latest network-confirmed results':'Latest results. Confirmations are still updating.'}</p>
      </div>

      <div className="delivery-layout">
        <div className="relative h-32 w-32 shrink-0" role="img" aria-label={`${successRate}% network-confirmed deliveries`}>
          <ResponsiveContainer width="100%" height="100%">
            <PieChart>
              <Pie
                data={data.filter(item=>item.value>0)}
                cx="50%"
                cy="50%"
                innerRadius={40}
                outerRadius={58}
                isAnimationActive={false}
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

        <div className="delivery-legend space-y-3">
          <DeliveryOutcomeList counts={data.map(item=>item.value)} tooltips={false} total={visibility.total} compact/>
        </div>
      </div>
      <p className="mt-3 text-xs text-muted-foreground">Not Sent recipients and failed submissions are excluded from Total Sent and delivery rate. Pending confirmations are not failures.</p>
      <DeliveryStatusHelp />
    </div>
  );
}
