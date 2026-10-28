<?php
require_once __DIR__.'/CreditPurchasePricing.php';

final class CustomerCashBalance
{
    public static function available(PDO $pdo):bool {
        $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            ? "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('customer_cash_ledger','credit_cash_allocations')"
            : "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('customer_cash_ledger','credit_cash_allocations')";
        return (int)$pdo->query($sql)->fetchColumn()===2;
    }
    public static function snapshot(PDO $pdo,int $userId):array {
        if (!self::available($pdo)) return ['enabled'=>false,'balance_cents'=>0,'reserved_cents'=>0,'available_cents'=>0];
        $q=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM customer_cash_ledger WHERE user_id=?');$q->execute([$userId]);$balance=(int)$q->fetchColumn();
        // Reservations remain until an order is settled or explicitly closed. An old
        // bank reference can still receive funds: never silently recycle its balance.
        $q=$pdo->prepare("SELECT COALESCE(SUM(applied_cents),0) FROM credit_cash_allocations WHERE user_id=? AND status='reserved'");$q->execute([$userId]);$reserved=(int)$q->fetchColumn();
        return ['enabled'=>true,'balance_cents'=>$balance,'reserved_cents'=>$reserved,'available_cents'=>$balance-$reserved];
    }
    public static function quote(int $credits,string $method,string $choice,int $balance):array {
        if (!in_array($choice,['keep_credits','keep_payment'],true)) throw new InvalidArgumentException('Choose whether to keep the SMS quantity or payment budget.');
        $base=CreditPurchasePricing::quote($credits,$method);$requested=$credits;
        if ($choice==='keep_payment' && $balance!==0) {
            $budget=$base['total_cents'];$best=null;$rules=CreditPurchasePricing::rules();
            foreach ($rules['tiers'] as $tier) {
                $lo=max($rules['minimum'],$tier['min']);$hi=min($rules['maximum'],$tier['max'],$method==='payos'?$rules['online_maximum']:$rules['maximum']);
                while($lo<=$hi) {$mid=intdiv($lo+$hi,2);$q=CreditPurchasePricing::quote($mid,$method);if($q['total_cents']-$balance<=$budget){$best=$q;$lo=$mid+1;}else{$hi=$mid-1;}}
            }
            if (!$best) throw new InvalidArgumentException('This budget cannot cover the amount owed and the minimum SMS purchase. Increase your budget or keep the SMS quantity.');
            $base=$best;$payment=$budget;$applied=$balance;
        } else {
            // Leave at least one cent payable; full cash-funded checkout is not implicit.
            $applied=$balance>0?min($balance,$base['total_cents']-1):$balance;
            $payment=$base['total_cents']-$applied;
        }
        if ($payment<1 || $payment>1000000000) throw new InvalidArgumentException('Payment amount outside the supported range. Contact sms@ieosuia.com.');
        return [...$base,'sms_total_cents'=>$base['total_cents'],'total_cents'=>$payment,'cash_applied_cents'=>$applied,'requested_credits'=>$requested,'balance_choice'=>$choice];
    }
    public static function reserve(PDO $pdo,int $orderId,int $userId,array $quote):void {
        if (!$pdo->inTransaction()) throw new LogicException('Cash reservation requires a transaction.');
        $q=$pdo->prepare("INSERT INTO credit_cash_allocations(order_id,user_id,requested_credits,choice,applied_cents,sms_total_cents,payment_cents,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'reserved',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        $q->execute([$orderId,$userId,$quote['requested_credits'],$quote['balance_choice'],$quote['cash_applied_cents'],$quote['sms_total_cents'],$quote['total_cents']]);
    }
    public static function settle(PDO $pdo,array $order,int $receivedCents):void {
        if (!$pdo->inTransaction()) throw new LogicException('Cash settlement requires a transaction.');
        $q=$pdo->prepare('SELECT * FROM credit_cash_allocations WHERE order_id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$order['id']]);$allocation=$q->fetch(PDO::FETCH_ASSOC);
        if ($allocation && $allocation['status']==='settled') return;
        if ($allocation && $allocation['status']!=='reserved') throw new DomainException('Cash allocation has been closed. Reconcile this payment separately.');
        $smsTotal=(int)($allocation['sms_total_cents']??$order['total_cents']);
        $delta=$receivedCents-$smsTotal;
        $q=$pdo->prepare('INSERT INTO customer_cash_ledger(user_id,order_id,amount_cents,event_key,description,created_at) VALUES(?,?,?,?,?,CURRENT_TIMESTAMP)');
        $q->execute([$order['user_id'],$order['id'],$delta,'order:'.$order['id'].':settled','Cleared EFT/checkout amount less frozen SMS purchase value']);
        if ($allocation) $pdo->prepare("UPDATE credit_cash_allocations SET status='settled',received_cents=?,updated_at=CURRENT_TIMESTAMP WHERE order_id=?")->execute([$receivedCents,$order['id']]);
        else $pdo->prepare("INSERT INTO credit_cash_allocations(order_id,user_id,requested_credits,choice,applied_cents,sms_total_cents,payment_cents,received_cents,status,created_at,updated_at) VALUES(?,?,?,'keep_credits',0,?,?,?,'settled',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")->execute([$order['id'],$order['user_id'],$order['credits'],$smsTotal,$order['total_cents'],$receivedCents]);
    }
    public static function release(PDO $pdo,int $orderId):void {
        if (!self::available($pdo)) return;
        $pdo->prepare("UPDATE credit_cash_allocations SET status='released',updated_at=CURRENT_TIMESTAMP WHERE order_id=? AND status='reserved'")->execute([$orderId]);
    }
}
