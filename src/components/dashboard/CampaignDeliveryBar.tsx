import { useState } from 'react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { DeliveryBreakdown } from './DeliveryBreakdown';

export function CampaignDeliveryBar({delivered,failed,pending=0,detail=false,counts,total}:{delivered:number;failed:number;pending?:number;detail?:boolean;counts?:number[];total?:number}) {
  const [open,setOpen]=useState(false);
  const items=[{label:'Delivered',count:delivered,color:'var(--delivery-delivered)'},{label:'Not delivered',count:failed,color:'var(--delivery-failed)'},...(detail?[{label:'Pending',count:pending,color:'var(--delivery-pending)'}]:[])];
  const barTotal=items.reduce((sum,item)=>sum+item.count,0);
  if(counts)return <div><DeliveryBreakdown counts={counts} total={total??counts.reduce((a,b)=>a+b,0)}/><div className="flex flex-wrap gap-3 text-xs text-muted-foreground">{items.map(item=><span key={item.label} className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-full" style={{background:item.color}}/>{item.label}</span>)}</div></div>;
  if(!barTotal)return null;
  return <div className="max-w-full" onMouseEnter={()=>setOpen(true)} onMouseLeave={()=>setOpen(false)}>
    <Popover open={open} onOpenChange={setOpen}><PopoverTrigger asChild><button type="button" aria-label="Delivery breakdown" className="block h-6 w-48 max-w-full rounded focus-visible:ring-2 focus-visible:ring-ring"><span className="flex h-3 overflow-hidden rounded-full bg-muted">{items.map(item=>item.count>0&&<span key={item.label} style={{width:`${item.count/barTotal*100}%`,background:item.color}}/>)}</span></button></PopoverTrigger><PopoverContent onOpenAutoFocus={e=>e.preventDefault()} className="w-64" onMouseEnter={()=>setOpen(true)}>{items.map(item=><p key={item.label} className="flex justify-between py-1 text-sm"><span>{item.label}</span><span>{item.count.toLocaleString()}</span></p>)}</PopoverContent></Popover>
    <div className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">{items.map(item=><span key={item.label} className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-full" style={{background:item.color}}/>{item.label}</span>)}</div>
  </div>;
}
