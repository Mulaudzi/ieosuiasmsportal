<?php

final class OperationalLogger
{
    private static ?bool $available=null;

    public static function log(string $level,string $service,string $event,string $message,array $context=[]):void
    {
        $message=self::redact($message);
        error_log("[{$service}] {$event}: {$message}");
        if(!self::available())return;
        try{
            $stmt=db()->prepare('INSERT INTO operational_logs(level,service,event,message,campaign_id,sms_message_id,user_id,context_json,created_at) VALUES(?,?,?,?,?,?,?,?,NOW())');
            $stmt->execute([
                in_array($level,['debug','info','warning','error','critical'],true)?$level:'info',
                substr($service,0,60),substr($event,0,100),substr($message,0,500),
                $context['campaign_id']??null,$context['sms_message_id']??null,$context['user_id']??null,
                json_encode(self::sanitizeContext($context),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            ]);
        }catch(Throwable){self::$available=false;}
    }

    public static function startRun(string $service,string $instance,array $metadata=[]):?int
    {
        if(!self::available('service_job_runs'))return null;
        try{$stmt=db()->prepare("INSERT INTO service_job_runs(service_name,instance_id,status,metadata_json,started_at) VALUES(?,?,'running',?,NOW())");$stmt->execute([$service,$instance,json_encode(self::sanitizeContext($metadata))]);return (int)db()->lastInsertId();}catch(Throwable){return null;}
    }

    public static function finishRun(?int $id,string $status,int $processed,int $failed,string $message=''):void
    {
        if(!$id)return;
        try{$stmt=db()->prepare('UPDATE service_job_runs SET status=?,processed_count=?,failed_count=?,message=?,completed_at=NOW(),duration_ms=TIMESTAMPDIFF(MICROSECOND,started_at,NOW()) DIV 1000 WHERE id=?');$stmt->execute([$status,$processed,$failed,substr(self::redact($message),0,500),$id]);}catch(Throwable){}
    }

    private static function available(string $table='operational_logs'):bool
    {
        if($table==='operational_logs'&&self::$available!==null)return self::$available;
        try{$stmt=db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$stmt->execute([$table]);$value=(bool)$stmt->fetchColumn();if($table==='operational_logs')self::$available=$value;return $value;}catch(Throwable){return false;}
    }

    private static function redact(string $value):string
    {
        return preg_replace('/(?i)(password|secret|token|api[_ -]?key)(\s*[=:]\s*)[^\s,;]+/','$1$2[REDACTED]',$value)??'Operational error';
    }

    private static function sanitizeContext(array $context):array
    {
        foreach($context as $key=>$value){if(preg_match('/password|secret|token|key/i',(string)$key))$context[$key]='[REDACTED]';elseif(is_string($value))$context[$key]=substr(self::redact($value),0,500);}
        return $context;
    }
}
