<?php
require __DIR__.'/../services/PurchaseAlertService.php';
$data=['credits_added'=>28,'customer_credits'=>1042,'available_credits'=>1000,'provider_credits'=>650.5,'amount'=>10,'name'=>'Test <Customer>','email'=>'test@example.invalid','merchant_reference'=>'TEST-ONLY','gateway_reference'=>'TEST-PAYOS','processed_at'=>'2026-10-02 12:00:00','checked_at'=>'2026-10-02 12:01:00 SAST'];
$html=PurchaseAlertService::render($data);
$checks=[PurchaseAlertService::RECIPIENT==='sms@ieosuia.com',str_contains($html,'1,042'),str_contains($html,'650.50'),str_contains($html,'R10.00'),str_contains($html,'Test &lt;Customer&gt;'),!str_contains($html,'Test <Customer>'),str_contains($html,'28'),str_contains($html,'1,000'),str_contains($html,'SAST')];
$data['provider_credits']=null;$checks[]=str_contains(PurchaseAlertService::render($data),'Temporarily unavailable');
$data['provider_credits']=0;$checks[]=str_contains(PurchaseAlertService::render($data),'>0.00<');
if(in_array(false,$checks,true))throw new RuntimeException('Purchase alert test failed');
echo count($checks)." purchase alert checks passed\n";
if(in_array('--preview',$argv,true))file_put_contents(__DIR__.'/../../.sms-audit.local/purchase-alert-preview.html',$html);
