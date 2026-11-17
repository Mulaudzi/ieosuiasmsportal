import { Clock, Timer, Gauge, RefreshCw } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

export interface SendingTiming {
  status: 'complete' | 'in_progress' | 'held' | 'waiting' | 'not_started' | 'cancelled' | 'unavailable';
  source: 'accepted_timestamps' | 'original_acknowledgements' | null;
  queue_wait_seconds: number | null;
  sending_seconds: number | null;
  total_seconds: number | null;
  elapsed_seconds: number | null;
  accepted_per_minute: number | null;
  accepted_count: number;
  outstanding_count: number;
  held_count: number;
  skipped_count: number;
}
export interface SendingSummary {
  sample_size: number;
  recent_campaign_count: number;
  average_sending_seconds: number | null;
  fastest_seconds: number | null;
  slowest_seconds: number | null;
}

export function sendingDuration(value: number | null | undefined): string {
  if (value == null || !Number.isFinite(value) || value < 0) return '—';
  const seconds = Math.round(value);
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor(seconds % 86400 / 3600);
  const minutes = Math.floor(seconds % 3600 / 60);
  return [days ? `${days}d` : '', hours ? `${hours}h` : '', minutes ? `${minutes}m` : '', `${seconds % 60}s`].filter(Boolean).join(' ');
}
const labels = {
  complete: 'Sending finished', in_progress: 'Sending in progress', held: 'Held for reconciliation',
  waiting: 'Waiting for worker', not_started: 'Not started', cancelled: 'Cancelled', unavailable: 'Timing unavailable',
};

export function SendingTimeCell({ timing }: { timing?: SendingTiming }) {
  const ongoing = timing?.status === 'in_progress' || timing?.status === 'held';
  return <div className="min-w-36 space-y-1 text-sm">
    <div className="flex items-center gap-1.5 font-medium tabular-nums"><Timer className="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
      {sendingDuration(ongoing ? timing.elapsed_seconds : timing?.status === 'complete' ? timing.sending_seconds : null)}{ongoing && ' elapsed'}
    </div>
    <p className="text-xs text-muted-foreground">{labels[timing?.status ?? 'unavailable']}</p>
    {timing?.queue_wait_seconds != null && <p className="text-xs text-muted-foreground">Queue: {sendingDuration(timing.queue_wait_seconds)}</p>}
  </div>;
}

export function CampaignSendingTiming({ timing, onRefresh }: { timing?: SendingTiming; onRefresh?: () => void }) {
  const ongoing = timing?.status === 'in_progress' || timing?.status === 'held';
  const complete = timing?.status === 'complete';
  const metrics = [
    { title: 'Queue wait', value: sendingDuration(timing?.queue_wait_seconds), icon: Clock },
    { title: ongoing ? 'Sending elapsed' : 'Sending time', value: sendingDuration(ongoing ? timing.elapsed_seconds : complete ? timing.sending_seconds : null), icon: Timer },
    { title: complete ? 'Total including queue' : 'To last acceptance', value: sendingDuration(timing?.total_seconds), icon: Clock },
    { title: 'Acceptance rate', value: timing?.accepted_per_minute != null ? `${timing.accepted_per_minute.toLocaleString()} SMS/min` : '—', icon: Gauge },
  ];
  return <section aria-label="Campaign sending performance" className="space-y-3 rounded-xl border bg-muted/20 p-4">
    <div className="flex flex-wrap items-center justify-between gap-2"><h4 className="font-semibold">Sending performance</h4>{onRefresh && <Button size="sm" variant="outline" onClick={onRefresh}><RefreshCw className="mr-2 h-3.5 w-3.5" />Refresh timing</Button>}</div>
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{metrics.map(({ title, value, icon: Icon }) => <Card key={title}><CardContent className="p-3"><div className="mb-1 flex items-center gap-1.5 text-xs text-muted-foreground"><Icon className="h-3.5 w-3.5" aria-hidden="true" />{title}</div><p className="text-lg font-semibold tabular-nums">{value}</p></CardContent></Card>)}</div>
    <p className="text-sm font-medium">{labels[timing?.status ?? 'unavailable']}{timing ? ` · ${timing.accepted_count.toLocaleString()} accepted · ${timing.outstanding_count.toLocaleString()} unresolved · ${timing.skipped_count.toLocaleString()} skipped` : ''}</p>
    <p className="text-xs text-muted-foreground">Sending time measures worker start to the last provider acceptance, not handset delivery or later administrative completion. {ongoing ? 'Elapsed time is a snapshot at the last refresh, not a finished sending duration. ' : ''}{timing?.source === 'original_acknowledgements' ? 'Reconstructed from complete original acknowledgement records. ' : ''}{timing?.status === 'unavailable' ? 'Reliable original timestamps are missing or inconsistent. ' : ''}Rates count recipient messages, not SMS segments.</p>
  </section>;
}

export function CustomerSendingSummary({ summary }: { summary?: SendingSummary }) {
  return <section aria-label="Customer sending performance" className="rounded-xl border bg-muted/20 p-4">
    <h3 className="mb-3 font-semibold">Recent sending performance</h3>
    <div className="grid grid-cols-3 gap-3 text-sm">{[
      ['Average sending time', summary?.average_sending_seconds], ['Fastest', summary?.fastest_seconds], ['Slowest', summary?.slowest_seconds],
    ].map(([label, value]) => <div key={String(label)}><p className="text-xs text-muted-foreground">{label}</p><p className="mt-1 text-lg font-semibold tabular-nums">{sendingDuration(value as number | null | undefined)}</p></div>)}</div>
    <p className="mt-3 text-xs text-muted-foreground">{summary?.sample_size ?? 0} completed campaigns with reliable timing out of the latest {summary?.recent_campaign_count ?? 0}. Unfinished, held and unavailable timings are excluded. Queue wait and handset delivery are excluded from these averages.</p>
  </section>;
}
