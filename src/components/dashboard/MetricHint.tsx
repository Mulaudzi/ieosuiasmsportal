import { useEffect, useRef, useState } from 'react';
import { Info } from 'lucide-react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

export const metricHints: Record<string,string> = {
 'Total Sent':'SMS activity for the current filters or reporting period. This total includes messages still waiting to send and unsuccessful attempts, but excludes skipped recipients. It is not a confirmed delivery count.',
 'Total Campaigns':'Number of campaigns matching the current filters.',
 'Scheduled':'Campaigns scheduled to be sent later.',
 'SMS Credits Used':'Credits charged for billable SMS segments. A long message can use more than one credit per recipient. Delivery confirmation is separate from charging.',
 'SMS Credits Used This Month':'Credits charged for billable SMS segments this month, not the number of confirmed deliveries.',
 'Available SMS Credits':'Credits available for sending. One credit pays for one SMS segment; longer messages may require multiple segments.',
 'Total Contacts':'Saved contacts in your account, not the number of SMS messages sent.',
 'Delivery Rate':'Network-confirmed deliveries divided by the message total for the selected period. Messages without confirmation are not confirmed deliveries.',
 'Delivered':'The network confirmed the SMS reached the recipient.',
 'Not Delivered':'The SMS could not be delivered. It may have been submitted but rejected or undeliverable, or sending may have failed. This does not automatically mean no sending attempt was made. Check the recipient details.',
 'Delivery Confirmation Pending':'The SMS was sent, but the network has not confirmed delivery yet.',
 'Delivery Status Unavailable':'The SMS was sent, but the network did not provide a delivery status. The recipient may have received it. This is not a confirmed failure.',
 'Delivery not yet confirmed':'Includes messages waiting to send and sent messages whose delivery is pending or unavailable. It does not mean all these messages failed.',
 'Skipped':'The SMS was not sent to this recipient, for example because they opted out.',
 'Average delivery time':'Average time between sending and network-confirmed delivery for messages with both timestamps. Messages without confirmation are excluded.',
 'Total':'Recipient message records in this campaign or the current filtered view, including skipped recipients.',
 'Pending dispatch':'Messages waiting for a sending attempt. These have not yet been submitted successfully.',
 'Processing':'Messages currently being handled by the sending worker.',
 'Segments':'SMS parts across recipients. Message length and character encoding determine how many parts are needed.',
};

export function MetricHint({label, text=metricHints[label]}:{label:string;text?:string}) {
 const [open,setOpen]=useState(false);const pinned=useRef(false);const timer=useRef<ReturnType<typeof setTimeout>>();
 const enter=()=>{clearTimeout(timer.current);setOpen(true);};
 const leave=()=>{if(!pinned.current)timer.current=setTimeout(()=>setOpen(false),180);};
 useEffect(()=>()=>clearTimeout(timer.current),[]);
 if(!text)return null;
 return <Popover open={open} onOpenChange={value=>{pinned.current=false;setOpen(value);}}><PopoverTrigger asChild><button type="button" aria-label={`About ${label}`} onPointerEnter={event=>{if(event.pointerType==='mouse')enter();}} onPointerLeave={leave} onClick={event=>{event.preventDefault();pinned.current=true;enter();}} className="ml-1 inline-flex shrink-0 rounded p-1 align-middle text-muted-foreground hover:text-foreground focus-visible:outline focus-visible:outline-primary"><Info aria-hidden="true" className="h-3.5 w-3.5"/></button></PopoverTrigger><PopoverContent onOpenAutoFocus={event=>event.preventDefault()} onPointerEnter={()=>clearTimeout(timer.current)} onPointerLeave={leave} className="max-w-[calc(100vw-2rem)] text-sm">{text}</PopoverContent></Popover>;
}
