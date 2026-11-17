import { Check, CheckCheck, Clock3, FileText, ListChecks, Pause, Send, ShieldCheck, TriangleAlert, Users } from 'lucide-react';
import { sendingProgress, type SendingProgressInput } from '@/lib/sending-progress';
import { SendingActivity } from './SendingActivity';
import { SendingProgressBar } from './SendingProgressBar';
import './sending-progress.css';

export function SendingOverview({campaign=false,compact=false,...input}:SendingProgressInput & {campaign?:boolean;compact?:boolean}) {
  const p=sendingProgress(input);
  const title=p.kind==='empty'?'No SMS messages sent yet':p.kind==='draft'?'Your campaign is a draft':p.kind==='scheduled'?'Scheduled and ready':p.kind==='queued'?'Your campaign is in the queue':p.kind==='cancelled'?'Campaign cancelled':p.kind==='held'?'Awaiting provider confirmation':p.kind==='reconciling'?'Confirming sending results':p.preparing?'Getting your messages ready':p.active?'Sending in progress':p.sent>0&&p.failed===0
    ?campaign?'Your SMS campaign has been sent successfully!':'Your SMS messages have been sent successfully!'
    :p.sent>0?`${p.sent.toLocaleString()} ${p.sent===1?'message':'messages'} successfully sent`:'No messages were successfully sent';
  const Icon=p.finished?(p.kind==='complete'?CheckCheck:TriangleAlert):p.kind==='held'||p.kind==='reconciling'?ShieldCheck:p.kind==='cancelled'?Pause:p.kind==='draft'?FileText:p.active?Send:Clock3;
  const badge=p.preparing?(p.kind==='queued'?'Queued':'Getting ready'):p.active?'In progress':p.kind==='complete'?'Sending complete':p.kind==='issues'?'Results ready':p.kind==='held'||p.kind==='reconciling'?'Checking results':p.kind==='queued'?'Queued':p.kind==='scheduled'?'Scheduled':p.kind==='cancelled'?'Stopped':p.kind==='draft'?'Draft':'Getting started';
  const tone=p.kind==='complete'?'success':p.kind==='held'||p.kind==='issues'||p.kind==='reconciling'?'attention':p.kind==='cancelled'||p.kind==='draft'||p.kind==='empty'?'neutral':'primary';
  const description=p.kind==='scheduled'?'Sending will begin at your scheduled time. No messages have been sent early.':p.kind==='queued'?'Your recipients are queued. Sending progress will appear here as the provider confirms each result.':p.kind==='draft'?'Review your message and recipients, then queue this campaign when you are ready.':p.kind==='cancelled'?'Sending has stopped. Any messages already accepted can still receive delivery confirmations.':p.kind==='held'||p.kind==='reconciling'?'Some sending results still need confirmation. We won’t resend uncertain messages or count them as delivered.':p.sent>0?'Your messages have been submitted for delivery. Delivery confirmations will update automatically as we receive reports from the mobile networks.':'Messages count as successfully sent only after the SMS provider accepts them for delivery.';
  const metrics=[{label:'Total recipients',value:p.total,icon:Users,tone:''},{label:'Successfully sent',value:p.sent,icon:CheckCheck,tone:'success'},{label:p.kind==='cancelled'?'Not processed':'Waiting to send',value:p.waitingToSend,icon:Clock3,tone:'primary'},{label:'Not sent or excluded',value:p.excluded,icon:ListChecks,tone:''},{label:'Failed submissions',value:p.failed,icon:TriangleAlert,tone:p.failed?'attention':''}];
  return <section className={`sending-overview ${compact?'sending-overview--compact':'metric-card'}`} data-tone={tone} data-motion={p.preparing?'preparing':p.active?'sending':'still'} aria-label="Campaign Sending Overview">
    <div className="sending-overview__hero">
      <div className="sending-overview__topline"><h2 className="sending-overview__eyebrow">{compact?'Sending progress':'Campaign Sending Overview'}</h2><span className="sending-overview__badge"><span className="sending-overview__dot" aria-hidden="true"/>{badge}</span></div>
      <div className="sending-overview__headline"><span className="sending-overview__symbol" aria-hidden="true"><Icon size={25} strokeWidth={1.8}/></span><div className="min-w-0 flex-1"><h3 className="sending-overview__title">{title}</h3><p className="sending-overview__description">{description}</p></div><div className="sending-overview__percent"><strong>{p.percent}<span>%</span></strong><span>processed</span></div></div>
      {(p.preparing||p.active)&&<SendingActivity preparing={p.preparing} queued={p.kind==='queued'}/>}
      <div className="sending-overview__barline"><span><strong>{p.processed.toLocaleString()}</strong> of {p.total.toLocaleString()} results confirmed</span><span>{p.waiting.toLocaleString()} remaining</span></div>
      <SendingProgressBar preparing={p.preparing} percent={p.percent} label="Sending progress" description={`${p.processed.toLocaleString()} of ${p.total.toLocaleString()} results confirmed. ${p.held.toLocaleString()} awaiting provider confirmation.${p.preparing?' Waiting for the first sending result.':''}`}/>
      <ol className="sending-overview__steps" aria-label="Campaign sending stages">{['Queued','Sending','Sending finished'].map((label,index)=>{
        const done=p.phase>index+1||p.finished, current=!p.finished&&p.phase===index+1;
        return <li key={label} data-step={done?'done':current?'current':'upcoming'} aria-current={current?'step':undefined}><span className="sending-overview__step-icon" aria-hidden="true">{done?<Check size={12}/>:current&&(p.kind==='held'||p.kind==='cancelled'||p.kind==='reconciling')?<Pause size={11}/>:index+1}</span><span>{label}</span></li>;
      })}</ol>
    </div>
    <div className="sending-overview__body"><dl className="sending-overview__metrics">{metrics.map(({label,value,icon:MetricIcon,tone:metricTone})=><div key={label} data-metric-tone={metricTone}><dt><MetricIcon size={14} aria-hidden="true"/>{label}</dt><dd>{value.toLocaleString()}</dd></div>)}</dl>
      {p.failed>0&&<p className="mt-3 text-xs text-muted-foreground">{p.failed.toLocaleString()} {p.failed===1?'submission was':'submissions were'} unsuccessful. These messages are separate from network delivery failures.</p>}
      {p.held>0&&<div role="status" className="sending-overview__notice"><ShieldCheck size={19} aria-hidden="true"/><div><p className="font-semibold">{p.held.toLocaleString()} {p.held===1?'result needs':'results need'} confirmation</p><p className="mt-1">{p.held.toLocaleString()} SMS {p.held===1?'has an unconfirmed provider result':'have unconfirmed provider results'}. These messages may already have arrived; this does not mean they are still being sent. We need a verified provider result before updating them. Resending is blocked to prevent duplicates. The original credits remain reserved, not charged again. Contact <a className="underline" href="mailto:sms@ieosuia.com">sms@ieosuia.com</a> with your campaign reference.</p></div></div>}
      <p className="sending-overview__footnote"><ShieldCheck size={13} aria-hidden="true"/>Sending progress tracks provider acceptance, not delivery to a phone.</p>
    </div>
  </section>;
}
