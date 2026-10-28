<?php
require_once __DIR__.'/EmailService.php';

final class CreditPurchaseMailer
{
    public static function enqueue(PDO $pdo,string $key,string $recipient,string $subject,string $html): void
    {
        $q=$pdo->prepare('SELECT id FROM credit_purchase_mail_outbox WHERE event_key=?');$q->execute([$key]);
        if (!$q->fetchColumn()) $pdo->prepare('INSERT INTO credit_purchase_mail_outbox(event_key,recipient,subject,html,available_at,created_at) VALUES(?,?,?,?,NOW(),NOW())')->execute([$key,$recipient,$subject,$html]);
    }

    public static function dispatchDue(int $limit=20): array
    {
        $pdo=db();$limit=max(1,min(50,$limit));$sent=0;$failed=0;
        $exists=$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_purchase_mail_outbox'")->fetchColumn();
        if (!$exists) return ['processed'=>0,'sent'=>0,'failed'=>0];
        $rows=$pdo->query("SELECT id FROM credit_purchase_mail_outbox WHERE sent_at IS NULL AND attempts<8 AND available_at<=NOW() AND (locked_at IS NULL OR locked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)) ORDER BY id LIMIT $limit")->fetchAll();
        foreach ($rows as $row) {
            $token=bin2hex(random_bytes(16));
            $claim=$pdo->prepare("UPDATE credit_purchase_mail_outbox SET attempts=attempts+1,locked_at=NOW(),lock_token=? WHERE id=? AND sent_at IS NULL AND attempts<8 AND available_at<=NOW() AND (locked_at IS NULL OR locked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE))");$claim->execute([$token,$row['id']]);
            if ($claim->rowCount()!==1) continue;
            try {
                $q=$pdo->prepare('SELECT * FROM credit_purchase_mail_outbox WHERE id=? AND lock_token=?');$q->execute([$row['id'],$token]);$job=$q->fetch();
                $result=EmailService::send($job['recipient'],$job['subject'],$job['html']);
                if (empty($result['success'])) throw new RuntimeException('Email delivery failed');
                $pdo->prepare('UPDATE credit_purchase_mail_outbox SET sent_at=NOW(),locked_at=NULL,lock_token=NULL WHERE id=? AND lock_token=?')->execute([$row['id'],$token]);$sent++;
            } catch (Throwable $e) {
                $pdo->prepare('UPDATE credit_purchase_mail_outbox SET available_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE),locked_at=NULL,lock_token=NULL WHERE id=? AND lock_token=?')->execute([$row['id'],$token]);$failed++;
            }
        }
        return ['processed'=>$sent+$failed,'sent'=>$sent,'failed'=>$failed];
    }
}
