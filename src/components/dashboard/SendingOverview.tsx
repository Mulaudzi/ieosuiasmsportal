export function SendingOverview({total,sent,excluded=0,failed=0,held=0,campaign=false,compact=false}:{total:number;sent:number;excluded?:number;failed?:number;held?:number;campaign?:boolean;compact?:boolean}) {
  const waiting=Math.max(0,total-sent-excluded-failed);
  const processed=Math.min(total,sent+excluded+failed);
  const percent=total>0?Math.round(100*processed/total):0;
  const title=total===0?'No SMS messages sent yet':held>0&&waiting===held?'Awaiting provider confirmation':waiting>0?'Sending in progress':sent>0&&failed===0
    ?campaign?'Your SMS campaign has been sent successfully!':'Your SMS messages have been sent successfully!'
    :sent>0?`${sent.toLocaleString()} ${sent===1?'message':'messages'} successfully sent`:'No messages were successfully sent';
  return <section className={compact?'':'metric-card'} aria-label="Campaign Sending Overview">
    {!compact&&<><h2 className="panel-title">Campaign Sending Overview</h2>
    <p className="mt-2 font-medium">{title}</p>
    <p className="mt-1 text-sm text-muted-foreground">{sent>0?'Your messages have been submitted for delivery. Delivery confirmations will update automatically as we receive reports from the mobile networks.':'Messages count as successfully sent only after the SMS provider accepts them for delivery.'}</p></>}
    <dl className="mt-4 flex flex-wrap gap-3">{[['Total recipients',total],['Successfully sent',sent],['Not sent or excluded',excluded],['Failed submissions',failed]].map(([label,count])=><div key={label} className="min-w-0 flex-[1_1_calc(50%_-_0.75rem)] lg:flex-[1_1_calc(25%_-_0.75rem)]"><dt className="text-sm text-muted-foreground">{label}</dt><dd className="text-xl font-semibold tabular-nums">{Number(count).toLocaleString()}</dd></div>)}</dl>
    <div className="mt-4"><p className="mb-1 text-xs text-muted-foreground">Sending progress: {processed.toLocaleString()} of {total.toLocaleString()} results confirmed{held>0?` (${held.toLocaleString()} awaiting provider confirmation)`:''}{waiting>held?` (${(waiting-held).toLocaleString()} waiting to send)`:''}</p><progress className="block h-2 w-full accent-primary" aria-label="Sending progress" value={percent} max={100}/></div>
    {failed>0&&<p className="mt-2 text-sm text-muted-foreground">{failed.toLocaleString()} {failed===1?'submission was':'submissions were'} unsuccessful. These messages are separate from network delivery failures.</p>}
    {held>0&&<p role="status" className="mt-3 rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm">{held.toLocaleString()} SMS {held===1?'has an unconfirmed provider result':'have unconfirmed provider results'}. These messages may already have arrived; this does not mean they are still being sent. We need a verified provider result before updating them. Resending is blocked to prevent duplicates. The original credits remain reserved, not charged again. Contact <a className="underline" href="mailto:sms@ieosuia.com">sms@ieosuia.com</a> with your campaign reference.</p>}
  </section>;
}
