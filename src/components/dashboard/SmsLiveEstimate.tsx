import { analyzeSmsMessage, composeSmsMessage } from '@/lib/sms-message-analysis';
export function SmsLiveEstimate({content,includeFooter,footer,footerLoaded,recipients}:{content:string;includeFooter:boolean;footer:string;footerLoaded:boolean;recipients:number|null}) {
  const analysis=analyzeSmsMessage(composeSmsMessage(content,includeFooter,footer));
  const pending=includeFooter&&(!footerLoaded||!footer.trim());
  const total=recipients===null?null:analysis.segment_count*recipients;
  return <section aria-label="Live SMS totals" className="mb-3 rounded-lg border bg-primary/5 px-3 py-2">
    <dl className="grid grid-cols-[repeat(3,minmax(0,1fr))] gap-2 text-[11px] sm:text-xs [&_dt]:min-h-8 sm:[&_dt]:min-h-0" aria-live="polite" aria-atomic="true"><div><dt className="text-muted-foreground">Characters</dt><dd className="break-all text-sm font-bold tabular-nums sm:text-base" data-sms-character-count>{analysis.character_count}</dd></div><div><dt className="text-muted-foreground">Credits each</dt><dd className="break-all text-sm font-bold tabular-nums sm:text-base" data-sms-part-count>{pending?'…':analysis.segment_count}</dd></div><div><dt className="text-muted-foreground">Est. total credits</dt><dd className="break-all text-sm font-bold tabular-nums sm:text-base" data-sms-total-credits>{pending?'…':total===null?'—':total.toLocaleString()}</dd></div></dl>
    <p data-sms-estimate-note className="mt-1 text-[11px] leading-snug text-muted-foreground">{pending?'Checking saved opt-out text.':`${analysis.encoding==='ucs2'?'Unicode':'Standard text'} · ${recipients===null?'Choose recipients':`${recipients.toLocaleString()} recipients`}${includeFooter?' · Includes opt-out text':''}.`} Review confirms the final cost after personalization.</p>
  </section>;
}
