import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";
import "./page-loading.css";

export type PageSkeletonVariant = "overview" | "contacts" | "table" | "form" | "wizard";

/** Indeterminate: deliberately has no percentage until real progress is known. */
export function LoadingBar() {
  return <div className="page-loading-bar" aria-hidden="true"><span /></div>;
}

function FormPlaceholder() {
  return <div className="space-y-5 rounded-xl border bg-card p-5 sm:p-6">
    <Skeleton className="h-6 w-40 max-w-full" />
    {[0, 1, 2].map(row => <div key={row} className="space-y-2"><Skeleton className="h-3 w-28" /><Skeleton className={row === 2 ? "h-28 w-full" : "h-11 w-full"} /></div>)}
    <div className="flex justify-between gap-4"><Skeleton className="h-10 w-20" /><Skeleton className="h-10 w-36" /></div>
  </div>;
}

/** Neutral placeholders, not fabricated account data or an empty-results state. */
export function PageLoading({ variant = "overview", label = "Loading content", className }: {
  variant?: PageSkeletonVariant; label?: string; className?: string;
}) {
  return <div role="status" aria-label={label} aria-busy="true" className={cn("min-w-0 space-y-4", className)}>
    <span className="sr-only">{label}</span>
    <div aria-hidden="true" className="space-y-4">
      <LoadingBar />
      {variant === "wizard" && <div className="grid grid-cols-5 gap-2 py-3">{[0, 1, 2, 3, 4].map(step => <div className="flex min-w-0 flex-col items-center gap-3" key={step}><Skeleton className="h-9 w-9 rounded-full" /><Skeleton className="h-3 w-16 max-w-full" /></div>)}</div>}
      {(variant === "overview" || variant === "contacts") && <div className="grid grid-cols-[repeat(2,minmax(0,1fr))] gap-3 xl:grid-cols-4">{[0, 1, 2, 3].map(card => <div key={card} className="flex min-w-0 items-center gap-3 rounded-xl border bg-card p-3 sm:p-5"><Skeleton className="h-8 w-8 shrink-0 rounded-xl sm:h-12 sm:w-12" /><div className="min-w-0 flex-1 space-y-3"><Skeleton className="h-3 w-24 max-w-full" /><Skeleton className="h-7 w-16 max-w-full" /></div></div>)}</div>}
      {variant === "form" || variant === "wizard" ? <FormPlaceholder /> : variant === "overview" ? <div className="grid gap-4 lg:grid-cols-2">{[0, 1].map(card => <div key={card} className="space-y-5 rounded-xl border bg-card p-5"><Skeleton className="h-5 w-40 max-w-full" /><Skeleton className="h-48 w-full" /></div>)}</div> : <div className={cn("grid gap-4", variant === "contacts" && "lg:grid-cols-[240px_minmax(0,1fr)]")}>
        {variant === "contacts" && <div className="space-y-4 rounded-xl border bg-card p-5"><Skeleton className="h-5 w-24" />{[0, 1, 2, 3].map(row => <Skeleton key={row} className={cn("h-10 w-full", row > 1 && "hidden lg:block")} />)}</div>}
        <div className="min-w-0 space-y-5 rounded-xl border bg-card p-5"><div className="flex gap-3"><Skeleton className="h-10 flex-1" /><Skeleton className="h-10 w-20" /></div>{[0, 1, 2, 3, 4].map(row => <div key={row} className="flex items-center gap-4 border-t pt-4"><Skeleton className="h-5 w-5 shrink-0" /><Skeleton className="h-4 flex-1" /><Skeleton className="h-4 w-20" /><Skeleton className="hidden h-4 w-28 sm:block" /></div>)}</div>
      </div>}
    </div>
  </div>;
}

/** Authentication/bootstrap fallback: no private navigation or account data. */
export function AppLoadingScreen() {
  return <div className="min-h-[100dvh] bg-background">
    <div aria-hidden="true" className="hidden fixed inset-y-0 left-0 w-64 space-y-7 bg-sidebar p-6 lg:block"><div className="h-10 rounded-lg bg-white/10" />{[0, 1, 2, 3, 4, 5].map(row => <div key={row} className="h-9 rounded-lg bg-white/10" />)}</div>
    <div className="lg:pl-64"><div aria-hidden="true" className="flex h-16 items-center justify-between gap-6 border-b bg-card px-5"><Skeleton className="h-9 w-full max-w-sm" /><Skeleton className="h-9 w-9 shrink-0 rounded-full" /></div><main className="mx-auto max-w-[1500px] space-y-6 p-4 sm:p-6"><div aria-hidden="true" className="space-y-3"><Skeleton className="h-8 w-48 max-w-full" /><Skeleton className="h-4 w-72 max-w-full" /></div><PageLoading label="Preparing your workspace" /></main></div>
  </div>;
}
