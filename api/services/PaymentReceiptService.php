<?php

require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/../domain/SmsPricing.php';

final class PaymentReceiptService
{
    public static function enqueue(PDO $pdo, int $paymentId, int $userId): bool
    {
        if(!self::available($pdo))return false;
        $stmt=$pdo->prepare("INSERT INTO payment_receipt_outbox(payment_id,user_id,status,attempts,available_at,created_at,updated_at)
            VALUES(?,?,'pending',0,NOW(),NOW(),NOW())
            ON DUPLICATE KEY UPDATE payment_id=VALUES(payment_id)");
        $stmt->execute([$paymentId,$userId]);
        return true;
    }

    public static function dispatch(int $paymentId): array
    {
        $pdo=db();
        if(!self::available($pdo))return self::direct($pdo,$paymentId);
        $claim=$pdo->prepare("UPDATE payment_receipt_outbox SET status='processing',locked_at=NOW(),attempts=attempts+1,updated_at=NOW()
            WHERE payment_id=? AND sent_at IS NULL AND attempts<8 AND available_at<=NOW()
              AND (status IN ('pending','failed') OR (status='processing' AND locked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)))");
        $claim->execute([$paymentId]);
        if($claim->rowCount()!==1){
            $existing=table('payment_receipt_outbox')->where('payment_id',$paymentId)->first();
            return ['success'=>(bool)($existing['sent_at']??false),'status'=>$existing['status']??'missing'];
        }

        $stmt=$pdo->prepare("SELECT o.*,p.amount,p.merchant_reference,u.email,u.name
            FROM payment_receipt_outbox o
            JOIN payments p ON p.id=o.payment_id
            JOIN users u ON u.id=o.user_id
            WHERE o.payment_id=? AND p.status='completed' LIMIT 1");
        $stmt->execute([$paymentId]);$job=$stmt->fetch();
        if(!$job||empty($job['email'])){
            self::fail($pdo,$paymentId,'Completed payment or customer email was not available');
            return ['success'=>false,'status'=>'failed'];
        }

        try {
            $result=EmailService::sendPaymentConfirmationEmail((string)$job['email'],(string)($job['name']?:'Customer'),(float)$job['amount'],(string)$job['merchant_reference']);
        } catch (\Throwable $exception) {
            $result=['success'=>false,'error'=>$exception->getMessage()];
        }
        if(!empty($result['success'])){
            $done=$pdo->prepare("UPDATE payment_receipt_outbox SET status='sent',sent_at=NOW(),locked_at=NULL,last_error=NULL,updated_at=NOW() WHERE payment_id=?");
            $done->execute([$paymentId]);
            return ['success'=>true,'status'=>'sent'];
        }
        self::fail($pdo,$paymentId,(string)($result['error']??'SMTP delivery failed'));
        return ['success'=>false,'status'=>'failed'];
    }

    public static function dispatchDue(int $limit=25): array
    {
        if(!self::available(db()))return ['processed'=>0,'sent'=>0,'failed'=>0,'migration_required'=>true];
        $limit=max(1,min(100,$limit));
        $rows=db()->query("SELECT payment_id FROM payment_receipt_outbox WHERE sent_at IS NULL AND attempts<8 AND available_at<=NOW() AND (status IN ('pending','failed') OR (status='processing' AND locked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE))) ORDER BY available_at,id LIMIT {$limit}")->fetchAll();
        $sent=0;$failed=0;
        foreach($rows as $row){$result=self::dispatch((int)$row['payment_id']);!empty($result['success'])?$sent++:$failed++;}
        return ['processed'=>count($rows),'sent'=>$sent,'failed'=>$failed];
    }

    private static function fail(PDO $pdo,int $paymentId,string $error):void
    {
        $safe=substr(preg_replace('/\s+/',' ',trim($error))?:'SMTP delivery failed',0,500);
        $stmt=$pdo->prepare("UPDATE payment_receipt_outbox SET status='failed',locked_at=NULL,last_error=?,available_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE),updated_at=NOW() WHERE payment_id=?");
        $stmt->execute([$safe,$paymentId]);
    }

    private static function available(PDO $pdo):bool
    {
        static $available=null;if($available!==null)return $available;
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_receipt_outbox'");
        $stmt->execute();return $available=(int)$stmt->fetchColumn()>0;
    }

    private static function direct(PDO $pdo,int $paymentId):array
    {
        $stmt=$pdo->prepare("SELECT p.amount,p.merchant_reference,u.email,u.name FROM payments p JOIN users u ON u.id=p.user_id WHERE p.id=? AND p.status='completed' LIMIT 1");
        $stmt->execute([$paymentId]);$payment=$stmt->fetch();
        if(!$payment||empty($payment['email']))return ['success'=>false,'status'=>'failed'];
        try{$result=EmailService::sendPaymentConfirmationEmail((string)$payment['email'],(string)($payment['name']?:'Customer'),(float)$payment['amount'],(string)$payment['merchant_reference']);}
        catch(\Throwable $exception){$result=['success'=>false];}
        return ['success'=>!empty($result['success']),'status'=>!empty($result['success'])?'sent':'failed'];
    }
}
