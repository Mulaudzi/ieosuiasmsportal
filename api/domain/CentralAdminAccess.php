<?php
declare(strict_types=1);

/** Back-channel administrator assignment contract. No browser claims or email-only promotions. */
final class CentralAdminAccess
{
    public static function managed(array $profile): bool { return ($profile['admin_assignment']??null)==='central-v1'; }
    public static function enabled(array $config): bool { return filter_var($config['enabled']??false,FILTER_VALIDATE_BOOL); }
    public static function profile(array $profile,string $app,array $roles,array $config): void {
        if(($profile['account_type']??'')!=='admin'||($profile['application']??'')!==$app||($profile['email_verified']??false)!==true
            ||!is_string($profile['sub']??null)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$profile['sub'])
            ||!is_string($profile['email']??null)||!filter_var($profile['email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Administrator identity is not eligible.',403);
        if(self::managed($profile) && (!self::enabled($config)||!in_array($profile['access_level']??null,$roles,true)||(int)($profile['token_version']??0)<1))throw new RuntimeException('Managed administrator access is not enabled or the role is unsupported.',403);
        if(self::enabled($config)&&(int)($profile['token_version']??0)<1)throw new RuntimeException('A current administrator identity is required.',403);
    }
    public static function verify(string $uuid,int $version,string $localRole,bool $managed,string $app,array $config,?callable $transport=null): void {
        if(!self::enabled($config)){if($managed)throw new RuntimeException('Managed administrator verification is unavailable.',503);return;}
        if($version<1||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$uuid))throw new RuntimeException('Sign in again to verify administrator access.',401);
        $issuer=rtrim((string)($config['issuer']??''),'/');$id=(string)($config['client_id']??'');$secret=(string)($config['client_secret']??'');
        if($issuer!=='https://auth.ieosuia.com'||$id===''||strlen($secret)<32)throw new RuntimeException('Administrator verification configuration is incomplete.',503);
        $input=['client_id'=>$id,'client_secret'=>$secret,'subject'=>$uuid];
        if($transport)$result=$transport($input);
        else{
            $curl=curl_init($issuer.'/oauth/admin-access');curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($input,'','&',PHP_QUERY_RFC3986),CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
            $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
            if($status!==200||!is_string($raw))throw new RuntimeException('Administrator verification is temporarily unavailable.',503);
            $result=json_decode($raw,true);
        }
        if(!is_array($result)||($result['active']??false)!==true||($result['sub']??null)!==$uuid||($result['application']??null)!==$app||(int)($result['token_version']??0)!==$version)throw new RuntimeException('Administrator access changed. Sign in again.',401);
        if(($managed||($result['managed']??false)===true)&&($result['access_level']??null)!==$localRole)throw new RuntimeException('Administrator role changed. Sign in again.',401);
    }
    /** QR and Invoices have one platform-admin tier, separate from customer users. */
    public static function synchronizePdo(PDO $pdo,array $profile,string $app,array $config): array {
        if(!in_array($app,['qr','invoice'],true))throw new RuntimeException('Unsupported administrator adapter.');
        self::profile($profile,$app,['admin'],$config);
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $pdo->beginTransaction();
        try{
            $s=$pdo->prepare('SELECT * FROM admin_users WHERE identity_uuid=? OR LOWER(email)=?'.$lock);$s->execute([$profile['sub'],strtolower($profile['email'])]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)>1)throw new RuntimeException('Administrator identity conflict.',403);
            if(!$rows){
                if(!self::managed($profile))throw new RuntimeException('Local administrator access is missing.',403);
                $name=mb_substr((string)($profile['name']??$profile['email']),0,100);
                $sql=$app==='qr'?'INSERT INTO admin_users(identity_uuid,email,name,password,password_step2,password_step3,is_active) VALUES(?,?,?,NULL,NULL,NULL,1)':"INSERT INTO admin_users(identity_uuid,email,name,password,status) VALUES(?,?,?,NULL,'active')";
                $pdo->prepare($sql)->execute([$profile['sub'],strtolower($profile['email']),$name]);
                $s=$pdo->prepare('SELECT * FROM admin_users WHERE id=?');$s->execute([(int)$pdo->lastInsertId()]);$a=$s->fetch(PDO::FETCH_ASSOC);
            }else{
                $a=$rows[0];
                if(strtolower($a['email'])!==strtolower($profile['email'])||empty($a['identity_uuid'])||!hash_equals($a['identity_uuid'],$profile['sub']))throw new RuntimeException('Administrator identity conflict.',403);
                if($app==='qr'?(int)$a['is_active']!==1:$a['status']!=='active')throw new RuntimeException('Local administrator access is inactive.',403);
            }
            $pdo->commit();return $a;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
