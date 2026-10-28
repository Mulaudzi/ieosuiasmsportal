import { campaignReference } from '@/lib/campaign-reference';
import { useState, useEffect, useCallback, useRef } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Plus, Search, MessageSquare, CalendarDays, RefreshCw, Send, Layers, MoreHorizontal } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { PageLoading } from '@/components/ui/page-loading';
import { MetricCard } from '@/components/dashboard/MetricCard';
import { MetricHint } from '@/components/dashboard/MetricHint';
import { CampaignStatusBadge } from '@/components/dashboard/CampaignStatusBadge';
import { CampaignDeliveryBar } from '@/components/dashboard/CampaignDeliveryBar';
import { DateRangeFilter } from '@/components/dashboard/DateRangeFilter';
import { SummaryCampaign } from '@/components/dashboard/CampaignSummary';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { getSmsCampaigns } from '@/lib/api';
import { portalDate } from '@/lib/portal-presentation';
import { sendingStatusHint } from '@/lib/campaign-status';
import { ReportingPeriod, validateCustomPeriod } from '@/lib/reporting-period';
import { useSmsRefresh } from '@/hooks/useSmsRefresh';
import { DraftList } from '@/components/drafts/DraftList';

const statuses=[['all','All statuses'],['active','Active'],['completed','Completed'],['queued','Queued'],['processing','Sending'],['scheduled','Scheduled'],['partially_failed','Submission issues'],['failed','Sending unsuccessful'],['cancelled','Cancelled'],['draft','Draft']];
interface Stats {total:number;sent:number;scheduled:number;credits_used:number}
export default function SmsCampaigns(){
 const [params,setParams]=useSearchParams();
 const search=params.get('search')??'';const status=statuses.some(([key])=>key===params.get('status'))?params.get('status')!:'all';
 const page=Math.max(1,Number(params.get('page'))||1);const sort=params.get('sort')==='oldest'?'oldest':'newest';
 const rawRange=params.get('range')??'all';
 const range=['all','24h','7d','30d','90d'].includes(rawRange)?rawRange:rawRange==='custom'&&!validateCustomPeriod(params.get('from')??'',params.get('to')??'')?'custom':'all';
 const from=range==='custom'?params.get('from')!:undefined,to=range==='custom'?params.get('to')!:undefined;
 const period:ReportingPeriod={range,from,to};
 const [campaigns,setCampaigns]=useState<SummaryCampaign[]>([]);const [stats,setStats]=useState<Stats>({total:0,sent:0,scheduled:0,credits_used:0});
 const [counts,setCounts]=useState<Record<string,number>>({});const [loading,setLoading]=useState(true);const [error,setError]=useState('');const sequence=useRef(0);
 const update=(values:Record<string,string|undefined>)=>setParams(previous=>{const next=new URLSearchParams(previous);next.delete('page');for(const [key,value] of Object.entries(values)){if(value&&value!=='all')next.set(key,value);else next.delete(key);}return next;});
 const load=useCallback(async(showLoading=true)=>{
  const request=++sequence.current;if(showLoading)setLoading(true);setError('');
  try{
   const response=await getSmsCampaigns({page,status:status==='all'?undefined:status,search:search||undefined,range,from,to,sort});
   if(request!==sequence.current)return;
   const payload=(response.data??response) as unknown as {campaigns:SummaryCampaign[];stats:Stats;status_counts?:Record<string,number>};
   if(!response.success||!Array.isArray(payload.campaigns))throw new Error('Campaigns unavailable');
   setCampaigns(payload.campaigns);setStats(payload.stats);setCounts(payload.status_counts??{});
  }catch{if(request===sequence.current)setError('Campaigns could not be loaded. Please refresh.');}
  finally{if(request===sequence.current)setLoading(false);}
 },[page,status,search,range,from,to,sort]);
 const invalidate=useCallback(()=>{sequence.current++;},[]);
 useEffect(()=>{void load();return invalidate;},[load,invalidate]);useSmsRefresh(()=>load(false));
 return <DashboardLayout title="SMS Campaigns" subtitle="Create and manage your SMS campaigns. Keep sending progress separate from delivery results." actions={<><Button aria-label="Refresh campaigns" variant="outline" size="icon" onClick={()=>void load()} disabled={loading}><RefreshCw size={16} className={loading?'animate-spin':''}/></Button><Button asChild className="gap-2"><Link to="/sms-campaigns/new"><Plus size={16}/>Send SMS</Link></Button></>}>
  <DraftList kind="sms"/>
  {error&&<p role="alert" className="mb-4 rounded-lg border border-destructive/30 p-3 text-sm text-destructive">{error}</p>}
  <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{[
   {title:'Total Campaigns',value:stats.total,icon:Send}, {title:'Total Sent',value:stats.sent,icon:MessageSquare},
   {title:'Scheduled',value:stats.scheduled,icon:CalendarDays}, {title:'SMS Credits Used',value:stats.credits_used,icon:Layers},
  ].map(item=><MetricCard key={item.title} {...item} value={loading||error?'—':Number(item.value??0).toLocaleString()} footer={<p className="text-xs text-muted-foreground">Across all matching campaigns</p>}/>)}</div>
  <div className="mb-4 flex flex-wrap gap-2" aria-label="Campaign status filters">{[['all','All'],['active','Active'],['scheduled','Scheduled'],['completed','Completed'],['draft','Drafts']].map(([key,label])=><Button key={key} variant={status===key?'default':'outline'} size="sm" aria-pressed={status===key} onClick={()=>update({status:key})}>{label}{counts[key]!==undefined?` (${counts[key]})`:''}</Button>)}</div>
  <div className="mb-4 flex flex-wrap items-center gap-3"><div className="relative min-w-48 flex-1"><Search className="absolute left-3 top-3 h-4 w-4 text-muted-foreground"/><Input aria-label="Search campaigns by name" placeholder="Search campaigns by name…" value={search} onChange={e=>update({search:e.target.value})} className="pl-10"/></div>
   <Select value={status} onValueChange={value=>update({status:value})}><SelectTrigger aria-label="Campaign status" className="w-44"><SelectValue/></SelectTrigger><SelectContent>{statuses.map(([key,label])=><SelectItem key={key} value={key}>{label}</SelectItem>)}</SelectContent></Select>
   <DateRangeFilter value={period} allowAll onChange={value=>update({range:value.range,from:value.from,to:value.to})}/>
   <Select value={sort} onValueChange={value=>update({sort:value})}><SelectTrigger aria-label="Campaign sort" className="w-40"><SelectValue/></SelectTrigger><SelectContent><SelectItem value="newest">Newest first</SelectItem><SelectItem value="oldest">Oldest first</SelectItem></SelectContent></Select>
  </div>
  <section className="overflow-hidden rounded-xl border bg-card" aria-label="Campaign list" aria-busy={loading}>
   {loading?<PageLoading variant="table" label="Loading campaigns" className="p-4"/>:error?<p className="py-12 text-center text-sm text-muted-foreground">Results unavailable. Refresh to try again.</p>:!campaigns.length?<div className="py-12 text-center"><MessageSquare className="mx-auto mb-3 text-muted-foreground"/><h2 className="font-semibold">No campaigns found</h2><p className="mt-1 text-sm text-muted-foreground">Try adjusting your filters or create a new campaign.</p></div>:<div className="portal-table-scroll"><table className="portal-table min-w-[1000px]"><thead><tr><th scope="col">Campaign</th><th scope="col">Sending status<MetricHint label="Sending status" text={sendingStatusHint}/></th>{['Recipients','Delivery breakdown','SMS Credits Used','Created','Actions'].map(label=><th scope="col" key={label}>{label}</th>)}</tr></thead><tbody>{campaigns.map(c=><tr key={c.id}>
    <td><div className="flex items-center gap-3"><MessageSquare className="shrink-0 rounded-lg bg-primary/10 p-2 text-primary" size={40}/><div><Link to={`/sms-campaigns/${c.id}`} className="font-semibold hover:text-primary">{c.name || 'Unnamed campaign'}</Link><p className="text-xs text-muted-foreground">{campaignReference(c)}</p><p className="mt-1 text-xs text-muted-foreground">{Number(c.sent_count).toLocaleString()} sent / {Number(c.recipient_count).toLocaleString()} recipients</p></div></div></td>
    <td><CampaignStatusBadge campaign={c}/></td><td className="font-semibold tabular-nums">{Number(c.recipient_count).toLocaleString()}</td>
    <td><CampaignDeliveryBar delivered={Number(c.delivered_count)} failed={Number(c.failed_count)} total={Number(c.recipient_count)} failedSubmissions={Number(c.failed_submission_count??0)} counts={[c.delivered_count,c.awaiting_delivery_count??0,c.dlr_unavailable_count??0,c.failed_count,c.skipped_count??0].map(Number)}/></td>
    <td className="font-semibold tabular-nums">{Number(c.credits_used??0).toLocaleString()}</td><td>{portalDate(c.created_at)}</td>
    <td><DropdownMenu><DropdownMenuTrigger asChild><Button variant="outline" size="icon" aria-label={`Actions for ${c.name || 'Unnamed campaign'}`}><MoreHorizontal size={18}/></Button></DropdownMenuTrigger><DropdownMenuContent align="end"><DropdownMenuItem asChild><Link to={`/sms-campaigns/${c.id}`}>View campaign</Link></DropdownMenuItem><DropdownMenuItem asChild><Link to={`/sms-campaigns/${c.id}?download=pdf`}>Export PDF</Link></DropdownMenuItem><DropdownMenuItem asChild><Link to={`/sms-campaigns/${c.id}?download=csv`}>Export CSV</Link></DropdownMenuItem></DropdownMenuContent></DropdownMenu></td>
   </tr>)}</tbody></table></div>}
  </section>
  <div className="mt-4 flex flex-wrap items-center justify-between gap-3"><p className="text-xs text-muted-foreground">{!loading&&!error&&`Showing ${campaigns.length} of ${stats.total} campaigns. Totals cover all matching campaigns.`}</p><div className="flex items-center gap-2"><Button variant="outline" size="sm" disabled={page<=1||loading} onClick={()=>setParams(previous=>{const next=new URLSearchParams(previous);next.set('page',String(page-1));return next;})}>Previous</Button><span className="px-2 text-sm" aria-current="page">{page}</span><Button variant="outline" size="sm" disabled={page*20>=stats.total||loading} onClick={()=>setParams(previous=>{const next=new URLSearchParams(previous);next.set('page',String(page+1));return next;})}>Next</Button></div></div>
 </DashboardLayout>;
}
