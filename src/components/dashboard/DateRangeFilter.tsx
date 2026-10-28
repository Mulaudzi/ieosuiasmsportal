import { useId, useState } from 'react';
import { CalendarDays } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogTitle, DialogDescription, DialogTrigger } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { periodLabel, ReportingPeriod, validateCustomPeriod } from '@/lib/reporting-period';

export function DateRangeFilter({value,onChange,allowAll=false}:{value:ReportingPeriod;onChange:(period:ReportingPeriod)=>void;allowAll?:boolean}) {
  const id=useId();
  const [open,setOpen]=useState(false);
  const [from,setFrom]=useState(value.from??'');const [to,setTo]=useState(value.to??'');
  const [error,setError]=useState('');
  return <div className="flex max-w-full flex-wrap items-center gap-2">
    <Select value={value.range} onValueChange={range=>{if(range==='custom'){setError('');setOpen(true);}else onChange({range});}}>
      <SelectTrigger aria-label="Date range" className="w-44"><CalendarDays className="mr-2 h-4 w-4 shrink-0"/><SelectValue/></SelectTrigger>
      <SelectContent>{allowAll&&<SelectItem value="all">All dates</SelectItem>}<SelectItem value="7d">Last 7 days</SelectItem><SelectItem value="30d">Last 30 days</SelectItem><SelectItem value="custom">Custom dates</SelectItem><SelectItem value="24h">Last 24 hours</SelectItem><SelectItem value="90d">Last 90 days</SelectItem></SelectContent>
    </Select>
    <Dialog open={open} onOpenChange={setOpen}><DialogTrigger asChild><Button variant="outline" aria-label="Edit custom dates" className="max-w-full text-xs" onClick={()=>setOpen(true)}>{value.range==='custom'?periodLabel(value):<CalendarDays size={16}/>}</Button></DialogTrigger>
      <DialogContent className="max-w-[min(24rem,calc(100vw-2rem))]">
        <form onSubmit={event=>{event.preventDefault();const message=validateCustomPeriod(from,to);setError(message??'');if(!message){onChange({range:'custom',from,to});setOpen(false);}}} className="space-y-3">
          <DialogTitle>Custom date range</DialogTitle><DialogDescription>Both dates are included, in South African time. Up to 366 days.</DialogDescription>
          <div><label htmlFor={`${id}-from`} className="text-sm">Start date</label><Input id={`${id}-from`} type="date" value={from} onChange={e=>setFrom(e.target.value)} required/></div>
          <div><label htmlFor={`${id}-to`} className="text-sm">End date</label><Input id={`${id}-to`} type="date" value={to} onChange={e=>setTo(e.target.value)} required/></div>
          {error&&<p role="alert" className="text-sm text-destructive">{error}</p>}
          <div className="flex justify-end gap-2"><Button type="button" variant="ghost" onClick={()=>setOpen(false)}>Cancel</Button><Button type="submit">Apply dates</Button></div>
        </form>
      </DialogContent>
    </Dialog>
  </div>;
}
