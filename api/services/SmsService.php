<?php
/**
 * LogicSMS gateway service.
 */

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
    
    /**
     * Send via LogicSMS (fallback)
     */
    private function sendViaLogicSms(string $phone, string $message, string $senderId,?string $clientReference=null): array {
        if(!filter_var($this->logicSmsUrl,FILTER_VALIDATE_URL)||strtolower((string)parse_url($this->logicSmsUrl,PHP_URL_SCHEME))!=='https')return ['success'=>false,'error'=>'LogicSMS API URL must use HTTPS','gateway'=>'logicsms'];
        $params = [
            'username' => $this->logicSmsUsername,
            'password' => $this->logicSmsPassword,
            'mobile' => $this->formatPhone($phone),
            'message' => $message,
            'Originator' => $senderId,
            'Unique' => preg_replace('/[^A-Za-z0-9_-]/','',(string)($clientReference?:bin2hex(random_bytes(12)))),
            'DCheck' => 'Y',
        ];
        
        if($this->transport){$response=($this->transport)($this->logicSmsUrl,$params);return self::parseResponse((string)($response['body']??''),(string)($response['error']??''),(int)($response['status']??200));}
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->logicSmsUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $caBundle=trim((string)env('LOGICSMS_CA_BUNDLE',''));
        if($caBundle!==''){
            if(!is_file($caBundle)||!is_readable($caBundle)){curl_close($ch);return ['success'=>false,'error'=>'Configured LogicSMS CA bundle is not readable','gateway'=>'logicsms'];}
            curl_setopt($ch,CURLOPT_CAINFO,$caBundle);
        }
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        return self::parseResponse((string)$response,$error,$status);
    }

    public static function parseResponse(string $response,string $error='',int $httpStatus=200):array {
        if($error!=='')return ['success'=>false,'error'=>$error,'gateway'=>'logicsms'];
        if($httpStatus<200||$httpStatus>=300)return ['success'=>false,'error'=>'LogicSMS HTTP '.$httpStatus,'gateway'=>'logicsms'];
        $xml = @simplexml_load_string($response);
        if ($xml && isset($xml->Id)) {
            return [
                'success' => true,
                'message_id' => (string) $xml->Id,
                'status' => (string) ($xml->Status ?? 'Sent'),
                'provider_cost' => isset($xml->MessageCost)?(float)$xml->MessageCost:null,
                'parts' => isset($xml->Parts)?(int)$xml->Parts:null,
                'id_list' => isset($xml->IdList)?(string)$xml->IdList:null,
                'gateway' => 'logicsms',
            ];
        }
        
        return ['success' => false, 'error' => 'Invalid LogicSMS response', 'gateway' => 'logicsms'];
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
        $identifiers=array_values(array_unique(array_filter([$providerMessageId,$clientReference],static fn($value)=>trim((string)$value)!=='')));
        $last=['success'=>false,'error'=>'LogicSMS status could not be resolved','gateway'=>'logicsms'];
        foreach($identifiers as $identifier){
            $params=['username'=>$this->logicSmsUsername,'password'=>$this->logicSmsPassword,'Unique'=>(string)$identifier,'Extended'=>'Y'];
            if($this->transport){$response=($this->transport)($url,$params);$last=self::parseStatusResponse((string)($response['body']??''),(string)($response['error']??''),(int)($response['status']??200));}
            else{
                $ch=curl_init();curl_setopt_array($ch,[CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params),CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
                $caBundle=trim((string)env('LOGICSMS_CA_BUNDLE',''));if($caBundle!==''){if(!is_file($caBundle)||!is_readable($caBundle)){curl_close($ch);return ['success'=>false,'error'=>'Configured LogicSMS CA bundle is not readable','gateway'=>'logicsms'];}curl_setopt($ch,CURLOPT_CAINFO,$caBundle);}
                $body=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$last=self::parseStatusResponse((string)$body,$error,$status);
                // The vendor documentation also demonstrates querysms.aspx as a
                // query-string request. Some LogicSMS accounts resolve status only
                // through that handler, so retry a failed POST as HTTPS GET.
                if(empty($last['success'])){
                    $ch=curl_init();curl_setopt_array($ch,[CURLOPT_URL=>$url.'?'.http_build_query($params),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_HTTPGET=>true]);
                    if($caBundle!=='')curl_setopt($ch,CURLOPT_CAINFO,$caBundle);
                    $body=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$last=self::parseStatusResponse((string)$body,$error,$status);
                }
            }
            if(!empty($last['success']))return $last;
        }
        return $last;
    }

    public static function parseStatusResponse(string $body,string $error='',int $httpStatus=200):array{
        if($error!=='')return ['success'=>false,'error'=>$error,'gateway'=>'logicsms'];
        if($httpStatus<200||$httpStatus>=300)return ['success'=>false,'error'=>'LogicSMS HTTP '.$httpStatus,'gateway'=>'logicsms'];
        $status='';$xml=@simplexml_load_string($body);
        if($xml){
            $nodes=$xml->xpath('//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="status"]');
            if($nodes&&isset($nodes[0]))$status=trim((string)$nodes[0]);
            if($status===''){
                $attributes=$xml->xpath('//@*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="status"]');
                if($attributes&&isset($attributes[0]))$status=trim((string)$attributes[0]);
            }
        }
        if($status===''){
            $plain=trim(strip_tags($body));
            if(preg_match('/(?:status\s*[:=]\s*)?\b(DELIVRD|DELIVERED|DELIVERY_SUCCESS|SUCCESS|UNDELIV|UNDELIVERED|FAILED|REJECTED|EXPIRED|ERROR|CREATE|CREATED|QUEUED|SENT|SUBMITTED|ACCEPTED)\b/i',$plain,$match))$status=$match[1];
            elseif(strlen($plain)<=80)$status=$plain;
        }
        if($status===''||strlen($status)>80)return ['success'=>false,'error'=>'Invalid LogicSMS status response','gateway'=>'logicsms'];
        if(preg_match('/cannot\s+find|not\s+found|invalid/i',$status))return ['success'=>false,'error'=>$status,'gateway'=>'logicsms'];
        return ['success'=>true,'status'=>$status,'gateway'=>'logicsms'];
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
        $plain=trim(strip_tags($body));
        $xml=false;
        if(str_starts_with(ltrim($body),'<')&&function_exists('simplexml_load_string')){
            $previous=libxml_use_internal_errors(true);
            try{$xml=simplexml_load_string($body);}catch(Throwable){$xml=false;}
            libxml_clear_errors();libxml_use_internal_errors($previous);
        }
        if($xml){foreach(['Credits','Balance','Value'] as $key){if(isset($xml->{$key})){$plain=trim((string)$xml->{$key});break;}}}
        if(!preg_match('/^-?\d+(?:\.\d+)?$/',$plain))return ['success'=>false,'error'=>'Invalid LogicSMS credits response','gateway'=>'logicsms'];
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
