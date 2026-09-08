<?php

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/database.php';

$pdo=db();$database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();$version=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
$checks=[];
$scalar=function(string $sql)use($pdo):int{return (int)$pdo->query($sql)->fetchColumn();};
$checks['orphan_accounts']=$scalar('SELECT COUNT(*) FROM accounts a LEFT JOIN users u ON u.id=a.user_id WHERE u.id IS NULL');
$checks['orphan_contacts']=$scalar('SELECT COUNT(*) FROM contacts c LEFT JOIN users u ON u.id=c.user_id WHERE u.id IS NULL');
$checks['orphan_wallets']=$scalar('SELECT COUNT(*) FROM wallets w LEFT JOIN users u ON u.id=w.user_id WHERE u.id IS NULL');
$checks['duplicate_payment_references']=$scalar("SELECT COUNT(*) FROM (SELECT gateway,merchant_reference FROM payments WHERE gateway IS NOT NULL AND merchant_reference IS NOT NULL GROUP BY gateway,merchant_reference HAVING COUNT(*)>1) x");
$checks['non_zar_wallets']=$scalar("SELECT COUNT(*) FROM wallets WHERE currency<>'ZAR'");
$checks['negative_wallet_balances']=$scalar('SELECT COUNT(*) FROM wallets WHERE balance<0 OR reserved<0 OR reserved>balance');
$checks['invalid_contact_phones']=$scalar("SELECT COUNT(*) FROM contacts WHERE phone IS NOT NULL AND phone<>'' AND REGEXP_REPLACE(phone,'[^0-9]','') NOT REGEXP '^(0[0-9]{9}|27[0-9]{9}|[1-9][0-9]{7,14})$'");
$checks['duplicate_contact_phones']=$scalar("SELECT COUNT(*) FROM (SELECT user_id,CASE WHEN digits REGEXP '^0[0-9]{9}$' THEN CONCAT('+27',SUBSTRING(digits,2)) WHEN digits REGEXP '^27[0-9]{9}$' THEN CONCAT('+',digits) WHEN digits REGEXP '^[1-9][0-9]{7,14}$' THEN CONCAT('+',digits) ELSE NULL END normalized FROM (SELECT user_id,REGEXP_REPLACE(COALESCE(phone,''),'[^0-9]','') digits FROM contacts) raw) normalized_contacts WHERE normalized IS NOT NULL GROUP BY user_id,normalized HAVING COUNT(*)>1) x");
$column=$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contacts' AND COLUMN_NAME='phone_normalized'")->fetchColumn();
if((int)$column>0)$checks['duplicate_contact_phones_after_migration']=$scalar("SELECT COUNT(*) FROM (SELECT user_id,phone_normalized FROM contacts WHERE phone_normalized IS NOT NULL GROUP BY user_id,phone_normalized HAVING COUNT(*)>1) x");
$blocking=array_filter($checks,fn($count)=>$count>0);
echo json_encode(['database'=>$database,'server_version'=>$version,'checks'=>$checks,'safe_to_migrate'=>count($blocking)===0],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($blocking?2:0);
