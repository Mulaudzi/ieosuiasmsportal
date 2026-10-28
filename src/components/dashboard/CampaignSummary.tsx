import { campaignReference } from '@/lib/campaign-reference';
import { Link } from 'react-router-dom';
import { ArrowRight, MessageSquare } from 'lucide-react';
import { CampaignStatusBadge } from './CampaignStatusBadge';
import { SendingOverview } from './SendingOverview';
import { CampaignDeliveryBar } from './CampaignDeliveryBar';
import { portalDate } from '@/lib/portal-presentation';

export interface SummaryCampaign {
 id:string;name:string;state?:string;status?:string;recipient_count:number;sent_count:number;
 delivered_count:number;failed_count:number;failed_submission_count?:number;skipped_count?:number;held_submission_count?:number;queued_count?:number;processing_count?:number;
 awaiting_delivery_count?:number;dlr_unavailable_count?:number;credits_used?:number;created_at:string;
}
export function LatestCampaign({campaign}:{campaign?:SummaryCampaign}) {
 return <section className="metric-card min-w-0">
  <div className="mb-4 flex items-center justify-between gap-3"><h2 className="panel-title">Latest Campaign Performance</h2>{campaign&&<Link to={`/sms-campaigns/${campaign.id}`} className="inline-flex shrink-0 items-center gap-2 text-sm text-primary">View campaign <ArrowRight size={15}/></Link>}</div>
  {campaign?<><div className="mb-4 flex items-start gap-3"><MessageSquare className="shrink-0 rounded-lg bg-primary/10 p-2 text-primary" size={40}/><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><h3 className="break-words font-semibold">{campaign.name || 'Unnamed campaign'}</h3><CampaignStatusBadge campaign={campaign}/></div><p className="mt-1 text-xs text-muted-foreground">{campaignReference(campaign)} · Created {portalDate(campaign.created_at)}</p></div></div><SendingOverview total={Number(campaign.recipient_count)} sent={Number(campaign.sent_count)} excluded={Number(campaign.skipped_count??0)} failed={Number(campaign.failed_submission_count??0)} held={Number(campaign.held_submission_count??0)} campaign compact/></>:<p className="py-8 text-sm text-muted-foreground">No campaigns created in this period. Choose another date range or create a campaign.</p>}
 </section>;
}
export function RecentCampaignTable({campaigns}:{campaigns:SummaryCampaign[]}) {
 return <section className="metric-card min-w-0"><div className="mb-4 flex items-center justify-between gap-3"><div><h2 className="panel-title">Recent Campaigns</h2><p className="text-sm text-muted-foreground">Campaigns created in the selected period · lifetime campaign results</p></div><Link to="/sms-campaigns" className="shrink-0 text-sm text-primary">View all →</Link></div>
  {!campaigns.length?<p className="py-6 text-sm text-muted-foreground">No campaigns in this period.</p>:<div className="portal-table-scroll"><table className="portal-table min-w-[880px]"><thead><tr>{['Campaign','Sending status','Recipients','Delivery breakdown','SMS Credits Used','Created','Actions'].map(label=><th key={label} scope="col">{label}</th>)}</tr></thead><tbody>{campaigns.map(c=><tr key={c.id}><td><Link to={`/sms-campaigns/${c.id}`} className="font-semibold hover:text-primary">{c.name || 'Unnamed campaign'}</Link><p className="text-xs text-muted-foreground">{campaignReference(c)}</p><p className="mt-1 text-xs text-muted-foreground">{Number(c.sent_count).toLocaleString()} sent / {Number(c.recipient_count).toLocaleString()} recipients</p></td><td><CampaignStatusBadge campaign={c}/></td><td>{Number(c.recipient_count).toLocaleString()}</td><td><CampaignDeliveryBar delivered={Number(c.delivered_count)} failed={Number(c.failed_count)} counts={[c.delivered_count,c.awaiting_delivery_count??0,c.dlr_unavailable_count??0,c.failed_count,c.skipped_count??0].map(Number)} total={Number(c.recipient_count)} failedSubmissions={Number(c.failed_submission_count??0)}/></td><td>{c.credits_used===undefined?'—':Number(c.credits_used).toLocaleString()}</td><td>{portalDate(c.created_at)}</td><td><Link aria-label={`View ${c.name}`} to={`/sms-campaigns/${c.id}`} className="text-primary">View</Link></td></tr>)}</tbody></table></div>}
 </section>;
}
