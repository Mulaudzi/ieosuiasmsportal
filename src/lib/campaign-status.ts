/** Campaign dispatch progress, deliberately separate from recipient delivery outcomes. */
export const campaignStatusConfig: Record<string,{label:string;class:string}> = {
 draft:{label:'Draft',class:'bg-muted text-muted-foreground'},
 scheduled:{label:'Scheduled',class:'status-queued'},
 queued:{label:'Scheduled',class:'status-queued'},
 pending:{label:'Waiting to send',class:'status-pending'},
 sending:{label:'Sending',class:'status-pending'},
 processing:{label:'Processing',class:'status-pending'},
 completed:{label:'Sending complete',class:'status-delivered'},
 partially_failed:{label:'Partially delivered',class:'status-failed'},
 sent:{label:'Sending complete',class:'status-delivered'},
 delivered:{label:'Delivered',class:'status-delivered'},
 failed:{label:'Not Delivered',class:'status-failed'},
 cancelled:{label:'Cancelled',class:'bg-muted text-muted-foreground'},
};
export const sendingStatusHint='This shows campaign sending progress, not delivery success. Sending complete means all recipients have been processed, including unsuccessful or skipped messages. Use Delivery Breakdown to see who received the SMS.';
