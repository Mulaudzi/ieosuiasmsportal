import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { format } from 'date-fns';
import { api } from '@/lib/api';
import { useAuth } from '@/hooks/useAuth';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
interface Activity {id:string;created_at:string;type:'Purchase'|'Credit adjustment'|'SMS usage';reference:string;detail:string;amount:number;credits:number;status:string}
export interface WalletActivityData {transactions:Activity[];total:number;page:number;last_page:number;total_paid:number}
export function WalletActivity({full=false,onTotalPaid}:{full?:boolean;onTotalPaid?:(amount:number)=>void}){
 const {user}=useAuth();const [params,setParams]=useSearchParams();const [page,setPage]=useState(1);
 const status=params.get('status')||'all',period=params.get('period')||'month',search=params.get('search')||'',start=params.get('start')||'',end=params.get('end')||'';
 const valid=period!=='custom'||Boolean(start&&end&&start<=end);
 const query={status,period,search,start,end,page:String(page),per_page:full?'20':'5'};
 const {data,isFetching,error,refetch}=useQuery({queryKey:['wallet-activity',user?.id,query],enabled:!!user&&valid,queryFn:async()=>{const r=await api.get<WalletActivityData>('/wallet/activity',query);if(!r.success)throw new Error('Transactions unavailable');return (r.data??r) as WalletActivityData;},refetchInterval:30000});
 useEffect(()=>{if(data)onTotalPaid?.(data.total_paid);},[data,onTotalPaid]);
 const change=(key:string,value:string)=>{setPage(1);setParams(previous=>{const next=new URLSearchParams(previous);next.set(key,value);return next;},{replace:true});};
 const history=new URLSearchParams(params);history.set('view','activity');history.set('status',status);history.set('period',period);
 return <section aria-label="Transactions" className="mt-6 space-y-3"><div className="flex items-center justify-between gap-3"><h2 className="text-lg font-semibold">Transactions</h2>{!full&&<Link className="text-sm text-primary hover:underline" to={'/wallet/payments?'+history.toString()}>View all</Link>}</div>
  <div className="flex flex-wrap gap-3"><Input aria-label="Search transactions" placeholder="Search by reference or amount" value={search} onChange={e=>change('search',e.target.value)} className="min-w-0 flex-1 basis-60"/>
   <Select value={status} onValueChange={v=>change('status',v)}><SelectTrigger aria-label="Payment status" className="w-36"><SelectValue/></SelectTrigger><SelectContent>{[['all','All'],['paid','Paid'],['pending','Pending'],['failed','Failed'],['refunded','Refunded']].map(([v,l])=><SelectItem key={v} value={v}>{l}</SelectItem>)}</SelectContent></Select>
   <Select value={period} onValueChange={v=>change('period',v)}><SelectTrigger aria-label="Transaction dates" className="w-40"><SelectValue/></SelectTrigger><SelectContent><SelectItem value="month">This month</SelectItem><SelectItem value="30d">Last 30 days</SelectItem><SelectItem value="custom">Custom</SelectItem></SelectContent></Select>
   {period==='custom'&&<><label className="text-sm">From<Input aria-label="Start date" type="date" value={start} onChange={e=>change('start',e.target.value)}/></label><label className="text-sm">To<Input aria-label="End date" type="date" value={end} min={start} onChange={e=>change('end',e.target.value)}/></label></>}
  </div>
  {!valid&&<p className="text-sm text-muted-foreground">Choose a valid start and end date.</p>}
  {error&&<p role="alert" className="text-sm text-destructive">Could not load transactions. <button onClick={()=>void refetch()} className="underline">Retry</button></p>}
  <div className="overflow-x-auto rounded-xl border bg-card" aria-busy={isFetching}><table className="w-full min-w-[640px] text-sm"><thead className="border-b bg-muted/50"><tr>{['Date','Type','Detail','Amount','Status'].map(h=><th className="p-3 text-left" key={h}>{h}</th>)}</tr></thead><tbody>{valid&&data?.transactions.map(row=><tr key={row.id} className="border-b"><td className="whitespace-nowrap p-3">{format(new Date(row.created_at),'d MMM yyyy')}</td><td className="p-3">{row.type}</td><td className="p-3">{row.detail}<span className="block text-xs text-muted-foreground">{Math.abs(row.credits).toLocaleString()} credits {row.type==='SMS usage'||row.credits<0?'deducted':row.type==='Purchase'&&row.status!=='paid'?(row.status==='pending'?'pending':'not added'):'added'}</span></td><td className="whitespace-nowrap p-3">{row.type==='SMS usage'||row.amount<0?'-':''}R{Math.abs(row.amount).toFixed(2)}</td><td className="p-3">{({paid:'Paid',pending:'Pending',failed:'Failed',refunded:'Refunded',completed:'Completed'} as Record<string,string>)[row.status]||row.status}</td></tr>)}</tbody></table>
   {valid&&!isFetching&&!error&&!data?.transactions.length&&<p className="p-4 text-sm text-muted-foreground">No transactions match these filters.</p>}
  </div>
  {full&&data&&data.last_page>1&&<div className="flex items-center justify-between gap-3 text-sm"><span>Page {page} of {data.last_page}</span><div className="flex gap-2"><Button variant="outline" disabled={page<=1||isFetching} onClick={()=>setPage(p=>p-1)}>Previous</Button><Button variant="outline" disabled={page>=data.last_page||isFetching} onClick={()=>setPage(p=>p+1)}>Next</Button></div></div>}
 </section>;
}
