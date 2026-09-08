<?php

// Read-only production configuration gate. Never prints configured values.
$path = dirname(__DIR__) . '/.env';
if (!is_file($path) || !is_readable($path)) {
    fwrite(STDERR, "FAIL api/.env is missing or unreadable\n");
    exit(1);
}

$values = [];
foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$key,$value] = explode('=', $line, 2);
    $values[trim($key)] = trim(trim($value), "\"'");
}

$failures=[];$warnings=[];
$required=['DB_HOST','DB_DATABASE','DB_USERNAME','DB_PASSWORD','JWT_SECRET','FRONTEND_URL','AUTH_ISSUER','AUTH_CLIENT_ID','AUTH_REDIRECT_URI','LOGICSMS_USERNAME','LOGICSMS_PASSWORD','LOGICSMS_WEBHOOK_SECRET','PAYOS_PUBLIC_KEY','PAYOS_API_SECRET','PAYOS_CALLBACK_SECRET','SMTP_HOST','SMTP_USER','SMTP_PASS','SMTP_FROM_EMAIL'];
foreach($required as $key)if(trim((string)($values[$key]??''))==='')$failures[]="{$key} is missing";
foreach(['JWT_SECRET','LOGICSMS_WEBHOOK_SECRET','PAYOS_API_SECRET','PAYOS_CALLBACK_SECRET'] as $key){
    $length=strlen((string)($values[$key]??''));if($length>0&&$length<32)$failures[]="{$key} must contain at least 32 characters";
}
if(strtolower((string)($values['APP_ENV']??''))!=='production')$failures[]='APP_ENV must be production';
if(filter_var((string)($values['APP_DEBUG']??'false'),FILTER_VALIDATE_BOOLEAN))$failures[]='APP_DEBUG must be false';
if(strtolower((string)($values['SMS_GATEWAY']??''))!=='logicsms')$failures[]='SMS_GATEWAY must be logicsms';
foreach(['FRONTEND_URL','AUTH_ISSUER','AUTH_REDIRECT_URI','LOGICSMS_API_URL','LOGICSMS_QUERY_URL','LOGICSMS_CREDITS_URL','PAYOS_BASE_URL'] as $key){
    $url=(string)($values[$key]??'');if($url!==''&&strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')$failures[]="{$key} must use HTTPS";
}
if((float)($values['SMS_PRICE_PER_SEGMENT']??0)<=0)$failures[]='SMS_PRICE_PER_SEGMENT must be positive';
if((int)($values['SMS_MAX_RECIPIENTS_PER_CAMPAIGN']??0)<1)$failures[]='SMS_MAX_RECIPIENTS_PER_CAMPAIGN must be positive';
if(trim((string)($values['PAYOS_RETURN_URL']??''))==='')$warnings[]='PAYOS_RETURN_URL is using the application default';
if(trim((string)($values['PAYOS_CANCEL_URL']??''))==='')$warnings[]='PAYOS_CANCEL_URL is using the application default';

foreach($warnings as $warning)echo "WARN {$warning}\n";
if($failures){foreach($failures as $failure)fwrite(STDERR,"FAIL {$failure}\n");fwrite(STDERR,count($failures)." readiness check(s) failed; no values were printed.\n");exit(1);}
echo "PASS production configuration is complete; no values were printed.\n";
