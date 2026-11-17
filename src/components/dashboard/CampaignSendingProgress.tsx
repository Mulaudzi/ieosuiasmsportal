import { Clock3, Send, ShieldCheck } from 'lucide-react';
import { sendingProgress } from '@/lib/sending-progress';
import type { SummaryCampaign } from './CampaignSummary';
import { SendingProgressBar } from './SendingProgressBar';
import './sending-progress.css';

/** Small row companion. Delivery reports remain in their own column. */
export function CampaignSendingProgress({campaign}:{campaign:SummaryCampaign}) {
  const state=(campaign.state??campaign.status??'').toLowerCase();
  const p=sendingProgress({state,total:Number(campaign.recipient_count),sent:Number(campaign.sent_count),excluded:Number(campaign.skipped_count??0),failed:Number(campaign.failed_submission_count??0),held:Number(campaign.held_submission_count??0),processing:Number(campaign.processing_count??0)});
  if(p.finished||['draft','cancelled'].includes(state)||p.total===0)return null;
  const Icon=p.kind==='held'||p.kind==='reconciling'?ShieldCheck:p.active?Send:Clock3;
  const label=p.kind==='held'?`${p.held.toLocaleString()} awaiting confirmation`:p.kind==='reconciling'?'Confirming results':p.kind==='scheduled'?'Scheduled to send':p.preparing?(p.kind==='queued'?'Queued · getting ready':'Getting messages ready'):`${p.processed.toLocaleString()} of ${p.total.toLocaleString()} confirmed`;
  return <div className="campaign-sending-mini" data-tone={p.kind==='held'||p.kind==='reconciling'?'attention':'primary'} data-motion={p.preparing?'preparing':p.active?'sending':'still'} aria-label={`Sending progress for ${campaign.name||'Unnamed campaign'}`}><div className="campaign-sending-mini__label"><span><Icon size={12} aria-hidden="true"/>{label}</span><strong>{p.percent}%</strong></div><SendingProgressBar preparing={p.preparing} percent={p.percent} label={`Sending progress for ${campaign.name||'Unnamed campaign'}`} description={`${p.processed} of ${p.total} sending results confirmed${p.preparing?'; waiting for the first sending result':''}`}/></div>;
}
