<?php
declare(strict_types=1);
final class EmailAwsSignature {
    public static function canonical(string $method,string $path,string $audience,string $time,string $nonce,string $body):string {
        if($method!=='POST'||!preg_match('~^/[a-z0-9/_.-]{1,150}$~D',$path)||str_contains($path,'..')||strlen($body)>400000)throw new RuntimeException('Invalid signed service request',400);
        return implode("\n",['IEOSUIA-EMAIL-1',$audience,$method,$path,$time,$nonce,hash('sha256',$body)]);
    }
    public static function sign(string $key,string $audience,string $path,string $body,?int $now=null):array {
        $time=(string)($now??time());$nonce=bin2hex(random_bytes(20));
        return ['Content-Type: application/json','X-Email-Key: v1','X-Email-Time: '.$time,'X-Email-Nonce: '.$nonce,'X-Email-Signature: '.hash_hmac('sha256',self::canonical('POST',$path,$audience,$time,$nonce,$body),$key)];
    }
    public static function verify(PDO $pdo,array $keys,string $audience,string $path,string $body,array $headers,?int $now=null):void {
        $headers=array_change_key_case($headers,CASE_LOWER);$kid=$headers['x-email-key']??'';$stamp=$headers['x-email-time']??'';$nonce=$headers['x-email-nonce']??'';$signature=$headers['x-email-signature']??'';$now??=time();
        if(!isset($keys[$kid])||strlen($keys[$kid])<32||!preg_match('/^[0-9]{10}$/D',$stamp)||abs($now-(int)$stamp)>120||!preg_match('/^[a-f0-9]{40}$/D',$nonce)||!preg_match('/^[a-f0-9]{64}$/D',$signature))throw new RuntimeException('Service authentication failed',401);
        $expected=hash_hmac('sha256',self::canonical('POST',$path,$audience,$stamp,$nonce,$body),$keys[$kid]);
        if(!hash_equals($expected,$signature))throw new RuntimeException('Service authentication failed',401);
        try{$q=$pdo->prepare('INSERT INTO email_aws_nonces(key_id,nonce_hash,expires_at)VALUES(?,?,?)');$q->execute([$kid,hash('sha256',$nonce),$now+600]);}
        catch(PDOException $e){if(in_array((string)$e->getCode(),['23000','23505'],true))throw new RuntimeException('Replayed service request',409);throw $e;}
        // Bound replay-storage retention without a public cleanup endpoint or a table-wide sweep.
        // Only authenticated traffic may remove expired entries; timestamps already reject old requests.
        $expired=$pdo->prepare('SELECT key_id,nonce_hash FROM email_aws_nonces WHERE expires_at<? ORDER BY expires_at LIMIT 25');$expired->execute([$now]);$rows=$expired->fetchAll(PDO::FETCH_ASSOC);
        if($rows){$parts=[];$args=[$now];foreach($rows as$row){$parts[]='(key_id=? AND nonce_hash=?)';array_push($args,$row['key_id'],$row['nonce_hash']);}$delete=$pdo->prepare('DELETE FROM email_aws_nonces WHERE expires_at<? AND ('.implode(' OR ',$parts).')');$delete->execute($args);}
    }
}
