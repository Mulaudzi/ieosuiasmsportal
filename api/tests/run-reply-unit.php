<?php
require __DIR__.'/../services/LogicSmsReplyService.php';
$checks=0;
function checkReply(bool $ok):void{global $checks;$checks++;if(!$ok)throw new RuntimeException('Reply assertion '.$checks.' failed');}
foreach(['STOP',' stop ','Unsubscribe','STOP ALL'] as $text)checkReply(LogicSmsReplyService::isStop($text));
foreach(['Please stop by tomorrow','START',''] as $text)checkReply(!LogicSmsReplyService::isStop($text));
$rows=LogicSmsReplyService::parse('<replies><reply><id>1</id><fromcell>27799282775</fromcell><message>STOP</message><ref>portal-message-26</ref><date>2026/09/24 12:00:00</date></reply></replies>');
checkReply($rows[0]['ref']==='portal-message-26');checkReply(count(LogicSmsReplyService::parse('<replies/>'))===0);
foreach(['Invalid password','<error>denied</error>','<replies><reply><id>1</id></reply></replies>'] as $bad){try{LogicSmsReplyService::parse($bad);checkReply(false);}catch(RuntimeException $e){if(str_starts_with($e->getMessage(),'Reply assertion'))throw $e;checkReply(true);}}
echo "$checks reply checks passed\n";
