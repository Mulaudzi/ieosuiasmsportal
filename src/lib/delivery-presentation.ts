export const deliveryOutcomes = [
  {key:'delivered',label:'Delivered',detail:'Network confirmed the SMS was delivered.'},
  {key:'pending',label:'Delivery Confirmation Pending',detail:'SMS was successfully sent, but delivery confirmation is still pending.'},
  {key:'unavailable',label:'Delivery Status Unavailable',detail:'SMS was successfully sent, but the network did not provide a delivery status. The recipient may have received it.'},
  {key:'failed',label:'Not Delivered',detail:'Network reported that the SMS could not be delivered.'},
  {key:'skipped',label:'Skipped',detail:'SMS was not sent, for example because the recipient had opted out.'},
] as const;
export function deliveryOutcome(state:string, detail?:string) {
  const key=state==='dlr_unavailable'||(state==='sent'&&detail?.startsWith('DLR lookup:'))?'unavailable':state==='sent'?'pending':state;
  return deliveryOutcomes.find(o=>o.key===key && !(['queued','pending','processing','retry'].includes(state)));
}
export function deliveryChartStyle(label:string) {
  const keys:Record<string,string>={'delivered':'delivered','awaiting confirmation':'pending','delivery confirmation pending':'pending','confirmation unavailable':'unavailable','delivery status unavailable':'unavailable','failed':'failed','not delivered':'failed','skipped':'skipped'};
  const key=keys[label.trim().toLowerCase()]; const outcome=deliveryOutcomes.find(o=>o.key===key);
  return {label:outcome?.label ?? label,color:`var(--delivery-${key ?? 'skipped'})`};
}
