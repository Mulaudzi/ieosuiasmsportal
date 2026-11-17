import { useEffect, useRef, useState } from 'react';
import { Info } from 'lucide-react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

export const metricHints: Record<string,string> = {
 'Active Campaigns':'Campaigns currently scheduled, queued or processing across your account. This is a current total, not a historical period total.',
 'Successfully sent':'Messages accepted by the provider for delivery. This does not mean network delivery has been confirmed.',
 'Waiting to send':'Recipients still queued or being processed; successful provider acceptance has not yet been confirmed.',
 'Total Sent':'Messages successfully accepted by the SMS provider for delivery. Waiting messages, excluded recipients and failed submissions are not included. This is separate from confirmed delivery.',
 'Total Campaigns':'Number of campaigns matching the current filters.',
 'Scheduled':'Campaigns scheduled to be sent later.',
 'SMS Credits Used':'Credits charged for billable SMS segments. A long message can use more than one credit per recipient. Delivery confirmation is separate from charging.',
 'SMS Credits Used This Month':'Credits charged for billable SMS segments this month, not the number of confirmed deliveries.',
 'Available SMS Credits':'Credits available for sending. One credit pays for one SMS segment; longer messages may require multiple segments.',
 'Total Contacts':'Saved contacts in your account, not the number of SMS messages sent.',
 'Delivery Rate':'Network-confirmed deliveries divided by the message total for the selected period. Messages without confirmation are not confirmed deliveries.',
 'Delivery Results':'Latest available provider and network reports. There is no mandatory waiting period. Pending confirmations are not failures.',
 'Delivered':'The network confirmed the SMS reached the recipient.',
 'Not Delivered':'The messaging provider or mobile network reported an unsuccessful outcome. This can include a provider block and is different from a failed submission.',
 'Sent - Awaiting Confirmation':'Your SMS has been successfully sent for delivery. We are awaiting confirmation from the mobile network.',
 'Sent - Confirmation Unavailable':'Your SMS was successfully sent for delivery, but a final delivery confirmation is unavailable. This is not a confirmed failure.',
 'Delivery not yet confirmed':'Successfully sent messages whose delivery confirmation is still pending or unavailable. Messages waiting to be sent are shown separately.',
 'Not Sent':'This SMS was excluded before sending, for example because the recipient opted out.',
 'Failed submissions':'The provider did not accept these messages for delivery. They are not network delivery failures.',
 'Average delivery time':'Average elapsed time from submission until the portal recorded confirmation. This is not verified handset delivery time. Messages without confirmation are excluded.',
 'Typical confirmation time':'Time from submission until the portal recorded confirmation for at least 75% of the sampled confirmed messages. This is not verified handset delivery time.',
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
