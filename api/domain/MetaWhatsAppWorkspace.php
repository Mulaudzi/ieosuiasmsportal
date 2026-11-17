<?php
declare(strict_types=1);
require_once __DIR__.'/../services/MetaWhatsAppWorkspaceClient.php';

/** Single-recipient customer workspace. Every operation is bound to an owned connection. */
final class MetaWhatsAppWorkspace
{
    public function __construct(private PDO $pdo,private MetaWhatsAppConnectConfig $config,
        private MetaWhatsAppWorkspaceClient $meta,private bool $sendingEnabled=false,private $clock=null){}
    private function now():int{return $this->clock===null?time():($this->clock)();}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->pdo->prepare($sql);$s->execute($args);return $s;}
    public function assertReady():void{$this->pdo->query('SELECT owner_id FROM whatsapp_workspace_operations WHERE 1=0');}
    private function connection(int $owner,string $phone):array {
        if(!preg_match('/^[0-9]{5,32}$/D',$phone))throw new MetaWhatsAppConnectException('Choose a connected business number.',422);
        $row=$this->query("SELECT * FROM whatsapp_customer_connections WHERE owner_id=? AND phone_number_id=? AND status='authorized'",[$owner,$phone])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new MetaWhatsAppConnectException('This WhatsApp connection is unavailable.',404);
        if($row['token_expires_at']!==null&&(int)$row['token_expires_at']<=$this->now())throw new MetaWhatsAppConnectException('Reconnect this WhatsApp Business number.',409);
        $metadata=json_decode($row['metadata_json'],true,16,JSON_THROW_ON_ERROR);
        if(!preg_match('/^27[1-9][0-9]{8}$/D',preg_replace('/\D/','',$metadata['display_phone_number']??''))) {
            throw new MetaWhatsAppConnectException('The pilot supports South African business numbers only.',422);
        }
        $row['token']=$this->config->unseal((string)$row['token_cipher'],'connection:'.$owner.':'.$row['waba_id'].':'.$phone)['token']??null;
        if(!is_string($row['token'])||$row['token']==='')throw new MetaWhatsAppConnectException('Reconnect this WhatsApp Business number.',409);
        return $row;
    }
    public static function templateSupport(array $template):array {
        $body=null;$supported=true;
        foreach(($template['components']??[])as $component){
            $type=$component['type']??'';
            if($type==='BODY')$body=$component['text']??'';
            elseif($type==='HEADER')$supported=$supported&&($component['format']??'')==='TEXT'&&!str_contains($component['text']??'','{{');
            elseif($type!=='FOOTER')$supported=false;
        }
        if(!is_string($body))$supported=false;
        preg_match_all('/\{\{([^{}]+)\}\}/',$body??'',$matches);
        $variables=array_values(array_unique($matches[1]));sort($variables,SORT_NUMERIC);
        if(count($variables)>10||($variables&&$variables!==array_map('strval',range(1,count($variables)))))$supported=false;
        return ['supported'=>$supported,'parameter_count'=>count($variables)];
    }
    private function templatesFor(array $connection):array {
        $templates=[];$after=null;$seen=[];
        for($page=0;$page<10;$page++){
            $query=['fields'=>'id,name,status,language,category,components','limit'=>100];if($after!==null)$query['after']=$after;
            $result=$this->meta->request('GET',$connection['waba_id'].'/message_templates',$connection['token'],$query);
            if($result['outcome']!=='accepted'||!is_array($result['data']['data']??null))throw new MetaWhatsAppConnectException('Could not load templates from Meta. Check the connection and retry.',502);
            foreach($result['data']['data']as $item){
                if(!is_array($item)||!is_string($item['name']??null)||!is_string($item['language']??null))continue;
                $templates[]=['id'=>(string)($item['id']??''),'name'=>$item['name'],'language'=>$item['language'],
                    'status'=>(string)($item['status']??'UNKNOWN'),'category'=>(string)($item['category']??''),'components'=>$item['components']??[]]+self::templateSupport($item);
            }
            if(empty($result['data']['paging']['next']))return $templates;
            $after=$result['data']['paging']['cursors']['after']??null;
            if(!is_string($after)||strlen($after)>4096||isset($seen[$after]))throw new MetaWhatsAppConnectException('Meta returned incomplete template results. Please retry.',502);
            $seen[$after]=true;
        }
        throw new MetaWhatsAppConnectException('This account has more templates than the pilot supports. Contact support.',422);
    }
    public function templates(int $owner,string $phone):array{return ['templates'=>$this->templatesFor($this->connection($owner,$phone))];}
    public function status(int $owner):array {
        $operations=[];
        foreach($this->query('SELECT operation_key,kind,phone_number_id,waba_id,state,provider_id,request_json,result_json,created_at FROM whatsapp_workspace_operations WHERE owner_id=? ORDER BY created_at DESC,operation_key DESC LIMIT 50',[$owner])->fetchAll(PDO::FETCH_ASSOC)as $row){
            $request=json_decode($row['request_json'],true,32,JSON_THROW_ON_ERROR);$state=$row['state'];
            if($state==='submitting')$state='uncertain';
            $events=[];
            if($row['kind']==='send'&&$row['provider_id']){
                $events=$this->query('SELECT status,provider_timestamp FROM whatsapp_status_events WHERE waba_id=? AND phone_number_id=? AND provider_message_id=? ORDER BY CAST(provider_timestamp AS UNSIGNED) DESC',[$row['waba_id'],$row['phone_number_id'],$row['provider_id']])->fetchAll(PDO::FETCH_ASSOC);
                foreach(['read','delivered','failed','sent']as $eventState)if(in_array($eventState,array_column($events,'status'),true)){$state=$eventState;break;}
            }
            $operations[]=['key'=>$row['operation_key'],'kind'=>$row['kind'],'phone_number_id'=>$row['phone_number_id'],'state'=>$state,
                'provider_id'=>$row['provider_id'],'created_at'=>(int)$row['created_at'],'recipient'=>$request['recipient']??null,
                'template'=>$request['template']??($request['name']??null),'events'=>$events];
        }
        return ['available'=>true,'sending_enabled'=>$this->sendingEnabled,'operations'=>$operations];
    }
    private function execute(int $owner,array $connection,string $kind,string $key,array $request,string $path,array $payload):array {
        if(!preg_match('/^[A-Za-z0-9_-]{20,80}$/D',$key))throw new MetaWhatsAppConnectException('Refresh the form before submitting.',422);
        $json=json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$hash=hash('sha256',$kind.'|'.$connection['phone_number_id'].'|'.$json);
        $existing=$this->query('SELECT request_sha256,state,result_json FROM whatsapp_workspace_operations WHERE owner_id=? AND operation_key=?',[$owner,$key])->fetch(PDO::FETCH_ASSOC);
        if($existing){
            if(!hash_equals($existing['request_sha256'],$hash))throw new MetaWhatsAppConnectException('This submission key was already used for different content.',409);
            return ['operation_key'=>$key,'outcome'=>$existing['state']==='submitting'?'uncertain':$existing['state'],'replayed'=>true];
        }
        // Serialize per owner and persist intent BEFORE any external mutation.
        $this->pdo->beginTransaction();
        try{
            $lock=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $user=$this->query('SELECT is_active FROM users WHERE id=?'.$lock,[$owner])->fetchColumn();
            if(!$user)throw new MetaWhatsAppConnectException('This account is unavailable.',403);
            if((int)$this->query('SELECT COUNT(*) FROM whatsapp_workspace_operations WHERE owner_id=? AND created_at>?',[$owner,$this->now()-3600])->fetchColumn()>=30)throw new MetaWhatsAppConnectException('The pilot allows 30 operations per hour. Please try later.',429);
            $this->connection($owner,$connection['phone_number_id']);
            $this->query("INSERT INTO whatsapp_workspace_operations(owner_id,operation_key,request_sha256,kind,waba_id,phone_number_id,state,request_json,created_at,updated_at)VALUES(?,?,?,?,?,?,'submitting',?,?,?)",[$owner,$key,$hash,$kind,$connection['waba_id'],$connection['phone_number_id'],$json,$this->now(),$this->now()]);
            $this->pdo->commit();
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            if($e instanceof PDOException&&(string)$e->getCode()==='23000')return $this->replay($owner,$key,$hash);
            throw $e;
        }
        $result=$this->meta->request('POST',$path,$connection['token'],$payload);$provider=null;
        if($result['outcome']==='accepted'){
            if($kind==='send'){
                $provider=$result['data']['messages'][0]['id']??null;
                if(($result['data']['messaging_product']??'')!=='whatsapp'||count($result['data']['messages']??[])!==1||!is_string($provider)||!preg_match('/^wamid\.[A-Za-z0-9+\/_=.-]{1,1024}$/D',$provider))$result=['outcome'=>'uncertain'];
            }elseif($kind==='template'){
                $provider=$result['data']['id']??null;if(!is_string($provider)||!preg_match('/^[0-9]{5,32}$/D',$provider))$result=['outcome'=>'uncertain'];
            }elseif(($result['data']['success']??false)!==true)$result=['outcome'=>'uncertain'];
        }
        if($result['outcome']!=='accepted')$provider=null;
        $safe=['outcome'=>$result['outcome'],'provider_code'=>$result['provider_code']??null];
        $this->query('UPDATE whatsapp_workspace_operations SET state=?,provider_id=?,result_json=?,updated_at=? WHERE owner_id=? AND operation_key=?',[$result['outcome'],$provider,json_encode($safe,JSON_THROW_ON_ERROR),$this->now(),$owner,$key]);
        return ['operation_key'=>$key,'outcome'=>$result['outcome'],'provider_id'=>$provider,'replayed'=>false];
    }
    private function replay(int $owner,string $key,string $hash):array {
        $row=$this->query('SELECT request_sha256,state FROM whatsapp_workspace_operations WHERE owner_id=? AND operation_key=?',[$owner,$key])->fetch(PDO::FETCH_ASSOC);
        if(!$row||!hash_equals($row['request_sha256'],$hash))throw new MetaWhatsAppConnectException('This submission key was already used.',409);
        return ['operation_key'=>$key,'outcome'=>$row['state']==='submitting'?'uncertain':$row['state'],'replayed'=>true];
    }
    public function createTemplate(int $owner,array $body):array {
        $phone=(string)($body['phone_number_id']??'');$connection=$this->connection($owner,$phone);
        $name=$body['name']??null;$text=$body['text']??null;$language=$body['language']??null;$category=$body['category']??null;
        if(!is_string($name)||!preg_match('/^[a-z][a-z0-9_]{0,511}$/D',$name)||!is_string($text)||trim($text)===''||mb_strlen($text)>1024||!preg_match('//u',$text)||str_contains($text,'{{')||str_contains($text,'}}')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$text)
            ||!in_array($language,['en_ZA','en_US','en_GB'],true)||!in_array($category,['UTILITY','MARKETING'],true))throw new MetaWhatsAppConnectException('Enter a template name, category, English language and plain text of up to 1,024 characters. Variables are not supported when creating pilot templates.',422);
        return $this->execute($owner,$connection,'template',(string)($body['operation_key']??''),['name'=>$name,'language'=>$language,'category'=>$category,'text'=>$text],$connection['waba_id'].'/message_templates',['name'=>$name,'language'=>$language,'category'=>$category,'components'=>[['type'=>'BODY','text'=>$text]]]);
    }
    public function send(int $owner,array $body):array {
        if(!$this->sendingEnabled)throw new MetaWhatsAppConnectException('WhatsApp sending is awaiting activation.',503);
        $connection=$this->connection($owner,(string)($body['phone_number_id']??''));
        $recipient=$body['recipient']??null;$note=$body['consent_note']??null;
        if(!is_string($recipient)||!preg_match('/^\+27[1-9][0-9]{8}$/D',$recipient))throw new MetaWhatsAppConnectException('Enter a South African recipient number in +27 format.',422);
        if(($body['consent']??false)!==true||!is_string($note)||mb_strlen(trim($note))<5||mb_strlen($note)>500)throw new MetaWhatsAppConnectException('Confirm recipient opt-in and record how permission was obtained.',422);
        $template=null;
        foreach($this->templatesFor($connection)as $item)if($item['name']===($body['template']??null)&&$item['language']===($body['language']??null))$template=$item;
        if(!$template||$template['status']!=='APPROVED'||!$template['supported'])throw new MetaWhatsAppConnectException('Select an approved text template supported by this pilot.',422);
        $parameters=$body['parameters']??[];
        if(!is_array($parameters)||!array_is_list($parameters)||count($parameters)!==$template['parameter_count'])throw new MetaWhatsAppConnectException('Complete all template parameters.',422);
        foreach($parameters as $value)if(!is_string($value)||trim($value)===''||mb_strlen($value)>1024||!preg_match('//u',$value))throw new MetaWhatsAppConnectException('Template parameters must contain valid text.',422);
        $payload=['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>substr($recipient,1),'type'=>'template','template'=>['name'=>$template['name'],'language'=>['code'=>$template['language']]]];
        if($parameters)$payload['template']['components']=[['type'=>'body','parameters'=>array_map(static fn($value)=>['type'=>'text','text'=>$value],$parameters)]];
        return $this->execute($owner,$connection,'send',(string)($body['operation_key']??''),['recipient'=>$recipient,'template'=>$template['name'],'language'=>$template['language'],'parameters'=>$parameters,'consent_note'=>trim($note),'consent_recorded_at'=>$body['consent_recorded_at']??null],$connection['phone_number_id'].'/messages',$payload);
    }
    public function subscribe(int $owner,array $body):array {
        $connection=$this->connection($owner,(string)($body['phone_number_id']??''));
        return $this->execute($owner,$connection,'subscribe',(string)($body['operation_key']??''),[],$connection['waba_id'].'/subscribed_apps',[]);
    }
}
