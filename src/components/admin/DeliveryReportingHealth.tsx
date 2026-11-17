import {Card,CardContent,CardDescription,CardHeader,CardTitle} from '@/components/ui/card';
import {Badge} from '@/components/ui/badge';
import {Activity} from 'lucide-react';

export interface DeliveryReportingSnapshot {
  available:boolean;status?:'healthy'|'attention';awaiting_confirmation?:number;not_yet_queried?:number;
  older_than_day?:number;callback_auth_configured?:boolean;last_callback_at?:string|null;
  missing_lookups_last_day?:number;gateway_cooldown?:boolean;problems?:string[];
}
export function DeliveryReportingHealth({report}:{report:DeliveryReportingSnapshot|null}) {
  if(!report)return null;
  const copy:Record<string,string>={
    CALLBACK_AUTH_NOT_CONFIGURED:'Authenticated delivery callbacks need configuration.',
    NO_RECENT_DELIVERY_CALLBACKS:'No delivery callback has been recorded in the last 24 hours. Verify the LogicSMS postback configuration.',
    DELIVERY_CONFIRMATIONS_OVERDUE:'Some accepted messages have awaited a final delivery report for more than a day.',
    MESSAGE_LOOKUPS_NEED_REVIEW:'Some message references could not be found. These are not proof of failed delivery and must not trigger a resend.',
    STATUS_GATEWAY_COOLDOWN:'Status checks are temporarily backing off after a gateway error. SMS sending is separate.',
  };
  return <Card aria-label="Delivery reporting health"><CardHeader><div className="flex items-center justify-between gap-2"><CardTitle className="flex items-center gap-2"><Activity className="h-5 w-5"/>Delivery reporting</CardTitle><Badge variant="outline">{!report.available?'Check unavailable':report.status==='healthy'?'Healthy':'Needs attention'}</Badge></div><CardDescription>Sending completion and network delivery confirmation are separate.</CardDescription></CardHeader><CardContent className="space-y-4">
    {!report.available?<p className="text-sm text-muted-foreground">Delivery diagnostics could not be loaded. No message statuses have been changed.</p>:<>
      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">{[['Awaiting confirmation',report.awaiting_confirmation],['Not yet queried',report.not_yet_queried],['Over 24 hours',report.older_than_day],['Missing lookups (24h)',report.missing_lookups_last_day]].map(([label,value])=><div key={String(label)} className="rounded-lg border p-3"><dt className="text-xs text-muted-foreground">{label}</dt><dd className="mt-1 text-xl font-semibold">{Number(value??0).toLocaleString()}</dd></div>)}</dl>
      <p className="text-sm text-muted-foreground">Last recorded callback: {report.last_callback_at?`${report.last_callback_at} SAST`:'None recorded'}.</p>
      {!!report.problems?.length&&<ul className="space-y-2 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm">{report.problems.map(problem=>copy[problem]&&<li key={problem}>{copy[problem]}</li>)}</ul>}
    </>}
  </CardContent></Card>;
}
