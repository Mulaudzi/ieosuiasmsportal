<?php
declare(strict_types=1);
require_once __DIR__.'/../services/SmsService.php';

/** Revalidates saved, exact-message provider response metadata, not user claims. */
final class SmsAcknowledgementEvidence
{
    public static function fromLog(array $log):array
    {
        if(($log['service']??null)!=='sms-provider'||($log['event']??null)!=='acknowledgement')throw new DomainException('Not an original provider acknowledgement');
        $context=json_decode((string)($log['context_json']??''),true,32,JSON_THROW_ON_ERROR);$d=$context['acknowledgement']??[];
        foreach(['campaign_id','sms_message_id','user_id'] as $field)if((int)($context[$field]??0)<1||(int)$context[$field] !== (int)($log[$field]??0))throw new DomainException('Acknowledgement ownership mismatch');
        if(($d['http_status']??null)!==200||($d['xml_parsed']??null)!==true||($d['xml_root']??null)!=='Message'||($d['xml_namespace_count']??null)!==0||($d['xml_error_count']??null)!==0||($d['fields']['Status']['value']??null)!=='CREATE'||($d['rejection_reason']??null)!=='UNRECOGNIZED_PROVIDER_STATUS'||!preg_match('/^[a-f0-9]{64}$/D',(string)($d['response_sha256']??'')))throw new DomainException('Complete saved CREATE acknowledgement required');
        $values=[];
        foreach(['Id','Status','MessageCost','Parts','IdList'] as $field){
            if(($d['direct_field_counts'][$field]??null)!==1||($d['local_field_counts'][$field]??null)!==1)throw new DomainException('Ambiguous saved acknowledgement');
            $details=$d['fields'][$field]??[];$value=$details['value']??'';
            if(($details['has_child_elements']??true)!==false||($details['bytes']??null)!==strlen($value)||!hash_equals((string)($details['sha256']??''),hash('sha256',$value)))throw new DomainException('Saved field fingerprint mismatch');
            $values[$field]=$value;
        }
        $xml='<Message>';foreach($values as $field=>$value)$xml.='<'.$field.'>'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</'.$field.'>';$xml.='</Message>';
        $result=SmsService::parseResponse($xml);
        if(empty($result['success'])||$result['status']!=='CREATE')throw new DomainException('Saved acknowledgement is not accepted');
        return ['source'=>'provider_acknowledgement','user_id'=>(int)$log['user_id'],'campaign_id'=>(int)$log['campaign_id'],'status'=>'CREATE','original_reference'=>'portal-message-'.(int)$log['sms_message_id'],'provider_message_id'=>$result['message_id'],'acknowledgement_log_id'=>(int)$log['id'],'response_sha256'=>$d['response_sha256']];
    }
}
