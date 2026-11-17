<?php
declare(strict_types=1);

/** Schema-only maintenance. This class cannot approve payments or grant funds. */
final class CashAdjustmentSetup
{
    public const VERSION='025_cash_balance_adjustments';
    public const CASH_PREREQUISITE='017_customer_cash_balances';
    public const TABLES=['customer_cash_adjustment_quotes','customer_cash_adjustments'];
    public function __construct(private PDO $pdo,private string $directory,private string $migrationHash,private ?string $cashHash=null) {}
    private function rows(string $sql,array $args=[]):array{$q=$this->pdo->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    private function table(string $name):?array{return $this->rows('SELECT ENGINE engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$name])[0]??null;}
    private function columns(string $name):array{return array_column($this->rows('SELECT column_name field,column_type type,is_nullable nullable FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?',[$name]),null,'field');}
    private function indexes(string $name):array{
        $out=[];foreach($this->rows('SELECT index_name name,column_name field,non_unique,sub_part FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? ORDER BY index_name,seq_in_index',[$name])as$r){$out[$r['name']]['fields'][]=$r['field'];$out[$r['name']]['unique']=!(bool)$r['non_unique'];$out[$r['name']]['full']=($out[$r['name']]['full']??true)&&$r['sub_part']===null;}return $out;
    }
    private function unique(string $name,array $fields):bool{foreach($this->indexes($name)as$i)if($i['unique']&&$i['full']&&$i['fields']===$fields)return true;return false;}
    private function history():array{return array_column($this->rows('SELECT version,applied_at FROM schema_migrations ORDER BY version'),'applied_at','version');}
    private function file(string $version=self::VERSION):string{if(!in_array($version,[self::VERSION,self::CASH_PREREQUISITE],true))throw new RuntimeException('Migration not allowlisted.');$hash=$version===self::VERSION?$this->migrationHash:$this->cashHash;$path=$this->directory.'/'.$version.'.php';if(!$hash||!is_file($path)||!hash_equals($hash,hash_file('sha256',$path)))throw new RuntimeException('Approved migration file is missing or changed: '.$version);return $path;}
    private static function normalize(string $type):string{return strtolower(preg_replace('/\b(bigint|int)\(\d+\)/i','$1',$type));}
    private static function definitions():array{
        $common=['id'=>'char(32)','actor_user_id'=>'bigint unsigned','user_id'=>'bigint unsigned','source_kind'=>'varchar(20)','order_id'=>'?bigint unsigned','order_reference'=>'?varchar(100)','bank_reference'=>'varchar(120)','bank_hash'=>'char(64)','cleared_cents'=>'bigint','recorded_cents'=>'bigint','amount_cents'=>'bigint','reason'=>'varchar(1000)','created_at'=>'datetime'];
        return ['customer_cash_adjustment_quotes'=>$common+['request_id'=>'char(36)','input_hash'=>'char(64)','expires_at'=>'bigint'],'customer_cash_adjustments'=>$common+['balance_before_cents'=>'bigint','balance_after_cents'=>'bigint','security_version'=>'int'],
            'customer_cash_ledger'=>['id'=>'bigint unsigned','user_id'=>'bigint unsigned','order_id'=>'bigint unsigned','amount_cents'=>'bigint','event_key'=>'varchar(100)','description'=>'varchar(255)','created_at'=>'datetime'],
            'credit_cash_allocations'=>['order_id'=>'bigint unsigned','user_id'=>'bigint unsigned','requested_credits'=>'int unsigned','choice'=>'varchar(20)','applied_cents'=>'bigint','sms_total_cents'=>'bigint unsigned','payment_cents'=>'bigint unsigned','received_cents'=>'?bigint unsigned','status'=>'varchar(20)','created_at'=>'datetime','updated_at'=>'datetime']];
    }
    public function inspect():array{
        if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new RuntimeException('MySQL / MariaDB is required.');
        $problems=[];$tables=[];try{$this->file();$this->file(self::CASH_PREREQUISITE);}catch(RuntimeException $e){$problems[]=$e->getMessage();}
        $prerequisites=['schema_migrations'=>['version','applied_at'],'users'=>['id','role','is_active','email_verified_at'],'payments'=>['id','user_id','status','gateway','currency','amount'],'credit_purchase_orders'=>['id','user_id','payment_id','reference','bank_reference','status','payment_method'],'credit_approval_security'=>['id','password_hash','version'],'credit_approval_attempts'=>['user_id','failures','window_started','blocked_until'],'credit_approval_events'=>['id','user_id','event','order_id','security_version','created_at'],'customer_cash_ledger'=>['id','user_id','order_id','amount_cents','event_key'],'credit_cash_allocations'=>['order_id','user_id','applied_cents','status']];
        foreach($prerequisites as$table=>$required){$meta=$this->table($table);if(!$meta){if(!in_array($table,['customer_cash_ledger','credit_cash_allocations'],true))$problems[]='Missing prerequisite table: '.$table;continue;}if(strtolower($meta['engine'])!=='innodb')$problems[]='Transactional InnoDB required: '.$table;if(array_diff($required,array_keys($this->columns($table))))$problems[]='Unexpected prerequisite columns: '.$table;}
        $validHistory=$this->table('schema_migrations')&&isset($this->columns('schema_migrations')['version'],$this->columns('schema_migrations')['applied_at'])&&$this->unique('schema_migrations',['version']);
        $history=$validHistory?$this->history():[];if(!$validHistory)$problems[]='Verified migration bookkeeping is required.';
        if(!isset($history['015_credit_approval_security']))$problems[]='Verify existing prerequisite first: 015_credit_approval_security. This link will not apply it.';
        foreach(self::definitions()as$table=>$fields){$meta=$this->table($table);$tables[$table]=['exists'=>$meta!==null];if(!$meta)continue;
            if(strtolower($meta['engine'])!=='innodb')$problems[]='Transactional InnoDB required: '.$table;
            $columns=$this->columns($table);if(array_diff(array_keys($columns),array_keys($fields)))$problems[]='Unexpected extra columns: '.$table;
            foreach($fields as$name=>$type){$nullable=str_starts_with($type,'?');$actual=$columns[$name]??[];if(self::normalize($actual['type']??'')!==ltrim($type,'?')||($actual['nullable']??'')!==($nullable?'YES':'NO'))$problems[]='Unexpected column definition: '.$table.'.'.$name;}
            if(($this->indexes($table)['PRIMARY']['fields']??[])!==($table==='credit_cash_allocations'?['order_id']:['id']))$problems[]='Expected primary key missing: '.$table;
        }
        if($tables[self::TABLES[0]]['exists']&&!$this->unique(self::TABLES[0],['actor_user_id','request_id']))$problems[]='Actor/request idempotency index is missing.';
        if($tables[self::TABLES[1]]['exists']){if(!$this->unique(self::TABLES[1],['bank_hash']))$problems[]='Unique bank-deposit protection is missing.';if(($this->indexes(self::TABLES[1])['idx_cash_adjustment_user']['fields']??[])!==['user_id','created_at'])$problems[]='Customer history index is missing.';}
        if($tables['customer_cash_ledger']['exists']&&!$this->unique('customer_cash_ledger',['event_key']))$problems[]='Existing rand ledger event uniqueness is missing.';
        if($tables['credit_cash_allocations']['exists']&&($this->indexes('credit_cash_allocations')['idx_cash_reserved']['fields']??[])!==['user_id','status'])$problems[]='Existing rand reservation index is missing.';
        $recorded=isset($history[self::VERSION]);$count=count(array_filter(self::TABLES,fn($t)=>$tables[$t]['exists']));
        if($recorded&&$count!==2)$problems[]='Recorded migration is missing a table.';
        $partial=!$recorded&&$count>0;
        $cashRecorded=isset($history[self::CASH_PREREQUISITE]);$cashCount=(int)$tables['customer_cash_ledger']['exists']+(int)$tables['credit_cash_allocations']['exists'];
        if($cashRecorded&&$cashCount!==2)$problems[]='Recorded rand-balance prerequisite is missing a table.';
        $cashPartial=!$cashRecorded&&$cashCount>0;
        return ['ok'=>true,'code'=>'RAND_CREDIT_SCHEMA_INSPECTED','read_only'=>true,'migration'=>self::VERSION,'allowed_migrations'=>[self::CASH_PREREQUISITE,self::VERSION],'migration_recorded'=>$recorded,'cash_prerequisite_recorded'=>$cashRecorded,'cash_prerequisite_permission_required'=>!$cashRecorded,'tables'=>$tables,'partial_setup_requires_investigation'=>$partial||$cashPartial,'ready_to_apply'=>!$problems&&!$partial&&!$cashPartial,'problems'=>array_values(array_unique($problems)),'email_migration_run'=>false,'other_migrations_run'=>false,'customer_records_changed'=>false,'credits_moved'=>false,'sms_submitted'=>false,'emails_sent'=>false,'payments_processed'=>false,'observed_at_utc'=>gmdate('c')];
    }
    public function apply(string $confirmation,bool $backup,bool $includeCash=false):array{
        if($confirmation!==self::VERSION||!$backup)throw new RuntimeException('Confirm migration 025 and a verified database backup.');
        if($this->pdo->inTransaction())throw new RuntimeException('No active financial transaction is permitted.');
        if((int)($this->rows("SELECT GET_LOCK(CONCAT(DATABASE(),':rand_credit_025'),0) acquired")[0]['acquired']??0)!==1)throw new RuntimeException('Another setup is running.');
        try{
            $before=$this->inspect();if(!$before['ready_to_apply'])throw new RuntimeException('Preflight failed; inspect before any changes.');if($before['cash_prerequisite_permission_required']&&!$includeCash)throw new RuntimeException('Explicit permission is required to apply missing rand-balance prerequisite 017.');$history=$this->history();
            $this->pdo->exec('SET SESSION lock_wait_timeout=5');$this->pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
            if(!$before['cash_prerequisite_recorded']){$migration=require $this->file(self::CASH_PREREQUISITE);$migration($this->pdo);}
            if(!$before['migration_recorded']){$migration=require $this->file();$migration($this->pdo);}
            $after=$this->inspect();if($after['problems']||count(array_filter($after['tables'],fn($t)=>$t['exists']))!==4)throw new RuntimeException('Schema verification failed; do not repeat Apply.');
            $this->pdo->beginTransaction();
            foreach([self::CASH_PREREQUISITE,self::VERSION]as$version)if(!isset($history[$version]))$this->rows('INSERT INTO schema_migrations(version,applied_at) VALUES(?,NOW())',[$version]);
            $current=$this->history();$exclude=static fn($rows)=>array_diff_key($rows,[self::CASH_PREREQUISITE=>true,self::VERSION=>true]);
            if($exclude($history)!==$exclude($current))throw new RuntimeException('Unrelated migration history changed. Investigate before proceeding.');
            foreach($history as$version=>$at)if(($current[$version]??null)!==$at)throw new RuntimeException('Existing migration history changed.');
            $this->pdo->commit();
            return ['ok'=>true,'code'=>'CASH_BALANCE_ADJUSTMENTS_READY','migration'=>self::VERSION,'allowed_migrations'=>[self::CASH_PREREQUISITE,self::VERSION],'verified_tables'=>array_keys(self::definitions()),'migration_recorded'=>true,'cash_prerequisite_applied'=>!$before['cash_prerequisite_recorded'],'email_migration_run'=>false,'other_migrations_run'=>false,'customer_records_changed'=>false,'credits_moved'=>false,'sms_submitted'=>false,'emails_sent'=>false,'payments_processed'=>false,'activation_flag_changed'=>false,'application_deployed'=>false,'schema_and_migration_bookkeeping_only'=>true,'completed_at_utc'=>gmdate('c'),'message'=>'Rand-credit database setup verified. No balances changed; the feature still needs separate deployment and activation. Copy this JSON back so the temporary link can be removed.'];
        }finally{if($this->pdo->inTransaction())$this->pdo->rollBack();$this->rows("SELECT RELEASE_LOCK(CONCAT(DATABASE(),':rand_credit_025')) released");}
    }
}
