import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { PageLoading } from '@/components/ui/page-loading';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { CampaignStatusBadge } from '@/components/dashboard/CampaignStatusBadge';
import { MetricCard } from '@/components/dashboard/MetricCard';
import { DeliveryStats } from '@/components/dashboard/DeliveryStats';
import { Users, Send, Clock, Layers, CheckCircle, Copy, ArrowLeft } from 'lucide-react';
import { portalDate } from '@/lib/portal-presentation';
import { api, getSmsCampaign, retrySmsCampaign, queueSmsCampaign, cancelSmsCampaign } from '@/lib/api';
import { toast } from '@/hooks/use-toast';
import { deliveryVisibility } from '@/lib/delivery-visibility';
import { recipientDelivery } from '@/lib/delivery-presentation';
import { SendingOverview } from '@/components/dashboard/SendingOverview';
import { campaignReference } from '@/lib/campaign-reference';
import { useSmsRefresh } from '@/hooks/useSmsRefresh';

interface Campaign { id:string;name:string;content:string;state:string;recipient_count:number;sent_count:number;failed_submission_count?:number;held_submission_count?:number;credits_used:number;queued_count:number;processing_count:number;awaiting_delivery_count:number;dlr_unavailable_count:number;delivered_count:number;failed_count:number;skipped_count:number;total_segments:number;estimated_cost:number;actual_cost:number;created_at:string;scheduled_at?:string;started_at?:string;completed_at?:string }
interface Recipient {id:string;contact:string;number:string;status:string;state?:string;technical_status?:string;reason_code?:string|null;segments:number;sent:string;result:string}
const choices=[['all','All recipients'],['delivered','Delivered'],['sent','Sent - Awaiting Confirmation'],['dlr_unavailable','Sent - Confirmation Unavailable'],['failed','Not Delivered'],['skipped','Not Sent'],['failed_to_send','Failed to send'],['pending','Waiting to send'],['processing','Sending']];

export default function CampaignDetails(){
 const {id=''}=useParams();const [urlParams]=useSearchParams();
 const [campaign,setCampaign]=useState<Campaign|null>(null);const [rows,setRows]=useState<Recipient[]>([]);const [total,setTotal]=useState(0);
 const [search,setSearch]=useState('');const [state,setState]=useState('all');const [page,setPage]=useState(1);const [loading,setLoading]=useState(true);const [error,setError]=useState('');
 const [segments,setSegments]=useState('all');
 const [busy,setBusy]=useState(false);const [exporting,setExporting]=useState(false);const sequence=useRef(0);const downloaded=useRef('');
 const load=useCallback(async(showLoading=true)=>{
  const request=++sequence.current;if(showLoading){setLoading(true);setRows([]);}setError('');
  try{
   const [c,r]=await Promise.all([getSmsCampaign(id),api.get<{rows:Recipient[];total:number}>(`/sms/campaigns-v2/${id}/recipient-view`,{state,search:search.trim(),segments,page:String(page)})]);
   if(request!==sequence.current)return;
   const cp=(c.data??c) as {campaign:Campaign};const rp=(r.data??r) as {rows:Recipient[];total:number};
   if(!c.success||!r.success||!cp.campaign||!Array.isArray(rp.rows))throw new Error('Invalid campaign data');
   setCampaign(cp.campaign);setRows(rp.rows);setTotal(rp.total);
  }catch{if(request===sequence.current)setError('Could not load this campaign. Please refresh.');}
  finally{if(request===sequence.current)setLoading(false);}
 },[id,state,search,segments,page]);
 useEffect(()=>{setCampaign(null);setState('all');setSearch('');setSegments('all');setPage(1);},[id]);
 const invalidate=useCallback(()=>{sequence.current++;},[]);
 useEffect(()=>{void load();return invalidate;},[load,invalidate]);
 useSmsRefresh(()=>load(false));
 const download=useCallback(async(format:'csv'|'pdf',filter:string,filtered=false)=>{
  setExporting(true);
  try{
   const base=import.meta.env.VITE_API_BASE_URL||'https://sms.ieosuia.com/api';
   const query=new URLSearchParams({format,state:filter,...(filtered?{search:search.trim(),segments}:{})});
   const response=await fetch(`${base}/sms/campaigns-v2/${id}/recipient-export?${query}`,{headers:{Authorization:`Bearer ${localStorage.getItem('auth_token')||''}`}});
   if(!response.ok){const error=await response.json().catch(()=>({}));throw new Error(error.error||error.message||'Export failed. Please try again.');}
   const url=URL.createObjectURL(await response.blob());const a=document.createElement('a');
   a.href=url;a.download=(response.headers.get('Content-Disposition')?.match(/filename="([^"\r\n]+)"/)?.[1]||`campaign-${filter}.${format}`).replace(/[\\/]/g,'-');
   document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),60000);
  }catch(e){toast({title:e instanceof Error?e.message:'Export failed',variant:'destructive'});}finally{setExporting(false);}
 },[id,search,segments]);
 useEffect(()=>{const format=urlParams.get('download');const key=id+format;if(String(campaign?.id)===id&&(format==='pdf'||format==='csv')&&downloaded.current!==key){downloaded.current=key;void download(format,'all');}},[campaign,id,urlParams,download]);
 const action=async(kind:'retry'|'queue'|'cancel')=>{setBusy(true);try{if(kind==='retry')await retrySmsCampaign(id);else if(kind==='queue')await queueSmsCampaign(id);else await cancelSmsCampaign(id);await load();}catch(e){toast({title:'Campaign could not be updated',description:e instanceof Error?e.message:'Please retry.',variant:'destructive'});}finally{setBusy(false);}};
 if(!campaign)return <DashboardLayout title="Campaign" subtitle={error || undefined}>{loading?<PageLoading label="Loading campaign details"/>:<><Link to="/sms-campaigns">Back to campaigns</Link>{error&&<Button onClick={()=>void load()}>Refresh</Button>}</>}</DashboardLayout>;
 const counts:Record<string,number>={all:Number(campaign.recipient_count),delivered:Number(campaign.delivered_count),failed:Number(campaign.failed_count),failed_to_send:Number(campaign.failed_submission_count??0),skipped:Number(campaign.skipped_count),dlr_unavailable:Number(campaign.dlr_unavailable_count),pending:Number(campaign.queued_count),processing:Number(campaign.processing_count),sent:Number(campaign.awaiting_delivery_count)};
 const outcomeCounts=[counts.delivered,counts.sent,Number(campaign.dlr_unavailable_count),counts.failed,Number(campaign.skipped_count)];
 const delivery=deliveryVisibility(outcomeCounts,counts.all,counts.failed_to_send);
 const rate=Number(campaign.sent_count)>0?`${(counts.delivered/Number(campaign.sent_count)*100).toFixed(1)}%`:'—';
 const metrics=[{title:'Recipients',value:counts.all,icon:Users},{title:'Successfully sent',value:Number(campaign.sent_count||0),icon:Send},{title:Number(campaign.held_submission_count??0)>0 && delivery.waitingToSend===Number(campaign.held_submission_count)?'Awaiting confirmation':'Waiting to send',value:delivery.waitingToSend,icon:Clock},{title:'SMS Credits Used',value:Number(campaign.credits_used||0),icon:Layers},{title:'Delivery Rate',value:rate,icon:CheckCircle}];
 const exportMenu=(format:'csv'|'pdf')=><DropdownMenu><DropdownMenuTrigger asChild><Button variant="outline" disabled={exporting||loading}>Export {format.toUpperCase()}</Button></DropdownMenuTrigger><DropdownMenuContent align="end">{choices.filter(([key])=>key==='all'||counts[key]>0).map(([key,label])=><DropdownMenuItem key={key} onSelect={()=>void download(format,key)}>{label}</DropdownMenuItem>)}</DropdownMenuContent></DropdownMenu>;
 return <DashboardLayout title={campaign.name || 'Unnamed campaign'} subtitle={`${campaignReference(campaign)} · Created ${portalDate(campaign.created_at)}`} actions={<>
  {campaign.state==='draft'&&<><Button disabled={busy} onClick={()=>void action('queue')}>Queue campaign</Button><Button variant="destructive" disabled={busy} onClick={()=>void action('cancel')}>Cancel draft</Button></>}
  {counts.failed_to_send>0&&<Button disabled={busy} onClick={()=>void action('retry')}>Retry failed submissions</Button>}
  <Button variant="outline" onClick={()=>void load()} disabled={loading}>Refresh</Button>{exportMenu('pdf')}{exportMenu('csv')}
 </>}>
  <div className="flex flex-wrap items-center justify-between gap-3"><Link to="/sms-campaigns" className="inline-flex items-center gap-2 text-sm text-primary"><ArrowLeft size={16}/>Back to campaigns</Link><CampaignStatusBadge campaign={campaign}/></div>
  {error&&<p role="alert" className="mt-3 text-sm text-destructive">{error}</p>}
  <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5" aria-label="Campaign totals">{metrics.map(metric=><MetricCard key={metric.title} {...metric} footer={<p className="text-xs text-muted-foreground">{metric.title==='Delivery Rate'?'Network-confirmed / successfully sent':metric.title==='Successfully sent'?'Accepted for delivery':'Lifetime campaign total'}</p>}/>)}</div>
  <div className="portal-analytics-grid mt-4"><SendingOverview total={counts.all} sent={Number(campaign.sent_count||0)} excluded={counts.skipped} failed={counts.failed_to_send} held={Number(campaign.held_submission_count??0)} campaign/><DeliveryStats stats={{total_recipients:counts.all,total_sent:Number(campaign.sent_count),total_delivered:counts.delivered,total_failed:counts.failed,total_pending:counts.sent+counts.dlr_unavailable,total_awaiting_delivery:counts.sent,total_dlr_unavailable:counts.dlr_unavailable,total_skipped:counts.skipped,total_failed_submissions:counts.failed_to_send,delivery_rate:Number(campaign.sent_count)>0?Number((counts.delivered/Number(campaign.sent_count)*100).toFixed(1)):0}}/></div>
  <div className="portal-analytics-grid mt-4"><section className="metric-card"><div className="mb-3 flex items-center justify-between"><h2 className="panel-title">Message Content</h2><Button variant="ghost" size="icon" aria-label="Copy message" onClick={()=>void navigator.clipboard.writeText(campaign.content).then(()=>toast({title:'Message copied'})).catch(()=>toast({title:'Could not copy message',variant:'destructive'}))}><Copy size={16}/></Button></div><p className="whitespace-pre-wrap break-words rounded-lg border bg-muted/30 p-3 text-sm leading-relaxed">{campaign.content}</p><dl className="mt-4 grid grid-cols-2 gap-4 text-xs sm:grid-cols-3">{[['Segments',campaign.total_segments],['Estimated Cost',`R${Number(campaign.estimated_cost).toFixed(2)}`],['Actual Cost',`R${Number(campaign.actual_cost).toFixed(2)}`],['Created',portalDate(campaign.created_at)],['Started',portalDate(campaign.started_at)],['Completed',portalDate(campaign.completed_at)]].map(([label,value])=><div key={label}><dt className="text-muted-foreground">{label}</dt><dd className="mt-1 font-medium">{value}</dd></div>)}</dl></section>
   <section className="metric-card"><h2 className="panel-title mb-4">Campaign Timeline</h2><ol className="space-y-5">{[['Campaign created',campaign.created_at],['Scheduled to send',campaign.scheduled_at],['Sending started',campaign.started_at],['Sending completed',campaign.completed_at]].filter(([,date])=>Boolean(date)).map(([label,date])=><li key={label} className="flex items-start gap-3"><CheckCircle size={18} className="mt-0.5 shrink-0 text-primary"/><div><p className="text-sm font-semibold">{label}</p><p className="mt-1 text-xs text-muted-foreground">{portalDate(date)}</p></div></li>)}</ol><p className="mt-5 text-xs text-muted-foreground">Sending completion is separate from mobile-network delivery confirmation.</p></section></div>
  <div className="mt-6 flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-lg font-semibold">Recipient delivery details</h2><p className="text-sm text-muted-foreground">A sent message is not necessarily a confirmed delivery.</p></div>
  <input aria-label="Search recipients" placeholder="Search number or contact" className="h-10 max-w-full rounded-md border bg-background px-3 text-sm" value={search} onChange={e=>{setPage(1);setSearch(e.target.value);}}/>
  <Select value={state} onValueChange={v=>{setState(v);setPage(1);}}><SelectTrigger aria-label="Recipient status" className="w-60"><SelectValue/></SelectTrigger><SelectContent>{choices.map(([key,label])=><SelectItem key={key} value={key}>{key==='all'?'All statuses':label}</SelectItem>)}</SelectContent></Select>
  <Select value={segments} onValueChange={v=>{setSegments(v);setPage(1);}}><SelectTrigger aria-label="Recipient segments" className="w-40"><SelectValue/></SelectTrigger><SelectContent><SelectItem value="all">All segments</SelectItem><SelectItem value="1">1 segment</SelectItem><SelectItem value="multi">Multiple segments</SelectItem></SelectContent></Select><Button variant="outline" disabled={exporting||loading} onClick={()=>void download('csv',state,true)}>Export filtered CSV</Button></div>
  <div className="portal-table-scroll mt-3 max-h-[36rem] rounded-xl border bg-card" aria-busy={loading}><table className="portal-table min-w-[850px]"><thead><tr>{['Contact','Number','Status','Segments','Sent','Result'].map(h=><th scope="col" key={h}>{h}</th>)}</tr></thead><tbody>{rows.map(row=>{const presentation=recipientDelivery(row);return <tr key={row.id}><td className="font-medium">{row.contact}</td><td className="whitespace-nowrap tabular-nums">{row.number}</td><td><span className="status-badge gap-1.5 whitespace-nowrap" style={{color:presentation.color,background:'hsl(var(--muted))'}}><span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-current"/>{presentation.label}</span></td><td>{row.segments}</td><td>{row.sent}</td><td>{presentation.detail}</td></tr>;})}</tbody></table>
  {!loading&&!rows.length&&<div className="p-6 text-center"><p>No recipients in this view</p><Link to={`/sms-campaigns/${id}`} className="mt-2 inline-block text-sm text-primary hover:underline" onClick={()=>{setState('all');setSearch('');setPage(1);}}>Show all</Link></div>}</div>
  {rows.length>0&&<div className="mt-4 flex flex-wrap items-center justify-between gap-3"><p className="text-sm text-muted-foreground">Showing {rows.length} of {total}</p><div className="flex gap-2"><Button variant="outline" disabled={page<=1||loading} onClick={()=>setPage(p=>p-1)}>Previous</Button><Button variant="outline" disabled={page*50>=total||loading} onClick={()=>setPage(p=>p+1)}>Next</Button></div></div>}
 </DashboardLayout>;
}
