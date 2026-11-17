<?php
declare(strict_types=1);
require_once __DIR__.'/../services/SmsService.php';
$cases=[
 ['DELIVERED',true],['<Response><Status>DELIVERED</Status></Response>',true],
 ['<Response status="SENT"/>',true],['<Response status="DELIVERED"><Status>DELIVERED</Status></Response>',true],
 ['<Response><Status>DELIVERED</Status><Status>FAILED</Status></Response>',false],
 ['<Response status="FAILED"><Status>DELIVERED</Status></Response>',false],
 ['<Response><one status="SENT"/><two status="FAILED"/></Response>',false],
 [str_repeat(' ',10).str_repeat('A',65537),false],
 [str_repeat(' ',65536).'DELIVERED',false],
 ['<!DOCTYPE x [<!ENTITY xx SYSTEM "file:///etc/passwd">]><x><Status>&xx;</Status></x>',false],
 ['<html><body>DELIVERED</body></html>',false],['Cannot Find Message',false],['working',false],
];
foreach($cases as [$body,$expected]){if(SmsService::parseStatusResponse($body)['success']!==$expected)throw new RuntimeException('Status query safety case failed');}
echo 'PASS '.count($cases)." bounded/unambiguous status response checks; no network\n";
