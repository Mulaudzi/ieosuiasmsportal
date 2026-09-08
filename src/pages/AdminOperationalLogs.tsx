import { useCallback, useEffect, useState } from "react";
import { AdminLayout } from "@/components/layout/AdminLayout";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { RefreshCw } from "lucide-react";

type Log={id:number;level:string;service:string;event:string;message:string;campaign_id?:number;sms_message_id?:number;created_at:string;context?:Record<string,unknown>};
type Run={id:number;service_name:string;status:string;processed_count:number;failed_count:number;message?:string;started_at:string;duration_ms?:number};
type Payload={logs:Log[];runs:Run[];pagination:{page:number;last_page:number;total:number}};

export default function AdminOperationalLogs(){
  const [data,setData]=useState<Payload>({logs:[],runs:[],pagination:{page:1,last_page:1,total:0}});const [level,setLevel]=useState('all');const [service,setService]=useState('all');const [search,setSearch]=useState('');const [loading,setLoading]=useState(true);
  const load=useCallback(async()=>{setLoading(true);try{const query=new URLSearchParams({per_page:'100'});if(level!=='all')query.set('level',level);if(service!=='all')query.set('service',service);if(search)query.set('search',search);const response=await api.get<Payload>(`/admin/operations/logs?${query}`);const payload=response.data??response as unknown as Payload;setData(payload);}finally{setLoading(false)}},[level,service,search]);
  useEffect(()=>{void load()},[load]);
  return <AdminLayout title="Operational Logs" subtitle="Redacted SMS, scheduler, DLR and background-job diagnostics" actions={<Button variant="outline" onClick={()=>void load()} disabled={loading}><RefreshCw className={`mr-2 h-4 w-4 ${loading?'animate-spin':''}`}/>Refresh</Button>}>
    <div className="grid gap-3 md:grid-cols-3"><Input placeholder="Search events and errors" value={search} onChange={e=>setSearch(e.target.value)}/><Select value={level} onValueChange={setLevel}><SelectTrigger><SelectValue/></SelectTrigger><SelectContent>{['all','info','warning','error','critical'].map(v=><SelectItem key={v} value={v}>{v}</SelectItem>)}</SelectContent></Select><Select value={service} onValueChange={setService}><SelectTrigger><SelectValue/></SelectTrigger><SelectContent>{['all','sms-worker','sms-scheduler','sms-dlr-poller','logicsms-webhook'].map(v=><SelectItem key={v} value={v}>{v}</SelectItem>)}</SelectContent></Select></div>
    <section className="mt-6"><h2 className="mb-3 text-lg font-semibold">Recent job runs</h2><div className="overflow-x-auto rounded-xl border"><table className="w-full text-sm"><thead><tr className="bg-muted/50"><th className="p-3 text-left">Started</th><th className="p-3 text-left">Service</th><th className="p-3">Status</th><th className="p-3">Processed</th><th className="p-3">Failed</th><th className="p-3 text-left">Result</th></tr></thead><tbody>{data.runs.map(run=><tr key={run.id} className="border-t"><td className="p-3 whitespace-nowrap">{run.started_at}</td><td className="p-3">{run.service_name}</td><td className="p-3 text-center"><Badge variant={run.status==='failed'?'destructive':'secondary'}>{run.status}</Badge></td><td className="p-3 text-center">{run.processed_count}</td><td className="p-3 text-center">{run.failed_count}</td><td className="p-3">{run.message||'—'}</td></tr>)}</tbody></table></div></section>
    <section className="mt-6"><h2 className="mb-3 text-lg font-semibold">Events ({data.pagination.total})</h2><div className="space-y-3">{data.logs.map(log=><article key={log.id} className="rounded-xl border bg-card p-4"><div className="flex flex-wrap items-center gap-2"><Badge variant={['error','critical'].includes(log.level)?'destructive':'secondary'}>{log.level}</Badge><span className="font-medium">{log.service} · {log.event}</span><span className="ml-auto text-xs text-muted-foreground">{log.created_at}</span></div><p className="mt-2 break-words text-sm">{log.message}</p><p className="mt-2 text-xs text-muted-foreground">Campaign {log.campaign_id??'—'} · Message {log.sms_message_id??'—'}</p></article>)}{!loading&&!data.logs.length&&<p className="rounded-xl border p-8 text-center text-muted-foreground">No matching operational events.</p>}</div></section>
  </AdminLayout>;
}
