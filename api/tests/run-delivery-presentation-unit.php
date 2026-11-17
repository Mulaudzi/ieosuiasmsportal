<?php
require_once __DIR__.'/../domain/SmsDeliveryPresentation.php';
$cases=[
 ['sent',null,'Sent - Awaiting Confirmation'],
 ['sent','DLR lookup: Cannot Find Message','Sent - Awaiting Confirmation'],
 ['dlr_unavailable','DLR lookup: Cannot Find Message','Sent - Confirmation Unavailable'],
 ['delivered',null,'Delivered'],
 ['failed','Invalid destination','Not Delivered'],
 ['queued',null,'Waiting to send'],
 ['retry',null,'Retry scheduled'],
 ['processing',null,'Sending'],
 ['skipped',null,'Not Sent'],
];
foreach($cases as [$state,$detail,$label]){
 if(SmsDeliveryPresentation::label($state,$detail)!==$label)throw new RuntimeException('Incorrect label: '.$state);
 if(preg_match('/\bDLR\b/',SmsDeliveryPresentation::details($state,$detail)))throw new RuntimeException('Technical jargon in customer details');
}
if(SmsDeliveryPresentation::details('failed','Invalid destination')!=='The mobile network reported that your SMS could not be delivered.')throw new RuntimeException('Failure reason lost');
if(!str_contains(SmsDeliveryPresentation::details('sent','DLR lookup: Cannot Find Message'),'awaiting confirmation'))throw new RuntimeException('Missing uncertainty explanation');
foreach(['delivered','failed','skipped'] as $state){
 if(SmsDeliveryPresentation::label($state,'DLR lookup: Cannot Find Message')!==SmsDeliveryPresentation::label($state))throw new RuntimeException('Stale diagnostic changed current label');
 if(SmsDeliveryPresentation::details($state,'DLR lookup: Cannot Find Message')!==SmsDeliveryPresentation::details($state,null))throw new RuntimeException('Stale diagnostic changed current details');
}
$blockedDetail='Blocked by the messaging provider or recipient restrictions. The exact reason is unavailable.';
foreach(['BLOCKED','LogicSMS delivery status: blocked 2026-10-03 18:57:24'] as $reason){
 if(SmsDeliveryPresentation::details('failed',$reason)!==$blockedDetail)throw new RuntimeException('Missing safe blocked explanation');
 if(SmsDeliveryPresentation::reasonCode('failed',$reason)!=='provider_blocked')throw new RuntimeException('Missing safe blocked reason code');
 foreach(['delivered','sent','skipped'] as $state)if(SmsDeliveryPresentation::reasonCode($state,$reason)!==null)throw new RuntimeException('Stale block changes current outcome');
 if(SmsDeliveryPresentation::reasonCode('failed',$reason,false)!==null)throw new RuntimeException('Unsent submission labelled as blocked delivery');
}
foreach(['DLR lookup: BLOCKED','BLOCKED because recipient opted out','LogicSMS delivery status: UNDELIVERED'] as $reason)if(SmsDeliveryPresentation::reasonCode('failed',$reason)!==null)throw new RuntimeException('Unverified error prose became a blocking reason');
echo "PASS: delivery state labels, safe blocked reasons, current-state precedence and no false delivery confirmation\n";
