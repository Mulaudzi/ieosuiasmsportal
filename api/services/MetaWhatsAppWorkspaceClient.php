<?php
declare(strict_types=1);
require_once __DIR__.'/../domain/MetaWhatsAppConnectConfig.php';

/** Scoped Graph transport. No redirects, raw provider errors, or automatic POST retries. */
final class MetaWhatsAppWorkspaceClient
{
    public function __construct(private MetaWhatsAppConnectConfig $config, private $transport = null) {}

    public function request(string $method, string $path, string $token, array $data = []): array
    {
        if (!in_array($method, ['GET','POST'], true) || !preg_match('~^[0-9]{5,32}/(?:message_templates|messages|subscribed_apps)$~D', $path)) {
            throw new LogicException('Invalid WhatsApp operation');
        }
        $query = ['appsecret_proof'=>hash_hmac('sha256',$token,$this->config->appSecret)];
        if ($method === 'GET') $query += $data;
        $url = 'https://graph.facebook.com/'.$this->config->graphVersion.'/'.$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $body = $method === 'POST' ? json_encode($data,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null;
        try {
            if ($this->transport !== null) $response = ($this->transport)($method,$url,$token,$body);
            else {
                $curl = curl_init($url); $output = '';
                if ($curl === false) throw new RuntimeException();
                try {
                    curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,
                        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                        CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,
                        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json','Content-Type: application/json'],
                        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$output):int {
                            if(strlen($output)+strlen($chunk)>1048576)return 0;
                            $output.=$chunk;return strlen($chunk);
                        }]);
                    if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
                    curl_exec($curl);
                    $response=['status'=>(int)curl_getinfo($curl,CURLINFO_HTTP_CODE),'errno'=>curl_errno($curl),'body'=>$output];
                } finally { curl_close($curl); }
            }
            $status = (int)($response['status'] ?? 0);
            $raw = (string)($response['body'] ?? '');
            if (($response['errno'] ?? 0) !== 0 || strlen($raw)>1048576) throw new RuntimeException();
            $parsed=json_decode($raw,true,48,JSON_THROW_ON_ERROR);
            if (!is_array($parsed)) throw new RuntimeException();
            if ($status>=200 && $status<300 && !isset($parsed['error'])) return ['outcome'=>'accepted','data'=>$parsed];
            if ($status>=400 && $status<500 && is_int($parsed['error']['code']??null)) {
                return ['outcome'=>'rejected','provider_code'=>$parsed['error']['code']];
            }
        } catch (Throwable) { /* Credentials, URLs and provider error bodies must never reach logs or clients. */ }
        return ['outcome'=>'uncertain'];
    }
}
