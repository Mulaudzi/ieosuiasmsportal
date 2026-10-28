import { Ban, Clock3, FolderOpen, Users } from 'lucide-react';

export function ContactSummaryCards({total, groups, optedOut, recentlyAdded}: {total: number | null; groups: number | null; optedOut: number | null; recentlyAdded: number | null}) {
  return <section aria-label="Contact summary" className="grid grid-cols-[repeat(2,minmax(0,1fr))] gap-3 xl:grid-cols-4">
    {[
      {label:'Total Contacts',value:total,icon:Users,tone:'border-blue-500/15 bg-blue-500/5 text-blue-600'},
      {label:'Groups',value:groups,icon:FolderOpen,tone:'border-teal-500/15 bg-teal-500/5 text-teal-600'},
      {label:'Opted Out',value:optedOut,icon:Ban,tone:'border-rose-500/15 bg-rose-500/5 text-rose-600'},
      {label:'Recently Added',value:recentlyAdded,icon:Clock3,tone:'border-violet-500/15 bg-violet-500/5 text-violet-600'},
    ].map(item => <div key={item.label} className={`flex min-w-0 flex-col items-start gap-2 rounded-2xl border p-3 shadow-sm sm:flex-row sm:items-center sm:gap-3 sm:p-5 ${item.tone}`}>
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-background/70 sm:h-10 sm:w-10"><item.icon className="h-5 w-5 sm:h-6 sm:w-6" aria-hidden="true"/></span>
      <div className="min-w-0"><h2 className="text-xs font-medium text-muted-foreground sm:text-sm">{item.label}</h2><p className="mt-1 text-2xl font-bold tabular-nums text-foreground">{item.value === null ? '—' : item.value.toLocaleString()}</p>{item.label==='Recently Added' && <p className="mt-0.5 text-xs text-muted-foreground">In the last 7 days</p>}</div>
    </div>)}
  </section>;
}
