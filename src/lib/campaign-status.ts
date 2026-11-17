/** Campaign dispatch progress, deliberately separate from recipient delivery outcomes. */
export const campaignStatusConfig: Record<string,{label:string;class:string}> = {
 draft:{label:'Draft',class:'bg-muted text-muted-foreground'},
 scheduled:{label:'Scheduled',class:'status-queued'},
 queued:{label:'Waiting to send',class:'status-queued'},
 pending:{label:'Waiting to send',class:'status-pending'},
 sending:{label:'Sending',class:'status-pending'},
 processing:{label:'Processing',class:'status-pending'},
 completed:{label:'Sending complete',class:'status-delivered'},
 partially_failed:{label:'Sending finished',class:'bg-muted text-muted-foreground'},
 sent:{label:'Sending complete',class:'status-delivered'},
 delivered:{label:'Sending complete',class:'status-delivered'},
 failed:{label:'Sending unsuccessful',class:'status-failed'},
 cancelled:{label:'Cancelled',class:'bg-muted text-muted-foreground'},
};
/** Legacy lifecycle fields can include subsequent network failures. Only explicit
 * submission counters may describe unsuccessful submissions; never failed_count. */
export function campaignStatus(campaign:{state?:string;status?:string;recipient_count?:number;sent_count?:number;skipped_count?:number;failed_submission_count?:number;held_submission_count?:number;processing_count?:number;queued_count?:number}) {
 const state=(campaign.state??campaign.status??'unknown').toLowerCase();
 const held=Number(campaign.held_submission_count??0);
 if(held>0 && Number(campaign.processing_count??0)===0 && Number(campaign.queued_count??held)<=held)return {label:'Awaiting provider confirmation',class:'status-pending'};
 const terminal=['completed','partially_failed','sent','delivered','failed'].includes(state);
 if(terminal&&campaign.failed_submission_count!==undefined){
  if(Number(campaign.failed_submission_count)>0)return {label:'Submission issues',class:'status-failed'};
  const total=Number(campaign.recipient_count),sent=Number(campaign.sent_count),excluded=Number(campaign.skipped_count??0);
  if(total>0&&sent+excluded===total)return {label:'Completed',class:'status-delivered'};
 }
 return campaignStatusConfig[state]??{label:state,class:'bg-muted text-muted-foreground'};
}
export const sendingStatusHint='This shows sending progress, not network delivery. Successfully sent means the provider accepted the SMS for delivery. Excluded recipients and failed submissions are shown separately. Delivery confirmations update as reports arrive.';
