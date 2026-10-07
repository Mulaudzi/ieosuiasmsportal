import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { CampaignDeliveryBar } from '@/components/dashboard/CampaignDeliveryBar';
import { campaignStatusConfig } from '@/lib/campaign-status';
import { sentenceCase } from '@/lib/campaign-comparison';
import { portalDate } from '@/lib/portal-presentation';
import { api, getSmsCampaign, retrySmsCampaign, queueSmsCampaign, cancelSmsCampaign } from '@/lib/api';
import { toast } from '@/hooks/use-toast';

interface Campaign { id:string;name:string;content:string;state:string;recipient_count:number;queued_count:number;processing_count:number;awaiting_delivery_count:number;dlr_unavailable_count:number;delivered_count:number;failed_count:number;skipped_count:number;total_segments:number;estimated_cost:number;actual_cost:number;created_at:string;scheduled_at?:string;started_at?:string;completed_at?:string }
interface Recipient {id:string;contact:string;number:string;status:string;segments:number;sent:string;result:string}
const choices=[['all','All recipients'],['delivered','Delivered'],['failed','Not Delivered'],['pending','Pending dispatch'],['processing','Processing'],['sent','Delivery Confirmation Pending']];
const colors:Record<string,string>={Delivered:'#2E9B5B','Not Delivered':'#C45C5C',Pending:'#2F6FED','Pending dispatch':'#2F6FED',Processing:'#2F6FED','Delivery Confirmation Pending':'#2F6FED','Delivery Status Unavailable':'#5E7394',Skipped:'#8B93A1'};

export default function CampaignDetails(){
 const {id=''}=useParams();const [urlParams]=useSearchParams();
 const [campaign,setCampaign]=useState<Campaign|null>(null);const [rows,setRows]=useState<Recipient[]>([]);const [total,setTotal]=useState(0);
 const [search,setSearch]=useState('');const [state,setState]=useState('all');const [page,setPage]=useState(1);const [loading,setLoading]=useState(true);const [error,setError]=useState('');
 const [busy,setBusy]=useState(false);const [exporting,setExporting]=useState(false);const sequence=useRef(0);const downloaded=useRef('');
 const load=useCallback(async()=>{
  const request=++sequence.current;setLoading(true);setError('');
  try{
   const [c,r]=await Promise.all([getSmsCampaign(id),api.get<{rows:Recipient[];total:number}>(`/sms/campaigns-v2/${id}/recipient-view`,{state,search:search.trim(),page:String(page)})]);
   if(request!==sequence.current)return;
   const cp=(c.data??c) as {campaign:Campaign};const rp=(r.data??r) as {rows:Recipient[];total:number};
   if(!c.success||!r.success||!cp.campaign||!Array.isArray(rp.rows))throw new Error('Invalid campaign data');
   setCampaign(cp.campaign);setRows(rp.rows);setTotal(rp.total);
  }catch{if(request===sequence.current)setError('Could not load this campaign. Please refresh.');}
  finally{if(request===sequence.current)setLoading(false);}
 },[id,state,search,page]);
 useEffect(()=>{setCampaign(null);setState('all');setSearch('');setPage(1);},[id]);
 useEffect(()=>{void load();const timer=setInterval(()=>void load(),30000);return()=>{sequence.current++;clearInterval(timer);};},[load]);
 const download=useCallback(async(format:'csv'|'pdf',filter:string)=>{
  setExporting(true);
  try{
   const base=import.meta.env.VITE_API_BASE_URL||'https://sms.ieosuia.com/api';
   const response=await fetch(`${base}/sms/campaigns-v2/${id}/recipient-export?format=${format}&state=${filter}`,{headers:{Authorization:`Bearer ${localStorage.getItem('auth_token')||''}`}});
   if(!response.ok){const error=await response.json().catch(()=>({}));throw new Error(error.error||error.message||'Export failed. Please try again.');}
   const url=URL.createObjectURL(await response.blob());const a=document.createElement('a');
   a.href=url;a.download=(response.headers.get('Content-Disposition')?.match(/filename="([^"\r\n]+)"/)?.[1]||`campaign-${filter}.${format}`).replace(/[\\/]/g,'-');
   document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),60000);
  }catch(e){toast({title:e instanceof Error?e.message:'Export failed',variant:'destructive'});}finally{setExporting(false);}
 },[id]);
 useEffect(()=>{const format=urlParams.get('download');const key=id+format;if(String(campaign?.id)===id&&(format==='pdf'||format==='csv')&&downloaded.current!==key){downloaded.current=key;void download(format,'all');}},[campaign,id,urlParams,download]);
 const action=async(kind:'retry'|'queue'|'cancel')=>{setBusy(true);try{if(kind==='retry')await retrySmsCampaign(id);else if(kind==='queue')await queueSmsCampaign(id);else await cancelSmsCampaign(id);await load();}catch(e){toast({title:'Campaign could not be updated',description:e instanceof Error?e.message:'Please retry.',variant:'destructive'});}finally{setBusy(false);}};
 if(!campaign)return <DashboardLayout title="Campaign" subtitle={loading?'Loading…':error}><Link to="/sms-campaigns">Back to campaigns</Link>{error&&<Button onClick={()=>void load()}>Refresh</Button>}</DashboardLayout>;
 const counts:Record<string,number>={all:Number(campaign.recipient_count),delivered:Number(campaign.delivered_count),failed:Number(campaign.failed_count),pending:Number(campaign.queued_count),processing:Number(campaign.processing_count),sent:Number(campaign.awaiting_delivery_count)};
 const pending=counts.pending+counts.processing+counts.sent;
 const exportMenu=(format:'csv'|'pdf')=><DropdownMenu><DropdownMenuTrigger asChild><Button variant="outline" disabled={exporting||loading}>Export {format.toUpperCase()}</Button></DropdownMenuTrigger><DropdownMenuContent align="end">{choices.filter(([key])=>key==='all'||counts[key]>0).map(([key,label])=><DropdownMenuItem key={key} onSelect={()=>void download(format,key)}>{label}</DropdownMenuItem>)}</DropdownMenuContent></DropdownMenu>;
 return <DashboardLayout title={sentenceCase(campaign.name)} subtitle={campaignStatusConfig[campaign.state]?.label??campaign.state} actions={<>
  {campaign.state==='draft'&&<><Button disabled={busy} onClick={()=>void action('queue')}>Queue campaign</Button><Button variant="destructive" disabled={busy} onClick={()=>void action('cancel')}>Cancel draft</Button></>}
  {counts.failed>0&&<Button disabled={busy} onClick={()=>void action('retry')}>Retry undelivered messages</Button>}
  <Button variant="outline" onClick={()=>void load()} disabled={loading}>Refresh</Button>{exportMenu('pdf')}{exportMenu('csv')}
 </>}>
  <Link to="/sms-campaigns" className="text-sm text-primary">Back to campaigns</Link>
  {error&&<p role="alert" className="mt-3 text-sm text-destructive">{error}</p>}
  <div className="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4" aria-label="Campaign totals">{[['Recipients',counts.all],['Delivered',counts.delivered],['Not Delivered',counts.failed],['Pending',pending]].map(([label,value])=><div key={label} className="rounded-xl border bg-card p-4"><p className="text-sm text-muted-foreground">{label}</p><p className="text-2xl font-bold" style={{color:colors[String(label)]}}>{Number(value).toLocaleString()}</p></div>)}</div>
  {campaign.dlr_unavailable_count>0&&<p className="mt-3 text-sm text-muted-foreground">Delivery Status Unavailable: {Number(campaign.dlr_unavailable_count).toLocaleString()}</p>}
  {campaign.skipped_count>0&&<p className="mt-2 text-sm text-muted-foreground">Skipped: {Number(campaign.skipped_count).toLocaleString()}</p>}
  {counts.delivered+counts.failed+pending>0&&<section className="mt-4 rounded-xl border bg-card p-4" aria-label="Delivery breakdown"><CampaignDeliveryBar delivered={counts.delivered} failed={counts.failed} pending={pending} detail/></section>}
  <div className="mt-4 rounded-xl border bg-card p-5 pb-8 sm:pb-5"><p className="whitespace-pre-wrap break-words">{campaign.content}</p><p className="mt-4 text-sm">{campaign.total_segments} segments · Estimated cost: R{Number(campaign.estimated_cost).toFixed(2)} · Actual cost: R{Number(campaign.actual_cost).toFixed(2)}</p>
  <div className="mt-4 grid gap-2 text-sm sm:grid-cols-2"><p>Created: {portalDate(campaign.created_at)}</p><p>Scheduled: {portalDate(campaign.scheduled_at)}</p><p>Started: {portalDate(campaign.started_at)}</p><p>Sending completed: {portalDate(campaign.completed_at)}</p></div></div>
  <div className="mt-6 flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-lg font-semibold">Recipient delivery details</h2><p className="text-sm text-muted-foreground">A sent message is not necessarily a confirmed delivery.</p></div>
  <input aria-label="Search recipients" placeholder="Search number or contact" className="h-10 max-w-full rounded-md border bg-background px-3 text-sm" value={search} onChange={e=>{setPage(1);setSearch(e.target.value);}}/>
  <Select value={state} onValueChange={v=>{setState(v);setPage(1);}}><SelectTrigger aria-label="Recipient status" className="w-60"><SelectValue/></SelectTrigger><SelectContent>{choices.map(([key,label])=><SelectItem key={key} value={key}>{key==='all'?'All':label}</SelectItem>)}</SelectContent></Select></div>
  <div className="mt-3 overflow-x-auto rounded-xl border bg-card" aria-busy={loading}><table className="w-full min-w-[850px] text-sm"><thead className="border-b bg-muted/50"><tr>{['Contact','Number','Status','Segments','Sent','Result'].map(h=><th key={h} className="p-3 text-left">{h}</th>)}</tr></thead><tbody>{rows.map(row=><tr key={row.id} className="border-b"><td className="p-3 font-medium">{row.contact}</td><td className="p-3">{row.number}</td><td className="p-3" style={{color:colors[row.status]}}>{row.status}</td><td className="p-3">{row.segments}</td><td className="p-3">{row.sent}</td><td className="p-3">{row.result}</td></tr>)}</tbody></table>
  {!loading&&!rows.length&&<div className="p-6 text-center"><p>No recipients in this view</p><Link to={`/sms-campaigns/${id}`} className="mt-2 inline-block text-sm text-primary hover:underline" onClick={()=>{setState('all');setSearch('');setPage(1);}}>Show all</Link></div>}</div>
  {rows.length>0&&<div className="mt-4 flex flex-wrap items-center justify-between gap-3"><p className="text-sm text-muted-foreground">Showing {rows.length} of {total}</p><div className="flex gap-2"><Button variant="outline" disabled={page<=1||loading} onClick={()=>setPage(p=>p-1)}>Previous</Button><Button variant="outline" disabled={page*50>=total||loading} onClick={()=>setPage(p=>p+1)}>Next</Button></div></div>}
 </DashboardLayout>;
}
