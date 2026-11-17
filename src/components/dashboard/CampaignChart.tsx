import { useState, useEffect, useCallback, useRef } from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { api } from '@/lib/api';
import { useSmsRefresh } from '@/hooks/useSmsRefresh';
import { defaultDashboardPeriod, periodLabel, ReportingPeriod } from '@/lib/reporting-period';
import { Button } from '@/components/ui/button';

interface Point {date:string;sms:number}
interface ChartResponse {chart:{date:string;sent:number}[];reporting_start?:string;reporting_end?:string}
export function CampaignChart({period=defaultDashboardPeriod}:{period?:ReportingPeriod}) {
 const [data,setData]=useState<Point[]>([]);const [loading,setLoading]=useState(true);const [error,setError]=useState('');const sequence=useRef(0);
 const load=useCallback(async(showLoading=true)=>{
  const request=++sequence.current;if(showLoading){setLoading(true);setData([]);}setError('');
  try{
   const response=await api.get<ChartResponse>('/dashboard/chart',{...period});
   if(request!==sequence.current)return;
   const payload=(response.data??response) as ChartResponse;
   if(!response.success||!Array.isArray(payload.chart))throw new Error('Invalid chart');
   const points=payload.chart.map(p=>({date:p.date,sms:Number(p.sent)||0}));
   if(payload.reporting_start&&payload.reporting_end){
    const values=new Map(points.map(p=>[p.date,p.sms]));const complete:Point[]=[];
    const start=new Date(payload.reporting_start.slice(0,10)+'T00:00:00Z'),end=payload.reporting_end.slice(0,10);
    for(let i=0;i<367&&start.toISOString().slice(0,10)<=end;i++,start.setUTCDate(start.getUTCDate()+1)){
     const date=start.toISOString().slice(0,10);complete.push({date,sms:values.get(date)??0});
    }
    setData(complete);
   }else setData(points);
  }catch{if(request===sequence.current)setError('Message volume could not be loaded.');}
  finally{if(request===sequence.current)setLoading(false);}
 },[period]);
 const invalidate=useCallback(()=>{sequence.current++;},[]);
 useEffect(()=>{void load();return invalidate;},[load,invalidate]);useSmsRefresh(()=>load(false));
 const dateLabel=(date:string)=>new Date(date+'T00:00:00Z').toLocaleDateString('en-ZA',{month:'short',day:'numeric',timeZone:'UTC'});
 return <section className="metric-card min-w-0" aria-label="Message Volume"><div className="mb-4 flex flex-wrap items-start justify-between gap-2"><div><h2 className="panel-title">Message Volume</h2><p className="text-sm text-muted-foreground">SMS activity · {periodLabel(period)}</p></div><span className="flex items-center gap-2 text-xs text-muted-foreground"><span className="h-2 w-2 rounded-full bg-primary"/>SMS</span></div>
  {error?<div role="alert" className="py-10 text-center text-sm"><p>{error}</p><Button variant="outline" size="sm" onClick={()=>void load()}>Retry chart</Button></div>:loading?<p role="status" className="py-20 text-center text-sm text-muted-foreground">Loading message volume…</p>:!data.some(p=>p.sms>0)?<p className="py-20 text-center text-sm text-muted-foreground">No messages sent in this period.</p>:<div className="h-56 w-full" role="img" aria-label={`${data.reduce((total,p)=>total+p.sms,0).toLocaleString()} SMS sent in the selected period`}><ResponsiveContainer width="100%" height="100%"><BarChart data={data} margin={{top:8,right:8,left:-14,bottom:0}}><CartesianGrid vertical={false} stroke="hsl(var(--border))" strokeDasharray="3 3"/><XAxis dataKey="date" tickFormatter={dateLabel} minTickGap={32} fontSize={11} tickLine={false} axisLine={false}/><YAxis allowDecimals={false} fontSize={11} tickLine={false} axisLine={false}/><Tooltip labelFormatter={dateLabel} cursor={{fill:'hsl(var(--muted))'}}/><Bar dataKey="sms" name="SMS sent" fill="hsl(var(--primary))" isAnimationActive={false} maxBarSize={24} radius={[3,3,0,0]}/></BarChart></ResponsiveContainer></div>}
 </section>;
}
