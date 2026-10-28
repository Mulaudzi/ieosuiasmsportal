export const deliveryOutcomes = [
  {key:'delivered',label:'Delivered',detail:'Delivery confirmed by the mobile network.'},
  {key:'pending',label:'Sent - Awaiting Confirmation',detail:'Your SMS has been successfully sent for delivery. We are awaiting confirmation from the mobile network.'},
  {key:'unavailable',label:'Sent - Confirmation Unavailable',detail:'Your SMS was successfully sent for delivery, but a final delivery confirmation is unavailable. This does not necessarily mean that the message was not delivered.'},
  {key:'failed',label:'Not Delivered',detail:'The mobile network reported that your SMS could not be delivered.'},
  {key:'skipped',label:'Not Sent',detail:'This SMS was excluded before sending, for example because the recipient opted out.'},
] as const;
export const failedSubmission = {key:'failed',label:'Failed to send',detail:'The SMS provider did not accept this message for delivery. It was not successfully sent.'} as const;
export function deliveryOutcome(state:string, _detail?:string, submitted=true) {
  if(state==='failed'&&!submitted)return failedSubmission;
  const key=state==='dlr_unavailable'?'unavailable':state==='sent'?'pending':state;
  return deliveryOutcomes.find(o=>o.key===key && !(['queued','pending','processing','retry'].includes(state)));
}
export function deliveryChartStyle(label:string) {
  const keys:Record<string,string>={'delivered':'delivered','sent - awaiting confirmation':'pending','awaiting confirmation':'pending','delivery confirmation pending':'pending','sent - confirmation unavailable':'unavailable','confirmation unavailable':'unavailable','delivery status unavailable':'unavailable','failed':'failed','not delivered':'failed','not sent':'skipped','skipped':'skipped'};
  if(label.toLowerCase()==='failed to send')return {label:'Failed to send',color:'var(--delivery-failed)'};
  const key=keys[label.trim().toLowerCase()]; const outcome=deliveryOutcomes.find(o=>o.key===key);
  return {label:outcome?.label ?? label,color:`var(--delivery-${key ?? 'skipped'})`};
}

/** Never put raw provider errors into the customer Result column, even during a rolling release. */
export function recipientDelivery(row:{status:string;state?:string;technical_status?:string;reason_code?:string|null}) {
  const aliases:Record<string,string>={'Delivered':'delivered','Not Delivered':'failed','Failed to send':'failed','Delivery Confirmation Pending':'sent','Sent - Awaiting Confirmation':'sent','Delivery Status Unavailable':'dlr_unavailable','Sent - Confirmation Unavailable':'dlr_unavailable','Skipped':'skipped','Not Sent':'skipped'};
  const state=row.state??aliases[row.status]??'';
  const outcome=deliveryOutcome(state,undefined,row.technical_status!=='FAILED_TO_SEND'&&row.status!=='Failed to send');
  const blocked=state==='failed'&&row.technical_status!=='FAILED_TO_SEND'&&row.status!=='Failed to send'&&row.reason_code==='provider_blocked';
  return outcome?{label:outcome.label,detail:blocked?'Blocked by the messaging provider or recipient restrictions. The exact reason is unavailable.':outcome.detail,color:`var(--delivery-${outcome.key})`}
    :{label:row.status,detail:state==='processing'?'This SMS is being submitted. Successful sending has not yet been confirmed.':'This SMS is waiting to be sent. The provider has not accepted it for delivery yet.',color:'var(--delivery-pending)'};
}
