<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../domain/PhoneNumber.php';

$apply=in_array('--apply',$argv,true);
$pdo=db();
$contacts=$pdo->query("SELECT id,user_id,phone,phone_normalized FROM contacts WHERE phone IS NOT NULL AND phone<>'' ORDER BY user_id,id")->fetchAll();
$seen=[];$updates=[];$invalid=[];$collisions=[];

foreach($contacts as $contact){
    try{$normalized=PhoneNumber::normalize((string)$contact['phone']);}
    catch(InvalidArgumentException $e){$invalid[]=['id'=>$contact['id'],'reason'=>$e->getMessage()];continue;}
    $key=$contact['user_id'].'|'.$normalized;
    if(isset($seen[$key])){$collisions[]=['user_id'=>$contact['user_id'],'kept_id'=>$seen[$key],'duplicate_id'=>$contact['id'],'phone'=>$normalized];continue;}
    $seen[$key]=$contact['id'];
    if((string)($contact['phone_normalized']??'')!==$normalized)$updates[]=['id'=>$contact['id'],'original'=>$contact['phone'],'normalized'=>$normalized];
}

echo json_encode(['mode'=>$apply?'apply':'dry-run','contacts_scanned'=>count($contacts),'updates'=>count($updates),'invalid'=>$invalid,'collisions'=>$collisions],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
if(!$apply){echo "No data changed. Re-run with --apply after resolving reported collisions.\n";exit($collisions?2:0);}
if($collisions){fwrite(STDERR,"Refusing to apply while tenant duplicate collisions exist.\n");exit(2);}

$pdo->beginTransaction();
try{
    $stmt=$pdo->prepare('UPDATE contacts SET phone_original=COALESCE(phone_original,phone),phone_normalized=?,updated_at=NOW() WHERE id=?');
    foreach($updates as $row)$stmt->execute([$row['normalized'],$row['id']]);
    $optouts=$pdo->query("SELECT id,recipient FROM opt_outs WHERE channel='sms'")->fetchAll();
    $optoutUpdate=$pdo->prepare('UPDATE opt_outs SET recipient=? WHERE id=?');
    foreach($optouts as $row){try{$normalized=PhoneNumber::normalize((string)$row['recipient']);$optoutUpdate->execute([$normalized,$row['id']]);}catch(InvalidArgumentException){} }
    $pdo->commit();echo 'Applied '.count($updates)." contact updates.\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
