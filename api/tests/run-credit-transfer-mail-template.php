<?php
// Pure rendering and an injected no-network mailer. No .env, SMTP, wallet or real code.
require_once __DIR__.'/../services/EmailService.php';
$render=new ReflectionMethod(EmailService::class,'renderCreditTransferCode');$n=0;
function checkMail(bool $ok,string $why):void{global$n;$n++;if(!$ok)throw new RuntimeException($why);}
$reference='0123456789abcdef0123456789abcdef';
$mail=$render->invoke(null,'012345',200,'preview-recipient@example.invalid',$reference);
checkMail($mail['subject']==='Confirm your SMS credit transfer','Existing subject preserved');
checkMail(str_contains($mail['html'],'012345')&&str_contains($mail['text'],'012345'),'Leading zero code preserved');
checkMail(str_contains($mail['html'],'200')&&str_contains($mail['text'],'200 SMS credits'),'Exact transfer amount');
checkMail(str_contains($mail['html'],'preview-recipient@example.invalid')&&str_contains($mail['text'],'preview-recipient@example.invalid'),'Exact recipient');
checkMail(str_contains($mail['html'],'TRF-'.strtoupper($reference))&&str_contains($mail['text'],'TRF-'.strtoupper($reference)),'Existing reference format preserved');
checkMail(str_contains($mail['html'],'width=device-width')&&str_contains($mail['html'],'max-width:560px'),'Fluid email layout');
checkMail(!preg_match('/<(?:img|script|iframe|form)\b/i',$mail['html']),'No trackers, remote images or forms');
checkMail(substr_count($mail['html'],'href="mailto:security@ieosuia.com"')===2,'Security contact in warning and footer');
checkMail(!str_contains($mail['html'],'sms@ieosuia.com')&&!str_contains($mail['text'],'sms@ieosuia.com'),'General SMS mailbox not used for security');
foreach(['Never share','within 10 minutes','Use once only','No credits move without confirmation','not a completed transfer','security@ieosuia.com']as$copy)checkMail(str_contains($mail['text'],$copy),'Plain-text copy: '.$copy);
checkMail(!str_contains($mail['html'],'code=')&&!str_contains($mail['html'],'token='),'No secrets in links');
$escaped=$render->invoke(null,'012345',1000,'<img src="https://fixture.invalid/x">','<script>fixture</script>');
checkMail(str_contains($escaped['html'],'&lt;img')&&!str_contains($escaped['html'],'<img'),'Recipient escaped');
checkMail(str_contains($escaped['html'],'&lt;SCRIPT&gt;')&&!str_contains($escaped['html'],'<SCRIPT>'),'Reference escaped');
checkMail(str_contains($escaped['text'],'1,000 SMS credits'),'Large amounts formatted consistently');
foreach(['12345','1234567','12345x','123456'."\n",'<code>']as$bad){
    try{$render->invoke(null,$bad,200,'fixture@example.invalid',$reference);checkMail(false,'Bad code accepted');}
    catch(InvalidArgumentException $e){checkMail(!str_contains($e->getMessage(),$bad),'Bad code not logged in exception');}
}
foreach([0,-1]as$bad){try{$render->invoke(null,'012345',$bad,'fixture@example.invalid',$reference);checkMail(false,'Bad amount');}catch(InvalidArgumentException){checkMail(true,'Bad amount rejected');}}
$property=new ReflectionProperty(EmailService::class,'mailer');
checkMail($property->getValue()===null,'Rendering and validation never initialize SMTP');
final class TransferMailCapture extends PHPMailer\PHPMailer\PHPMailer {
    public array $captured=[];
    public function send():bool{$this->captured=['html'=>$this->Body,'text'=>$this->AltBody,'subject'=>$this->Subject,'from'=>$this->From,'reply'=>$this->getReplyToAddresses(),'debug'=>$this->SMTPDebug];return true;}
}
$capture=new TransferMailCapture(true);$capture->setFrom('noreply@example.invalid','IEOSUIA SMS Portal');$capture->SMTPDebug=4;$property->setValue(null,$capture);
try{
    $r=EmailService::sendCreditTransferCode('fixture@example.invalid','012345',200,'preview-recipient@example.invalid',$reference);
    checkMail($r['success']===true,'Public helper preserves result contract');
    checkMail($capture->captured['html']===$mail['html']&&$capture->captured['text']===$mail['text'],'Public helper sends HTML and explicit plain text');
    checkMail(in_array('security@ieosuia.com',array_column($capture->captured['reply'],0),true),'Replies go to security');
    checkMail($capture->captured['from']==='noreply@example.invalid','Configured sender unchanged');
    checkMail($capture->captured['debug']===0&&$capture->SMTPDebug===4,'Debug disabled during verification email and restored');
    EmailService::sendSmsLoginCode('fixture@example.invalid','012345','login');
    checkMail(in_array('security@ieosuia.com',array_column($capture->captured['reply'],0),true),'Sign-in security replies use the same security mailbox');
    checkMail(str_contains($capture->captured['html'],'security@ieosuia.com')&&!str_contains($capture->captured['html'],'sms@ieosuia.com'),'Sign-in security contact corrected too');
}finally{$property->setValue(null,null);}
if(isset($argv[1])){
    if(!is_dir($argv[1]))throw new RuntimeException('Preview output directory must already exist');
    file_put_contents($argv[1].'/transfer-preview.html',$mail['html']);
    $long=$render->invoke(null,'012345',1234567,str_repeat('long-recipient-',5).'@example.invalid',str_repeat('abcdef0123456789',4));
    file_put_contents($argv[1].'/transfer-long-preview.html',$long['html']);
}
echo "PASS: $n transfer-email rendering, escaping, security contact and no-network sender checks. No email sent.\n";
