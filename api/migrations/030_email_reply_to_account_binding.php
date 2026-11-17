<?php
declare(strict_types=1);
// Additive, nullable binding: old reservations retain their original Reply-To/account-email fallback.
return static function(PDO $pdo):void {
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
        $q->execute(['email_aws_reservations','account_email_sha256']);$exists=(bool)$q->fetchColumn();
    }else{$exists=false;foreach($pdo->query('PRAGMA table_info(email_aws_reservations)')->fetchAll(PDO::FETCH_ASSOC)as$c)if($c['name']==='account_email_sha256')$exists=true;}
    if(!$exists)$pdo->exec('ALTER TABLE email_aws_reservations ADD COLUMN account_email_sha256 CHAR(64) NULL');
};
