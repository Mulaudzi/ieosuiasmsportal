import { useEffect, useRef, useState } from 'react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { DeliveryOutcomeList } from './DeliveryOutcomeList';
import { deliveryOutcomes } from '@/lib/delivery-presentation';

export function DeliveryBreakdown({counts,total}:{counts:number[];total:number}) {
  const [open,setOpen]=useState(false);
  const timer=useRef<ReturnType<typeof setTimeout>>();
  const pinned=useRef(false);
  const keepOpen=()=>{clearTimeout(timer.current);setOpen(true);};
  const leave=()=>{if(!pinned.current)timer.current=setTimeout(()=>setOpen(false),180);};
  useEffect(()=>()=>clearTimeout(timer.current),[]);
  return <Popover open={open} onOpenChange={value=>{pinned.current=false;setOpen(value);}}><PopoverTrigger asChild>
    <button type="button" aria-label="Delivery Breakdown" aria-expanded={open} onPointerEnter={event=>{if(event.pointerType==='mouse')keepOpen();}} onPointerLeave={leave} onClick={event=>{event.preventDefault();pinned.current=true;keepOpen();}} className="w-48 max-w-full rounded-md p-2 text-left hover:bg-muted focus-visible:outline focus-visible:outline-primary">
      <span className="flex items-center gap-3"><span aria-hidden="true" className="flex h-3 min-w-0 flex-1 overflow-hidden rounded-full bg-muted">{deliveryOutcomes.map((o,i)=>counts[i]>0&&<span key={o.key} style={{width:`${100*counts[i]/Math.max(total,1)}%`,background:`var(--delivery-${o.key})`}}/>)}</span><span className="shrink-0 text-sm tabular-nums">{total.toLocaleString()}</span></span>
    </button>
  </PopoverTrigger><PopoverContent onOpenAutoFocus={event=>event.preventDefault()} onPointerEnter={()=>clearTimeout(timer.current)} onPointerLeave={leave} className="w-80 max-w-[calc(100vw-2rem)]" align="start"><h3 className="mb-3 font-semibold">Delivery Breakdown</h3><DeliveryOutcomeList counts={counts} divided/><div className="mt-2 flex justify-between border-t pt-3 text-sm font-semibold"><span>Total</span><span>{total.toLocaleString()}</span></div></PopoverContent></Popover>;
}
