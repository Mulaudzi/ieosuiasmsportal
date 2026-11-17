<?php
declare(strict_types=1);

/** Explicitly scoped maintenance tool. Never invokes the general migration runner. */
final class CreditTransferSetup
{
    public const VERSION='021_credit_transfers';
    public const PREREQUISITE='015_credit_approval_security';
    public const TABLES=['credit_transfer_quotes','credit_transfer_security','credit_transfers'];
    private const HASHES=[
        self::PREREQUISITE=>'0550f3711d2c1cefe062988d44921089ecd330398148c16e4850f8235244ae92',
        self::VERSION=>'94ec8e3e456411c1a203ef8df96812d0fe479d85497b6a4159dabb58e39d72fe',
    ];
    public function __construct(private PDO $pdo,private string $migrationDirectory) {}
    private function rows(string $sql,array $args=[]):array
    { $q=$this->pdo->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC); }
    private function table(string $table):?array
    { return $this->rows('SELECT ENGINE AS engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table])[0]??null; }
    private function columns(string $table):array
    { return array_column($this->rows('SELECT column_name AS field,column_type AS type,is_nullable AS nullable,collation_name AS collation,numeric_scale AS scale FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?',[$table]),null,'field'); }
    private function indexes(string $table):array
    {
        $out=[];foreach($this->rows('SELECT index_name AS name,non_unique AS non_unique,column_name AS field,sub_part AS prefix FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? ORDER BY index_name,seq_in_index',[$table])as$r){$out[$r['name']]['columns'][]=$r['field'];$out[$r['name']]['unique']=!(bool)$r['non_unique'];$out[$r['name']]['full']=($out[$r['name']]['full']??true)&&$r['prefix']===null;}return $out;
    }
    private function unique(string $table,array $columns):bool
    { foreach($this->indexes($table)as$i)if($i['unique']&&$i['full']&&$i['columns']===$columns)return true;return false; }
    private function history():array
    { return array_column($this->rows('SELECT version,applied_at FROM schema_migrations ORDER BY version'),'applied_at','version'); }
    private static function definitions():array
    {
        $party=['actor_user_id'=>'bigint unsigned','source_user_id'=>'bigint unsigned','destination_user_id'=>'bigint unsigned','source_wallet_id'=>'bigint unsigned','destination_wallet_id'=>'bigint unsigned'];
        $value=['credits'=>'int unsigned','unit_amount'=>'decimal(14,4)','amount'=>'decimal(14,4)'];
        return [
            'credit_approval_security'=>['id'=>'tinyint unsigned','password_hash'=>'?varchar(255)','version'=>'int unsigned','updated_by'=>'?bigint unsigned','updated_at'=>'?datetime'],
            'credit_approval_attempts'=>['user_id'=>'bigint unsigned','failures'=>'int unsigned','window_started'=>'bigint unsigned','blocked_until'=>'bigint unsigned'],
            'credit_approval_events'=>['id'=>'bigint unsigned','user_id'=>'bigint unsigned','event'=>'varchar(40)','order_id'=>'?bigint unsigned','security_version'=>'int unsigned','created_at'=>'datetime'],
            'credit_transfer_quotes'=>['id'=>'char(32)']+$party+['request_id'=>'char(36)']+$value+[
                'authorization_mode'=>'varchar(10)','code_hash'=>'?varchar(255)','code_expires_at'=>'?bigint unsigned','code_requested_at'=>'?bigint unsigned','reason'=>'varchar(500)',
                'source_name'=>'varchar(255)','source_email'=>'varchar(255)','destination_name'=>'varchar(255)','destination_email'=>'varchar(255)',
                'source_available'=>'decimal(14,4)','destination_available'=>'decimal(14,4)','expires_at'=>'bigint unsigned','created_at'=>'datetime'],
            'credit_transfer_security'=>['user_id'=>'bigint unsigned','failures'=>'int unsigned','window_started'=>'bigint unsigned','blocked_until'=>'bigint unsigned'],
            'credit_transfers'=>['id'=>'char(32)']+$party+$value+['source_before'=>'decimal(14,4)','source_after'=>'decimal(14,4)','source_reserved'=>'decimal(14,4)','destination_before'=>'decimal(14,4)','destination_after'=>'decimal(14,4)','destination_reserved'=>'decimal(14,4)','created_at'=>'datetime'],
        ];
    }
    private static function normalize(string $type):string
    { return strtolower(preg_replace('/\b(bigint|tinyint|int)\(\d+\)/i','$1',$type)); }
    private function file(string $version):string
    {
        // Callers cannot supply a migration name or path. Both files are byte-pinned.
        if(!isset(self::HASHES[$version]))throw new RuntimeException('Migration is not allowlisted.');
        $path=$this->migrationDirectory.'/'.$version.'.php';
        if(!is_file($path)||!hash_equals(self::HASHES[$version],hash_file('sha256',$path)))throw new RuntimeException('Missing or changed approved migration: '.$version);
        return $path;
    }
    public function inspect():array
    {
        if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new RuntimeException('MySQL or MariaDB is required.');
        $problems=[];$tables=[];
        foreach(array_keys(self::HASHES)as$version){try{$this->file($version);}catch(RuntimeException $e){$problems[]=$e->getMessage();}}
        foreach(['users','wallets','wallet_ledger','schema_migrations']as$t){
            $meta=$this->table($t);if(!$meta){$problems[]='Missing prerequisite table: '.$t;continue;}
            if(strtolower($meta['engine'])!=='innodb')$problems[]=$t.' must use InnoDB.';
        }
        foreach(['users','wallets']as$t){$cols=$this->columns($t);if(self::normalize($cols['id']['type']??'')!=='bigint unsigned'||!$this->unique($t,['id']))$problems[]=$t.'.id must be a full unsigned BIGINT unique key.';}
        $wallet=$this->columns('wallets');
        foreach(['balance','reserved']as$c)if(!str_starts_with($wallet[$c]['type']??'','decimal(')||(int)($wallet[$c]['scale']??0)<2)$problems[]='Exact decimal wallet column required: '.$c;
        if(self::normalize($wallet['user_id']['type']??'')!=='bigint unsigned')$problems[]='Unexpected wallets.user_id definition.';
        if($this->table('wallets')&&isset($wallet['user_id'])&&$this->rows('SELECT user_id FROM wallets GROUP BY user_id HAVING COUNT(*)>1 LIMIT 1'))$problems[]='Duplicate account wallets require reconciliation.';
        if(!$this->unique('wallet_ledger',['idempotency_key']))$problems[]='A full unique wallet-ledger idempotency key is required.';
        $historyColumns=$this->columns('schema_migrations');
        $validHistory=isset($historyColumns['version'],$historyColumns['applied_at'])&&$this->unique('schema_migrations',['version']);
        if(!$validHistory)$problems[]='Migration history is missing or has an unexpected definition.';
        $history=$validHistory?$this->history():[];
        foreach(self::definitions()as$t=>$fields){
            $meta=$this->table($t);$tables[$t]=['exists'=>$meta!==null];if(!$meta)continue;
            if(strtolower($meta['engine'])!=='innodb')$problems[]=$t.' must use InnoDB.';
            $cols=$this->columns($t);
            if(array_diff(array_keys($cols),array_keys($fields)))$problems[]='Unexpected columns in '.$t.'; inspect before continuing.';
            foreach($fields as$c=>$expected){
                $nullable=str_starts_with($expected,'?');$expected=ltrim($expected,'?');$col=$cols[$c]??[];
                if(self::normalize($col['type']??'')!==$expected||($col['nullable']??'')!==($nullable?'YES':'NO'))$problems[]='Unexpected definition: '.$t.'.'.$c;
                if(in_array($t,self::TABLES,true)&&isset($col['collation'])&&$col['collation']!=='utf8mb4_unicode_ci')$problems[]='Unexpected text collation: '.$t.'.'.$c;
            }
            $pk=in_array($t,['credit_approval_attempts','credit_transfer_security'],true)?['user_id']:['id'];
            if(($this->indexes($t)['PRIMARY']['columns']??[])!==$pk)$problems[]='Unexpected primary key: '.$t;
            if($t==='credit_transfer_quotes'&&!$this->unique($t,['actor_user_id','request_id']))$problems[]='Missing actor/request uniqueness on credit_transfer_quotes.';
            if(in_array($t,self::TABLES,true)){
                $expectedFks=$t==='credit_transfer_security'?['user_id'=>['users','id']]:['actor_user_id'=>['users','id'],'source_user_id'=>['users','id'],'destination_user_id'=>['users','id'],'source_wallet_id'=>['wallets','id'],'destination_wallet_id'=>['wallets','id']];
                if($t==='credit_transfers')$expectedFks['id']=['credit_transfer_quotes','id'];
                $fks=$this->rows('SELECT k.column_name AS field,k.referenced_table_name AS target,k.referenced_column_name AS target_column,r.delete_rule AS delete_rule,r.update_rule AS update_rule FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.table_schema=DATABASE() AND k.table_name=? AND k.referenced_table_name IS NOT NULL',[$t]);
                if(count($fks)!==count($expectedFks))$problems[]='Unexpected foreign-key count: '.$t;
                foreach($expectedFks as$c=>[$target,$targetColumn]){$found=false;foreach($fks as$f)if($f['field']===$c&&$f['target']===$target&&$f['target_column']===$targetColumn&&in_array($f['delete_rule'],['RESTRICT','NO ACTION'],true)&&in_array($f['update_rule'],['RESTRICT','NO ACTION'],true))$found=true;if(!$found)$problems[]='Unexpected foreign key: '.$t.'.'.$c;}
            }
        }
        foreach([['credit_transfers','idx_credit_transfer_source',['source_user_id','created_at']],['credit_transfers','idx_credit_transfer_destination',['destination_user_id','created_at']],['credit_transfer_quotes','idx_credit_transfer_actor',['actor_user_id','created_at']]]as[$t,$i,$cols])if($tables[$t]['exists']&&($this->indexes($t)[$i]['columns']??[])!==$cols)$problems[]='Missing or changed transfer index: '.$i;
        $prerequisiteRecorded=isset($history[self::PREREQUISITE]);$recorded=isset($history[self::VERSION]);
        if($prerequisiteRecorded)foreach(['credit_approval_security','credit_approval_attempts','credit_approval_events']as$t)if(!$tables[$t]['exists'])$problems[]='Recorded prerequisite is missing a table: '.$t;
        if($tables['credit_approval_security']['exists']){
            $ids=array_column($this->rows('SELECT id FROM credit_approval_security'),'id');
            if($ids&&array_map('intval',$ids)!==[1])$problems[]='Unexpected finance-security singleton rows.';
            if($prerequisiteRecorded&&!$ids)$problems[]='Recorded finance-security singleton is missing.';
        }
        if($recorded)foreach(self::TABLES as$t)if(!$tables[$t]['exists'])$problems[]='Recorded transfer migration is missing a table: '.$t;
        $partial=!$recorded&&(bool)array_filter(self::TABLES,fn($t)=>$tables[$t]['exists']);
        return ['ok'=>true,'code'=>'CREDIT_TRANSFER_SCHEMA_INSPECTED','read_only'=>true,'migration'=>self::VERSION,'migration_recorded'=>$recorded,'security_prerequisite_recorded'=>$prerequisiteRecorded,'security_prerequisite_permission_required'=>!$prerequisiteRecorded,'partial_setup_requires_explicit_resume'=>$partial,'ready_to_apply'=>!$problems,'tables'=>$tables,'problems'=>array_values(array_unique($problems)),'allowed_migrations'=>[self::PREREQUISITE,self::VERSION],'email_migration_recorded'=>isset($history['019_email_campaigns']),'email_migration_run'=>false,'credits_moved'=>false,'emails_sent'=>false,'sms_submitted'=>false,'payments_processed'=>false,'observed_at_utc'=>gmdate('c')];
    }
    public function apply(string $confirmation,bool $backupConfirmed,bool $includeSecurity=false,bool $resumePartial=false):array
    {
        if($confirmation!==self::VERSION||!$backupConfirmed)throw new RuntimeException('Explicit migration name and verified-backup confirmation are required.');
        if($this->pdo->inTransaction())throw new RuntimeException('Schema setup cannot run inside a financial transaction.');
        if((int)($this->rows("SELECT GET_LOCK(CONCAT(DATABASE(),':credit_transfers_021'),0) acquired")[0]['acquired']??0)!==1)throw new RuntimeException('Another credit-transfer setup is running.');
        try{
            $before=$this->inspect();if(!$before['ready_to_apply'])throw new RuntimeException('Schema preflight failed. Run inspect and resolve its problems.');
            if(!$before['security_prerequisite_recorded']&&!$includeSecurity)throw new RuntimeException('Missing security prerequisite requires --include-security-prerequisite.');
            if($before['partial_setup_requires_explicit_resume']&&!$resumePartial)throw new RuntimeException('Partial unrecorded setup exists. Inspect it, then explicitly authorize --resume-partial.');
            $history=$this->history();
            $this->pdo->exec('SET SESSION lock_wait_timeout=5');$this->pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
            if(!$before['security_prerequisite_recorded']){$m=require $this->file(self::PREREQUISITE);$m($this->pdo);}
            if(!$before['migration_recorded']){$m=require $this->file(self::VERSION);$m($this->pdo);}
            $after=$this->inspect();if(!$after['ready_to_apply'])throw new RuntimeException('Schema post-verification failed. DDL may have partially completed; inspect before continuing.');
            foreach(array_keys(self::definitions())as$t)if(!$after['tables'][$t]['exists'])throw new RuntimeException('Expected setup table is missing: '.$t);
            if((int)($this->rows('SELECT COUNT(*) AS total FROM credit_approval_security WHERE id=1')[0]['total']??0)!==1)throw new RuntimeException('Security singleton was not verified.');
            // Verify all schema first, then atomically append only missing allowlisted markers.
            $this->pdo->beginTransaction();
            foreach([self::PREREQUISITE,self::VERSION]as$version)if(!isset($history[$version]))$this->rows('INSERT INTO schema_migrations(version,applied_at) VALUES(?,NOW())',[$version]);
            $current=$this->history();$unrelated=static fn($rows)=>array_diff_key($rows,array_fill_keys([self::PREREQUISITE,self::VERSION],true));
            if($unrelated($current)!==$unrelated($history))throw new RuntimeException('Unrelated migration history changed concurrently; inspect before proceeding.');
            foreach($history as$version=>$at)if(($current[$version]??null)!==$at)throw new RuntimeException('An existing migration marker changed; investigate.');
            $this->pdo->commit();
            return ['ok'=>true,'code'=>$before['migration_recorded']&&$before['security_prerequisite_recorded']?'ALREADY_APPLIED':'CREDIT_TRANSFERS_READY','migration'=>self::VERSION,'verified_tables'=>self::TABLES,'security_prerequisite_applied'=>!$before['security_prerequisite_recorded'],'migration_recorded'=>true,'email_migration_run'=>false,'other_migrations_run'=>false,'customer_records_changed'=>false,'credits_moved'=>false,'emails_sent'=>false,'sms_submitted'=>false,'payments_processed'=>false,'activation_flag_changed'=>false,'application_deployed'=>false,'schema_and_migration_bookkeeping_only'=>true,'completed_at_utc'=>gmdate('c')];
        }finally{if($this->pdo->inTransaction())$this->pdo->rollBack();$this->rows("SELECT RELEASE_LOCK(CONCAT(DATABASE(),':credit_transfers_021')) released");}
    }
}
