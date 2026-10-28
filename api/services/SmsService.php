<?php
/**
 * LogicSMS gateway service.
 */

require_once __DIR__.'/../domain/LogicSmsDeliveryStatus.php';

class SmsService {
    private $logicSmsUrl;
    private $logicSmsUsername;
    private $logicSmsPassword;
    private $transport;
    
    public function __construct(?callable $transport=null) {
        $this->logicSmsUrl = env('LOGICSMS_API_URL', 'https://www.logicsms.co.za/postmsg2.aspx');
        $this->logicSmsUsername = env('LOGICSMS_USERNAME');
        $this->logicSmsPassword = env('LOGICSMS_PASSWORD');
        $this->transport=$transport;
    }
    
    public function send(string $phone, string $message, ?string $senderId = null, ?string $clientReference = null): array {
        $senderId = $senderId ?? env('SMS_DEFAULT_SENDER', 'IEOSUIA');
        
        if(!$this->logicSmsUsername||!$this->logicSmsPassword)return ['success'=>false,'error'=>'LogicSMS is not configured','gateway'=>'logicsms'];
        return $this->sendViaLogicSms($phone, $message, $senderId,$clientReference);
    }
    
    public static function submissionTimeoutSeconds(): int {
        return max(5,min(20,(int)env('SMS_PROVIDER_TIMEOUT_SECONDS',15)));
    }

    private function submissionParameters(string $phone,string $message,string $senderId,?string $clientReference):array {
        return [
            'username' => $this->logicSmsUsername,
            'password' => $this->logicSmsPassword,
            'mobile' => $this->formatPhone($phone),
            'message' => $message,
            'Originator' => $senderId,
            'Unique' => preg_replace('/[^A-Za-z0-9_-]/','',(string)($clientReference?:bin2hex(random_bytes(12)))),
            'DCheck' => 'Y',
        ];
    }

    private function sendViaLogicSms(string $phone, string $message, string $senderId,?string $clientReference=null): array {
        if(!filter_var($this->logicSmsUrl,FILTER_VALIDATE_URL)||strtolower((string)parse_url($this->logicSmsUrl,PHP_URL_SCHEME))!=='https')return ['success'=>false,'error'=>'LogicSMS API URL must use HTTPS','gateway'=>'logicsms'];
        $params=$this->submissionParameters($phone,$message,$senderId,$clientReference);
        if($this->transport){$response=($this->transport)($this->logicSmsUrl,$params);return self::parseResponse((string)($response['body']??''),(string)($response['error']??''),(int)($response['status']??200));}
        $ch=$this->createSubmissionHandle($phone,$message,$senderId,$clientReference);
        if(is_array($ch))return $ch;
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        return self::parseResponse((string)$response,$error,$status);
    }

    /** Build only: the worker persists submission intent before executing this handle. */
    public function createSubmissionHandle(string $phone,string $message,?string $senderId=null,?string $clientReference=null): CurlHandle|array {
        if(!$this->logicSmsUsername||!$this->logicSmsPassword)return ['success'=>false,'error'=>'LogicSMS is not configured','gateway'=>'logicsms'];
        if(!filter_var($this->logicSmsUrl,FILTER_VALIDATE_URL)||strtolower((string)parse_url($this->logicSmsUrl,PHP_URL_SCHEME))!=='https')return ['success'=>false,'error'=>'LogicSMS API URL must use HTTPS','gateway'=>'logicsms'];
        $params=$this->submissionParameters($phone,$message,$senderId??env('SMS_DEFAULT_SENDER','IEOSUIA'),$clientReference);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->logicSmsUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::submissionTimeoutSeconds(),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $caBundle=trim((string)env('LOGICSMS_CA_BUNDLE',''));
        if($caBundle!==''){
            if(!is_file($caBundle)||!is_readable($caBundle)){curl_close($ch);return ['success'=>false,'error'=>'Configured LogicSMS CA bundle is not readable','gateway'=>'logicsms'];}
            curl_setopt($ch,CURLOPT_CAINFO,$caBundle);
        }
        return $ch;
    }

    public static function parseResponse(string $response,string $error='',int $httpStatus=200):array {
        $diagnostic=['http_status'=>$httpStatus,'response_bytes'=>strlen($response),'response_sha256'=>hash('sha256',$response)];
        $failure=static function(string $code,string $message,bool $uncertain=true,?string $reason=null)use(&$diagnostic):array{
            $details=$diagnostic+['code'=>$code];
            if($reason!==null)$details['rejection_reason']=$reason;
            return ['success'=>false,'uncertain'=>$uncertain,'retryable'=>false,'error_code'=>$code,'error'=>$message,'gateway'=>'logicsms','diagnostic'=>$details];
        };
        $invalid=static fn(string $reason):array=>$failure('INVALID_ACKNOWLEDGEMENT','Unverified LogicSMS acknowledgement; reconciliation required before resend',true,$reason);
        if($error!=='')return $failure(preg_match('/timed?\s*out|timeout/i',$error)?'TRANSPORT_TIMEOUT':'TRANSPORT_ERROR','LogicSMS transport outcome unavailable');
        if($httpStatus<200||$httpStatus>=300)return $failure('HTTP_'.$httpStatus,'LogicSMS HTTP '.$httpStatus);
        $response=trim(preg_replace('/^\xEF\xBB\xBF/','',$response));
        // Parse only an entire, bounded XML document. Never salvage a numeric ID
        // from an HTML challenge, a partial response, or entity-bearing markup.
        if(strlen($response)>65536)return $invalid('RESPONSE_TOO_LARGE');
        if(preg_match('/<!DOCTYPE|<!ENTITY/i',$response))return $invalid('PROHIBITED_XML_DECLARATION');
        $previous=libxml_use_internal_errors(true);
        libxml_clear_errors();
        try{$xml=simplexml_load_string($response,'SimpleXMLElement',LIBXML_NONET);}catch(Throwable){$xml=false;}
        $xmlErrors=libxml_get_errors();
        $diagnostic+=['xml_parsed'=>$xml!==false,'xml_error_count'=>count($xmlErrors),'xml_error_codes'=>array_slice(array_values(array_unique(array_map(static fn($item):int=>(int)$item->code,$xmlErrors))),0,8)];
        libxml_clear_errors();libxml_use_internal_errors($previous);
        if($xml===false)return $invalid('XML_PARSE_FAILED');
        $diagnostic+=self::acknowledgementDiagnostic($xml);
        $shape=$xml!==false && in_array($xml->getName(),['Message','Response'],true) && count($xml->Id)===1 && count($xml->Status)===1;
        $id=$shape?trim((string)$xml->Id):'';
        $status=$shape?strtoupper(trim((string)$xml->Status)):'';
        // DCheck=Y may return the status of an already accepted message. Its
        // positive provider ID is acceptance evidence, not a second submission.
        // Verified owner test 27: a new accepted submission returns CREATE,
        // a real positive ID, positive MessageCost and Parts, and an empty IdList.
        // This is submission acceptance, never handset delivery. Bare/partial
        // CREATE responses remain uncertain and cannot trigger a resend.
        $created=$shape && $xml->getName()==='Message' && $status==='CREATE'
            && count($xml->MessageCost)===1 && count($xml->Parts)===1 && count($xml->IdList)===1
            && preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',trim((string)$xml->MessageCost)) && (float)$xml->MessageCost>0
            && preg_match('/^[1-9][0-9]*$/D',trim((string)$xml->Parts))
            && count($xml->xpath('/*/*/*')?:[])===0;
        if ($shape && preg_match('/^[1-9][0-9]*$/D',$id) && ($created || in_array($status,['SENT','DELIVERED','DELIVRD','UNDELIVERED','UNDELIV','BLOCKED','EXPIRED'],true))) {
            return [
                'success' => true,
                'message_id' => $id,
                'status' => $status,
                'provider_cost' => isset($xml->MessageCost)?(float)$xml->MessageCost:null,
                'parts' => isset($xml->Parts)?(int)$xml->Parts:null,
                'id_list' => isset($xml->IdList)?(string)$xml->IdList:null,
                'gateway' => 'logicsms',
                'diagnostic'=>$diagnostic+['code'=>'ACCEPTED','provider_status'=>$status],
            ];
        }
        // Verified live no-credential response: Message/Id=0/Status=Error with
        // zero cost, parts and IdList plus Reason. Never treat that as acceptance.
        if($shape && $xml->getName()==='Message' && $id==='0' && $status==='ERROR'
            && count($xml->Reason)===1 && trim((string)$xml->Reason)!==''
            && trim((string)$xml->MessageCost)==='0' && trim((string)$xml->Parts)==='0' && trim((string)$xml->IdList)==='0'){
            $reason=trim((string)$xml->Reason);
            $code=match(true){
                (bool)preg_match('/username|password|credential|authentication/i',$reason)=>'PROVIDER_AUTHENTICATION',
                (bool)preg_match('/insufficient|not enough|no credits|credit.*(?:low|exhaust|balance)/i',$reason)=>'PROVIDER_CREDITS',
                (bool)preg_match('/invalid|incorrect.*(?:mobile|number)|mobile.*format/i',$reason)=>'PROVIDER_VALIDATION',
                default=>'PROVIDER_REJECTED',
            };
            return $failure($code,match($code){
                'PROVIDER_AUTHENTICATION'=>'LogicSMS rejected the gateway credentials. Support must correct the gateway account before retrying.',
                'PROVIDER_CREDITS'=>'LogicSMS rejected the submission because gateway credits are unavailable. Support must check the gateway balance.',
                'PROVIDER_VALIDATION'=>'LogicSMS rejected the submission parameters. Support must check the gateway validation result.',
                default=>'LogicSMS explicitly rejected the submission. No provider acceptance was recorded.',
            },false);
        }
        $reason=match(true){
            !in_array($xml->getName(),['Message','Response'],true)=>'UNEXPECTED_ROOT',
            count($xml->Id)!==1||count($xml->Status)!==1=>'EXPECTED_SINGLE_ID_AND_STATUS',
            !preg_match('/^[1-9][0-9]*$/D',$id)=>$id==='0'&&$status==='ERROR'?'INCOMPLETE_EXPLICIT_REJECTION':'INVALID_PROVIDER_ID',
            default=>'UNRECOGNIZED_PROVIDER_STATUS',
        };
        return $invalid($reason);
    }

    /**
     * Passive, bounded metadata for support. Never store response text, XML error
     * messages, arbitrary tag/namespace names, Reason, destination or message.
     * Namespace-agnostic inspection is diagnostic only: acceptance above still
     * uses exactly the original direct Id/Status checks and accepted statuses.
     */
    private static function acknowledgementDiagnostic(SimpleXMLElement $xml):array
    {
        $root=$xml->getName();
        $namespaces=array_values(array_unique(array_values($xml->getDocNamespaces(true))));
        sort($namespaces,SORT_STRING);
        $details=[
            'xml_root'=>in_array($root,['Message','Response','string','html','HTML'],true)?$root:'OTHER',
            'xml_root_sha256'=>hash('sha256',$root),
            'xml_namespace_count'=>count($namespaces),
            'xml_namespace_sha256'=>array_map(static fn(string $value):string=>hash('sha256',$value),array_slice($namespaces,0,8)),
            'xml_namespace_list_truncated'=>count($namespaces)>8,
            'direct_field_counts'=>[],
            'local_field_counts'=>[],
            'fields'=>[],
        ];
        foreach(['Id','Status','IdList','MessageCost','Parts','Reason'] as $field){
            $details['direct_field_counts'][$field]=count($xml->{$field});
            $nodes=$xml->xpath('/*/*[local-name()="'.$field.'"]')?:[];
            $details['local_field_counts'][$field]=count($nodes);
            if(count($nodes)!==1||$field==='Reason')continue;
            $node=$nodes[0];$value=trim((string)$node);
            $valueDetails=['bytes'=>strlen($value),'sha256'=>hash('sha256',$value),'has_child_elements'=>count($node->xpath('.//*')?:[])>0];
            $safe=!$valueDetails['has_child_elements']&&match($field){
                'Id'=>strlen($value)<=20&&(bool)preg_match('/^[0-9]+$/D',$value),
                'Status'=>in_array(strtoupper($value),['SENT','DELIVERED','DELIVRD','UNDELIVERED','UNDELIV','BLOCKED','EXPIRED','ERROR','CREATE','INVALID','UNKNOWN','QUEUED','PENDING'],true),
                'IdList'=>strlen($value)<=335&&(bool)preg_match('/^[0-9]{1,20}(?:[,;| ][0-9]{1,20}){0,15}$/D',$value),
                'MessageCost'=>strlen($value)<=24&&(bool)preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,8})?$/D',$value),
                'Parts'=>strlen($value)<=5&&(bool)preg_match('/^[0-9]{1,5}$/D',$value),
                default=>false,
            };
            if($safe)$valueDetails['value']=$field==='Status'?strtoupper($value):$value;
            $details['fields'][$field]=$valueDetails;
        }
        return $details;
    }
    
    /**
     * Send bulk SMS (more efficient for campaigns)
     */
    public function sendBulk(array $messages): array {
        $results = [];
        foreach ($messages as $msg) {
            $results[] = $this->send(
                $msg['phone'],
                $msg['message'],
                $msg['sender_id'] ?? null
            );
        }
        return $results;
    }
    
    /**
     * Get message status
     */
    public function getStatus(string $providerMessageId, ?string $clientReference = null): array {
        if(!$this->logicSmsUsername||!$this->logicSmsPassword)return ['success'=>false,'error'=>'LogicSMS is not configured','gateway'=>'logicsms'];
        $url=trim((string)env('LOGICSMS_QUERY_URL','https://www.logicsms.co.za/querysms.aspx'));
        if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')return ['success'=>false,'error'=>'LogicSMS query URL must use HTTPS','gateway'=>'logicsms'];
        // Prefer the exact Unique supplied on send. Keep the existing ID fallback
        // pending clarification of the documentation's returned-ID/CREATE wording.
        $identifiers=array_values(array_unique(array_filter([$clientReference,$providerMessageId],static fn($value)=>trim((string)$value)!=='')));
        if(!$identifiers)return ['success'=>false,'error'=>'A message reference is required','error_code'=>'MISSING_REFERENCE','retryable'=>false,'gateway'=>'logicsms'];
        $last=['success'=>false,'error'=>'LogicSMS status could not be resolved','gateway'=>'logicsms'];$attempts=[];
        foreach($identifiers as $identifier){
            $params=['username'=>$this->logicSmsUsername,'password'=>$this->logicSmsPassword,'Unique'=>(string)$identifier,'Extended'=>'Y'];
            foreach(['POST','GET'] as $method){
                $response=$this->queryStatus($url,$params,$method);
                $httpStatus=(int)($response['status']??200);
                $last=self::parseStatusResponse((string)($response['body']??''),(string)($response['error']??''),$httpStatus);
                $attempts[]=['reference'=>(string)$identifier,'method'=>$method,'http_status'=>$httpStatus,'outcome'=>$last['status']??$last['error_code']];
                // Keep a bounded compatibility fallback, not an automatic retry loop.
                if(!empty($last['success'])||(($last['error_code']??'')!=='MESSAGE_NOT_FOUND'&&!in_array($httpStatus,[405,501],true)))break;
            }
            $last+=['lookup_reference'=>(string)$identifier,'lookup_attempts'=>$attempts];
            if(!empty($last['success']))return $last;
            // Do not mask authentication, transport or malformed-response errors
            // by trying a different message. Fallback is only for missing records.
            if(($last['error_code']??'')!=='MESSAGE_NOT_FOUND')return $last;
        }
        return $last;
    }

    private function queryStatus(string $url,array $params,string $method):array
    {
        if($this->transport)return ($this->transport)($url,$params,$method);
        $caBundle=trim((string)env('LOGICSMS_CA_BUNDLE',''));
        if($caBundle!==''&&(!is_file($caBundle)||!is_readable($caBundle)))return ['status'=>0,'error'=>'Configured LogicSMS CA bundle is not readable'];
        $ch=curl_init();$options=[CURLOPT_URL=>$method==='GET'?$url.'?'.http_build_query($params):$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>max(2,min(10,(int)env('SMS_STATUS_QUERY_TIMEOUT_SECONDS',8))),CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true];
        if($method==='POST')$options+=[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params),CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']];
        else $options[CURLOPT_HTTPGET]=true;
        if($caBundle!=='')$options[CURLOPT_CAINFO]=$caBundle;
        curl_setopt_array($ch,$options);
        $body=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        return ['body'=>(string)$body,'error'=>$error,'status'=>$status];
    }

    public static function parseStatusResponse(string $body,string $error='',int $httpStatus=200):array{
        $failure=static fn(string $code,string $message,bool $retryable=false):array=>['success'=>false,'error'=>$message,'error_code'=>$code,'retryable'=>$retryable,'requires_investigation'=>$code==='MESSAGE_NOT_FOUND','gateway'=>'logicsms'];
        if($error!=='')return $failure('TRANSPORT_ERROR','LogicSMS status request failed in transit',true);
        if($httpStatus<200||$httpStatus>=300)return $failure(in_array($httpStatus,[401,403],true)?'AUTHENTICATION_ERROR':'HTTP_ERROR','LogicSMS HTTP '.$httpStatus,$httpStatus===429||$httpStatus>=500);
        if(strlen($body)>65536)return $failure('INVALID_RESPONSE','LogicSMS status response exceeds the safe size limit');
        $body=trim(preg_replace('/^\xEF\xBB\xBF/','',$body));
        if(preg_match('/<!DOCTYPE|<!ENTITY|<(?:html|script|body)\b/i',$body))return $failure('INVALID_RESPONSE','Invalid LogicSMS status response');
        $status='';$xml=false;
        if(str_starts_with($body,'<')){
            $previous=libxml_use_internal_errors(true);
            try{$xml=simplexml_load_string($body,'SimpleXMLElement',LIBXML_NONET);}catch(Throwable){$xml=false;}
            libxml_clear_errors();libxml_use_internal_errors($previous);
            if(!$xml)return $failure('INVALID_RESPONSE','Invalid LogicSMS status response');
        }
        if($xml){
            $nodes=$xml->xpath('//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="status"]');
            if(count($nodes?:[])>1)return $failure('INVALID_RESPONSE','Ambiguous LogicSMS status response');
            if($nodes&&isset($nodes[0]))$status=trim((string)$nodes[0]);
            $attributes=$xml->xpath('//@*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="status"]');
            if(count($attributes?:[])>1||$status!==''&&$attributes&&strcasecmp($status,trim((string)$attributes[0]))!==0)return $failure('INVALID_RESPONSE','Conflicting LogicSMS status response');
            if($status===''){
                if($attributes&&isset($attributes[0]))$status=trim((string)$attributes[0]);
            }
        }
        if($status===''){
            $status=$xml?trim(strip_tags($body)):$body;
            $status=preg_replace('/^status\s*[:=]\s*/i','',$status);
        }
        // Extended=Y can return e.g. DELIVERED 2026-09-08 20:20:53.
        // Only accept a status with a date suffix, not arbitrary error prose.
        $parsed=LogicSmsDeliveryStatus::parse($status);
        if($parsed){
            $state=LogicSmsDeliveryStatus::normalize($parsed['status']);
            return ['success'=>true]+$parsed+['is_final'=>in_array($state,['delivered','failed'],true),'timestamp_semantics'=>$parsed['provider_status_at']===null?null:($parsed['status']==='BLOCKED'?'status_transition':'provider_reported_unverified'),'gateway'=>'logicsms'];
        }
        if(preg_match('/^(?:cannot\s+find\s+message|message\s+not\s+found)[.!]?$/i',$status))return $failure('MESSAGE_NOT_FOUND','LogicSMS could not find the message. Investigate the original send reference and account; this is not a delivery outcome.');
        if(preg_match('/password|username|authentication|unauthori[sz]ed|access\s+denied/i',$status))return $failure('AUTHENTICATION_ERROR','LogicSMS rejected the status request credentials or access');
        return $failure('INVALID_RESPONSE','Invalid LogicSMS status response');
    }

    public function getCredits(): array {
        if(!$this->logicSmsUsername||!$this->logicSmsPassword)return ['success'=>false,'error'=>'LogicSMS is not configured','gateway'=>'logicsms'];
        $url=trim((string)env('LOGICSMS_CREDITS_URL','https://www.logicsms.co.za/querycredits.aspx'));
        if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')return ['success'=>false,'error'=>'LogicSMS credits URL must use HTTPS','gateway'=>'logicsms'];
        $params=['username'=>$this->logicSmsUsername,'password'=>$this->logicSmsPassword];
        if($this->transport){$response=($this->transport)($url,$params);return self::parseCreditsResponse((string)($response['body']??''),(string)($response['error']??''),(int)($response['status']??200));}
        $ch=curl_init();curl_setopt_array($ch,[CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params),CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
        $caBundle=trim((string)env('LOGICSMS_CA_BUNDLE',''));if($caBundle!==''){if(!is_file($caBundle)||!is_readable($caBundle)){curl_close($ch);return ['success'=>false,'error'=>'Configured LogicSMS CA bundle is not readable','gateway'=>'logicsms'];}curl_setopt($ch,CURLOPT_CAINFO,$caBundle);}
        $body=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return self::parseCreditsResponse((string)$body,$error,$status);
    }

    public static function parseCreditsResponse(string $body,string $error='',int $httpStatus=200):array{
        if($error!=='')return ['success'=>false,'error'=>$error,'gateway'=>'logicsms'];
        if($httpStatus<200||$httpStatus>=300)return ['success'=>false,'error'=>'LogicSMS HTTP '.$httpStatus,'gateway'=>'logicsms'];
        $body=trim(preg_replace('/^\xEF\xBB\xBF/','',$body));
        $plain=$body;
        // querycredits.aspx can return the balance BEFORE its legacy ASP.NET
        // QueryCredits HTML shell. Do not extract arbitrary numbers from HTML:
        // a login, challenge or error page must never become an account balance.
        if(preg_match('/^(-?\d+(?:\.\d+)?)\s*(?=<!DOCTYPE\s+HTML\b|<HTML\b)/i',$body,$balance)){
            $html=substr($body,strlen($balance[0]));
            $title='';$visible='';$hasBody=false;
            if(preg_match('/<title\b[^>]*>(.*?)<\/title\s*>/is',$html,$match))$title=trim(html_entity_decode(strip_tags($match[1]),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            if(preg_match('/<body\b[^>]*>(.*?)<\/body\s*>/is',$html,$match)){$hasBody=true;$visible=trim(html_entity_decode(strip_tags($match[1]),ENT_QUOTES|ENT_HTML5,'UTF-8'));}
            if(strcasecmp($title,'QueryCredits')===0&&$visible===''&&$hasBody&&!preg_match('/<(?:script|iframe|object)\b/i',$html))$plain=$balance[1];
        }
        $xml=false;
        if(str_starts_with($body,'<')&&!preg_match('/<!DOCTYPE|<!ENTITY/i',$body)&&function_exists('simplexml_load_string')){
            $previous=libxml_use_internal_errors(true);
            try{$xml=simplexml_load_string($body,'SimpleXMLElement',LIBXML_NONET);}catch(Throwable){$xml=false;}
            libxml_clear_errors();libxml_use_internal_errors($previous);
        }
        if($xml){
            $nodes=$xml->xpath('//*[translate(local-name(),"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="credits" or translate(local-name(),"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="balance" or translate(local-name(),"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="value"]');
            if(count($nodes?:[])===1)$plain=trim((string)$nodes[0]);
            elseif(!$nodes&&in_array(strtolower($xml->getName()),['string','decimal','double'],true)&&count($xml->children())===0)$plain=trim((string)$xml);
        }
        if(!preg_match('/^-?\d+(?:\.\d+)?$/',$plain)||!is_finite((float)$plain))return ['success'=>false,'error'=>'Invalid LogicSMS credits response','gateway'=>'logicsms'];
        return ['success'=>true,'credits'=>(float)$plain,'gateway'=>'logicsms'];
    }
    
    /**
     * Get current gateway name
     */
    public function getGateway(): string {
        return 'logicsms';
    }
    
    /**
     * Format phone number to E.164
     */
    private function formatPhone(string $phone): string {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (substr($phone, 0, 1) === '0') {
            $phone = '27' . substr($phone, 1);
        }
        return $phone;
    }
}
