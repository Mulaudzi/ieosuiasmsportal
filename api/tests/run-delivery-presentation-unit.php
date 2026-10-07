<?php
require_once __DIR__.'/../domain/SmsDeliveryPresentation.php';
$cases=[
 ['sent',null,'Delivery Confirmation Pending'],
 ['sent','DLR lookup: Cannot Find Message','Delivery Status Unavailable'],
 ['dlr_unavailable','DLR lookup: Cannot Find Message','Delivery Status Unavailable'],
 ['delivered',null,'Delivered'],
 ['failed','Invalid destination','Not Delivered'],
 ['queued',null,'Waiting to send'],
 ['retry',null,'Retry scheduled'],
 ['processing',null,'Sending'],
 ['skipped',null,'Skipped'],
];
foreach($cases as [$state,$detail,$label]){
 if(SmsDeliveryPresentation::label($state,$detail)!==$label)throw new RuntimeException('Incorrect label: '.$state);
 if(preg_match('/\bDLR\b/',SmsDeliveryPresentation::details($state,$detail)))throw new RuntimeException('Technical jargon in customer details');
}
if(SmsDeliveryPresentation::details('failed','Invalid destination')!=='Network reported that the SMS could not be delivered.')throw new RuntimeException('Failure reason lost');
if(!str_contains(SmsDeliveryPresentation::details('sent','DLR lookup: Cannot Find Message'),'may have received it'))throw new RuntimeException('Missing uncertainty explanation');
foreach(['delivered','failed','skipped'] as $state){
 if(SmsDeliveryPresentation::label($state,'DLR lookup: Cannot Find Message')!==SmsDeliveryPresentation::label($state))throw new RuntimeException('Stale diagnostic changed current label');
 if(SmsDeliveryPresentation::details($state,'DLR lookup: Cannot Find Message')!==SmsDeliveryPresentation::details($state,null))throw new RuntimeException('Stale diagnostic changed current details');
}
echo "PASS: 9 delivery state labels, approved details, latest state overrides stale diagnostics, no false delivery confirmation\n";
