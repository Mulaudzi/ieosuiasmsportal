import { Skeleton } from "@/components/ui/skeleton";

export function MetricCardSkeleton() {
  return (
    <div className="metric-card">
      <div className="flex items-center justify-between">
        <div className="space-y-2">
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-8 w-32" />
          <Skeleton className="h-3 w-28" />
        </div>
        <Skeleton className="h-12 w-12 rounded-xl" />
      </div>
    </div>
  );
}

export function CampaignTableRowSkeleton() {
  return (
    <tr className="border-b border-border">
      <td className="px-6 py-4">
        <Skeleton className="h-5 w-40" />
      </td>
      <td className="px-6 py-4">
        <Skeleton className="h-6 w-20 rounded-full" />
      </td>
      <td className="px-6 py-4">
        <Skeleton className="h-5 w-16" />
      </td>
      <td className="px-6 py-4">
        <Skeleton className="h-5 w-16" />
      </td>
      <td className="px-6 py-4">
        <Skeleton className="h-5 w-24" />
      </td>
      <td className="px-6 py-4">
        <Skeleton className="h-8 w-8 rounded" />
      </td>
    </tr>
  );
}

export function CampaignListSkeleton({ rows = 5 }: { rows?: number }) {
  return (
    <div className="rounded-xl border border-border bg-card overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr className="border-b border-border bg-muted/50">
              <th className="px-6 py-4 text-left"><Skeleton className="h-4 w-20" /></th>
              <th className="px-6 py-4 text-left"><Skeleton className="h-4 w-16" /></th>
              <th className="px-6 py-4 text-left"><Skeleton className="h-4 w-20" /></th>
              <th className="px-6 py-4 text-left"><Skeleton className="h-4 w-16" /></th>
              <th className="px-6 py-4 text-left"><Skeleton className="h-4 w-16" /></th>
              <th className="px-6 py-4 text-left"><Skeleton className="h-4 w-12" /></th>
            </tr>
          </thead>
          <tbody>
            {Array.from({ length: rows }).map((_, i) => (
              <CampaignTableRowSkeleton key={i} />
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

export function ChartSkeleton() {
  return (
    <div className="rounded-xl border border-border bg-card p-6">
      <div className="space-y-2 mb-6">
        <Skeleton className="h-6 w-40" />
        <Skeleton className="h-4 w-64" />
      </div>
      <Skeleton className="h-64 w-full rounded-lg" />
    </div>
  );
}

export function ContactCardSkeleton() {
  return (
    <div className="rounded-xl border border-border bg-card p-5">
      <div className="flex items-start gap-4">
        <Skeleton className="h-12 w-12 rounded-full" />
        <div className="flex-1 space-y-2">
          <Skeleton className="h-5 w-32" />
          <Skeleton className="h-4 w-40" />
          <Skeleton className="h-4 w-28" />
        </div>
      </div>
    </div>
  );
}

export function TemplateCardSkeleton() {
  return (
    <div className="rounded-xl border border-border bg-card p-5">
      <div className="flex items-start justify-between">
        <Skeleton className="h-10 w-10 rounded-lg" />
        <div className="flex gap-1">
          <Skeleton className="h-8 w-8 rounded" />
          <Skeleton className="h-8 w-8 rounded" />
        </div>
      </div>
      <Skeleton className="mt-4 h-5 w-32" />
      <Skeleton className="mt-2 h-10 w-full" />
      <div className="mt-4 flex justify-between border-t border-border pt-4">
        <Skeleton className="h-3 w-20" />
        <Skeleton className="h-3 w-24" />
      </div>
    </div>
  );
}

export function DashboardSkeleton() {
  return (
    <div className="space-y-6">
      {/* Metric Cards */}
      <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <MetricCardSkeleton key={i} />
        ))}
      </div>
      
      {/* Charts */}
      <div className="grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <ChartSkeleton />
        </div>
        <ChartSkeleton />
      </div>
      
      {/* Table */}
      <CampaignListSkeleton rows={3} />
    </div>
  );
}
