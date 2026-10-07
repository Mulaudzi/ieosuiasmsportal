<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../services/CampaignReportMailer.php';
try{$result=CampaignReportMailer::dispatchDue();echo json_encode($result).PHP_EOL;exit(($result['review_required']??0)>0?1:0);}
catch(Throwable $e){fwrite(STDERR,"Campaign report worker failed. Check migration 012 and server logs.\n");exit(1);}
