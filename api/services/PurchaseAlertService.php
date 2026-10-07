<?php
require_once __DIR__.'/EmailService.php';
require_once __DIR__.'/SmsService.php';
require_once __DIR__.'/../domain/SmsPricing.php';

final class PurchaseAlertService
{
    public const RECIPIENT = 'sms@ieosuia.com';

    public static function dispatchDue(int $limit=25): array
    {
        $pdo=db();$limit=max(1,min(100,$limit));
        // A durable scan also recovers an alert if a callback process terminated
        // after committing the wallet credit. Never notify for pending payments.
        $pdo->exec("INSERT IGNORE INTO purchase_alert_outbox(payment_id,available_at,created_at,updated_at)
            SELECT p.id,NOW(),NOW(),NOW() FROM payments p
            JOIN purchase_alert_settings s ON s.id=1
            WHERE p.status='completed' AND p.processed_at>=s.enabled_at");
        $jobs=$pdo->query("SELECT payment_id FROM purchase_alert_outbox WHERE sent_at IS NULL AND attempts<8 AND available_at<=NOW()
            AND (status IN ('pending','failed') OR (status='processing' AND locked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE))) ORDER BY created_at LIMIT {$limit}")->fetchAll();
        $sent=0;$failed=0;
        foreach($jobs as $job){
            $id=(int)$job['payment_id'];$token=bin2hex(random_bytes(16));
            $claim=$pdo->prepare("UPDATE purchase_alert_outbox SET status='processing',attempts=attempts+1,lock_token=?,locked_at=NOW(),updated_at=NOW()
                WHERE payment_id=? AND sent_at IS NULL AND attempts<8 AND available_at<=NOW()
                AND (status IN ('pending','failed') OR (status='processing' AND locked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)))");
            $claim->execute([$token,$id]);if(!$claim->rowCount())continue;
            try{
                $query=$pdo->prepare("SELECT p.id,p.amount,p.credits_added,p.merchant_reference,p.gateway_reference,p.processed_at,u.name,u.email
                    FROM payments p JOIN users u ON u.id=p.user_id WHERE p.id=? AND p.status='completed'");
                $query->execute([$id]);$payment=$query->fetch();if(!$payment)throw new RuntimeException('Completed payment not available');
                $price=SmsPricing::pricePerSegment();
                // Round down separately per wallet, just as customer balances do.
                // Include reserved credits in total holdings; show spendable separately.
                $query=$pdo->prepare("SELECT COALESCE(SUM(FLOOR((GREATEST(w.balance,0)+0.000001)/?)),0) customer_credits,
                    COALESCE(SUM(FLOOR((GREATEST(w.balance-w.reserved,0)+0.000001)/?)),0) available_credits
                    FROM wallets w JOIN users u ON u.id=w.user_id WHERE LOWER(COALESCE(u.role,'user'))<>'admin' AND w.currency='ZAR'");
                $query->execute([$price,$price]);$snapshot=array_merge($payment,$query->fetch());
                try{$provider=(new SmsService())->getCredits();}catch(Throwable){$provider=['success'=>false];}
                $snapshot['provider_credits']=!empty($provider['success'])?(float)$provider['credits']:null;
                $snapshot['checked_at']=(new DateTimeImmutable('now',new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d H:i:s').' SAST';
                $pdo->prepare('UPDATE purchase_alert_outbox SET snapshot_json=? WHERE payment_id=? AND lock_token=?')->execute([json_encode($snapshot),$id,$token]);
                $result=EmailService::send(self::RECIPIENT,'SMS credit purchase #'.$id.' confirmed',self::render($snapshot));
                if(empty($result['success']))throw new RuntimeException('Purchase alert email could not be sent');
                $pdo->prepare("UPDATE purchase_alert_outbox SET status='sent',sent_at=NOW(),lock_token=NULL,locked_at=NULL,last_error=NULL,updated_at=NOW() WHERE payment_id=? AND lock_token=?")->execute([$id,$token]);$sent++;
            }catch(Throwable $e){
                // Do not log raw provider/SMTP output, which can contain secrets.
                $pdo->prepare("UPDATE purchase_alert_outbox SET status='failed',last_error='Purchase alert failed; check SMTP and service configuration',available_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE),locked_at=NULL,lock_token=NULL,updated_at=NOW() WHERE payment_id=? AND lock_token=?")->execute([$id,$token]);$failed++;
            }
        }
        return ['processed'=>$sent+$failed,'sent'=>$sent,'failed'=>$failed];
    }

    public static function render(array $data): string
    {
        $escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $provider=$data['provider_credits']===null?'Temporarily unavailable':number_format((float)$data['provider_credits'],2);
        $cards='';foreach([
            ['Credits just purchased',number_format((int)$data['credits_added']),'#2563eb'],
            ['All customer credits',number_format((int)$data['customer_credits']),'#0f172a'],
            ['LogicSMS provider credits',$provider,'#0f766e'],
        ] as [$label,$value,$color])$cards.='<tr><td style="padding:18px 24px;border-bottom:1px solid #e2e8f0"><div style="font-size:13px;color:#64748b">'.$label.'</div><div style="font-size:30px;font-weight:bold;color:'.$color.';margin-top:6px">'.$value.'</div></td></tr>';
        $details='';foreach(['Customer'=>$data['name'],'Email'=>$data['email'],'Amount paid'=>'R'.number_format((float)$data['amount'],2),'Payment reference'=>$data['merchant_reference'],'PayOS reference'=>$data['gateway_reference']??'','Confirmed at'=>$data['processed_at']] as $label=>$value)$details.='<tr><td style="padding:8px 0;color:#64748b;vertical-align:top;width:140px">'.$label.'</td><td style="padding:8px 0;word-break:break-word">'.$escape($value).'</td></tr>';
        return '<!doctype html><html><body style="margin:0;background:#f1f5f9;font-family:Arial,sans-serif;color:#0f172a"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:28px 12px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:auto;background:#fff;border-radius:12px;overflow:hidden"><tr><td style="background:#0f172a;padding:28px;color:#fff"><div style="font-size:13px;letter-spacing:2px;color:#93c5fd">IEOSUIA SMS PORTAL</div><h1 style="font-size:25px;margin:12px 0">Credit purchase confirmed</h1><p style="margin:0;color:#cbd5e1">Payment received and customer wallet credited.</p></td></tr>'.$cards.'<tr><td style="padding:24px"><table role="presentation" width="100%" style="font-size:14px">'.$details.'</table><div style="background:#eff6ff;padding:16px;margin-top:20px;font-size:13px;line-height:1.6">Customer totals include credits reserved for queued campaigns. Available to spend: <strong>'.number_format((int)$data['available_credits']).' credits</strong>.<br>Balances checked: '.$escape($data['checked_at']).'.<br>Provider credits are a separate balance, not credits purchased by customers.</div><p style="font-size:12px;color:#64748b;line-height:1.6">Internal purchase notification. No action is required unless the provider balance needs replenishment. An unavailable provider balance does not mean zero credits.</p></td></tr></table></td></tr></table></body></html>';
    }
}
