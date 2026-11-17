<?php
// Local synthetic rendering only; deliberately does not import database configuration or SMTP.
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../api/domain/EmailCampaignPolicy.php';
$root=realpath(__DIR__.'/..');$output=$argv[1]??'';$parent=realpath(dirname($output));$private=realpath($root.'/.sms-audit.local');
if(!$parent||!$private||!str_starts_with(str_replace('\\','/',$parent).'/',str_replace('\\','/',$private).'/')||basename($output)!=='email-fixture-preview.html')throw new RuntimeException('Use an existing private audit directory and email-fixture-preview.html');
$sender=['business_name'=>'Example Community','postal_address'=>'100 Example Street, Example City','reply_to'=>'owner@example.org'];
$preview=EmailCampaignPolicy::render(['body'=>"Hello,\nThis is your requested community update. This message is a synthetic preview only."],$sender,'https://portal.example.org/api/email/preferences/'.str_repeat('a',32).'?token='.str_repeat('b',64),'https://portal.example.org/api/email/preferences/'.str_repeat('a',32).'?token='.str_repeat('b',64).'&report=1');
file_put_contents($output,$preview['html']);echo 'Rendered synthetic HTML; no SMTP, database, live recipients or sends.'.PHP_EOL;
