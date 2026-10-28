<?php

final class CreditApprovalException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status) { parent::__construct($message); }
}

final class CreditApprovalSecurity
{
    public const OWNER_EMAIL = 'lufuno@ieosuia.com';
    private const WINDOW = 900;
    private const MAX_FAILURES = 5;

    public static function isOwner(?array $user): bool
    {
        return $user && ($user['role'] ?? '') === 'admin' && (int)($user['is_active'] ?? 0) === 1
            && !empty($user['email_verified_at'])
            && strtolower(trim((string)($user['email'] ?? ''))) === self::OWNER_EMAIL;
    }

    public static function available(PDO $pdo): bool
    {
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('credit_approval_security','credit_approval_attempts','credit_approval_events')"
            : "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('credit_approval_security','credit_approval_attempts','credit_approval_events')";
        return (int)$pdo->query($sql)->fetchColumn() === 3;
    }

    private static function ready(PDO $pdo): void
    {
        if (!self::available($pdo)) throw new CreditApprovalException('Credit approval security is being configured. No credits have been added.',503);
    }

    private static function row(PDO $pdo, bool $lock = false): array
    {
        $suffix = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $row = $pdo->query('SELECT * FROM credit_approval_security WHERE id=1'.$suffix)->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new CreditApprovalException('Credit approval security is not initialized.',503);
        return $row;
    }

    public static function status(PDO $pdo, ?array $user): array
    {
        $ready = self::available($pdo);
        $row = $ready ? self::row($pdo) : [];
        return ['available'=>$ready,'configured'=>!empty($row['password_hash']), 'is_owner'=>self::isOwner($user),
            'owner_email'=>self::OWNER_EMAIL, 'updated_at'=>$row['updated_at']??null];
    }

    public static function event(PDO $pdo, int $actor, string $event, ?int $order = null, int $version = 0): void
    {
        $pdo->prepare('INSERT INTO credit_approval_events(user_id,event,order_id,security_version,created_at) VALUES(?,?,?,?,?)')
            ->execute([$actor,$event,$order,$version,date('Y-m-d H:i:s')]);
    }

    // Called with the singleton security row locked; concurrent attempts cannot bypass the limit.
    private static function checkPassword(PDO $pdo, array $row, int $actor, #[SensitiveParameter] string $password): ?CreditApprovalException
    {
        $q=$pdo->prepare('SELECT * FROM credit_approval_attempts WHERE user_id=?');$q->execute([$actor]);$attempt=$q->fetch(PDO::FETCH_ASSOC);
        $now=time();
        if ($attempt && (int)$attempt['blocked_until']>$now) {
            self::event($pdo,$actor,'rate_limited',null,(int)$row['version']);
            return new CreditApprovalException('Too many incorrect master passwords. Try again in 15 minutes.',429);
        }
        $valid = strlen($password)<=72 && password_verify($password,(string)$row['password_hash']);
        if (!$valid) {
            $start=$attempt && $now-(int)$attempt['window_started']<self::WINDOW ? (int)$attempt['window_started'] : $now;
            $failures=$start===(int)($attempt['window_started']??-1) ? (int)$attempt['failures']+1 : 1;
            $blocked=$failures>=self::MAX_FAILURES ? $now+self::WINDOW : 0;
            if ($attempt) $pdo->prepare('UPDATE credit_approval_attempts SET failures=?,window_started=?,blocked_until=? WHERE user_id=?')->execute([$failures,$start,$blocked,$actor]);
            else $pdo->prepare('INSERT INTO credit_approval_attempts(user_id,failures,window_started,blocked_until) VALUES(?,?,?,?)')->execute([$actor,$failures,$start,$blocked]);
            self::event($pdo,$actor,'password_rejected',null,(int)$row['version']);
            return new CreditApprovalException($blocked ? 'Too many incorrect master passwords. Try again in 15 minutes.' : 'The master password is incorrect. No credits have been added.',$blocked?429:422);
        }
        $pdo->prepare('DELETE FROM credit_approval_attempts WHERE user_id=?')->execute([$actor]);
        return null;
    }

    public static function verify(PDO $pdo, int $actor, #[SensitiveParameter] mixed $password): int
    {
        self::ready($pdo);
        if (!is_string($password) || $password==='') throw new CreditApprovalException('Enter the master password to approve this payment.',422);
        if ($pdo->inTransaction()) throw new LogicException('Master-password verification must precede the payment transaction.');
        $pdo->beginTransaction();
        try {
            $row=self::row($pdo,true);
            if (empty($row['password_hash'])) throw new CreditApprovalException('The owner must set the master password before any manual credits can be approved.',503);
            $error=self::checkPassword($pdo,$row,$actor,$password);
            $pdo->commit(); // Persist failed attempts even when the financial action is denied.
        } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
        if ($error) throw $error;
        return (int)$row['version'];
    }

    public static function assertVersion(PDO $pdo, int $version): void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Approval requires a payment transaction.');
        $row=self::row($pdo,true);
        if (empty($row['password_hash']) || (int)$row['version']!==$version)
            throw new CreditApprovalException('The master password changed. Enter the current password and review the payment again.',409);
    }

    public static function configure(PDO $pdo, array $owner, #[SensitiveParameter] mixed $current, #[SensitiveParameter] mixed $next, #[SensitiveParameter] mixed $confirmation): void
    {
        if (!self::isOwner($owner)) throw new CreditApprovalException('Only the verified owner account can manage the master password.',403);
        self::ready($pdo);
        if (!is_string($next) || strlen($next)>72 || preg_match_all('/./us',$next)<14 || trim($next)==='' || !is_string($confirmation) || !hash_equals($next,$confirmation))
            throw new CreditApprovalException('Use matching master passphrases of at least 14 characters, with a maximum of 72 bytes.',422);
        $hash=password_hash($next,PASSWORD_DEFAULT);
        $pdo->beginTransaction();$error=null;
        try {
            $row=self::row($pdo,true);$configured=!empty($row['password_hash']);
            if ($configured) $error=self::checkPassword($pdo,$row,(int)$owner['id'],is_string($current)?$current:'');
            if (!$error && $configured && password_verify($next,$row['password_hash'])) $error=new CreditApprovalException('Choose a different master password.',422);
            if (!$error) {
                $pdo->prepare('UPDATE credit_approval_security SET password_hash=?,version=version+1,updated_by=?,updated_at=? WHERE id=1')->execute([$hash,$owner['id'],date('Y-m-d H:i:s')]);
                self::event($pdo,(int)$owner['id'],$configured?'password_rotated':'password_configured',null,(int)$row['version']+1);
            }
            $pdo->commit();
        } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
        if ($error) throw $error;
    }
}
