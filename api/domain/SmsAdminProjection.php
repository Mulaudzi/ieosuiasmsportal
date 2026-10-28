<?php

final class SmsAdminProjectionException extends RuntimeException {}

/** Maps an already verified, back-channel SMS admin identity to its local records. */
final class SmsAdminProjection
{
    public static function synchronize(PDO $pdo, array $profile, bool $recordLogin = true, bool $allowProvision = false): array
    {
        $uuid = $profile['sub'] ?? null;
        $email = is_string($profile['email'] ?? null) ? strtolower(trim($profile['email'])) : '';
        if (($profile['account_type'] ?? '') !== 'admin' || ($profile['application'] ?? '') !== 'sms'
            || ($profile['email_verified'] ?? false) !== true || !is_string($uuid)
            || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $uuid)
            || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new SmsAdminProjectionException('identity_not_allowed');
        }

        $ownsTransaction = !$pdo->inTransaction();
        $managed = ($profile['admin_assignment'] ?? '') === 'central-v1';
        if ($managed && (!$allowProvision || ($profile['access_level'] ?? '') !== 'admin' || (int)($profile['token_version'] ?? 0) < 1)) {
            throw new SmsAdminProjectionException('managed_access_not_enabled');
        }
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            // An email fallback may link an unbound record, never replace an existing identity.
            $q = $pdo->prepare('SELECT * FROM admin_users WHERE identity_uuid=? OR LOWER(email)=? ORDER BY id'.$lock);
            $q->execute([$uuid, $email]);
            $admins = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$admins && $managed) {
                $q = $pdo->prepare('SELECT id FROM users WHERE identity_uuid=? OR LOWER(email)=?'.$lock);
                $q->execute([$uuid,$email]);
                if ($q->fetch()) throw new SmsAdminProjectionException('identity_conflict');
                $now = date('Y-m-d H:i:s');
                $name = mb_substr((string)($profile['name'] ?? $email),0,100);
                $pdo->prepare('INSERT INTO admin_users(identity_uuid,name,email,password,pin_hash,is_active,created_at,updated_at) VALUES(?,?,?,NULL,NULL,1,?,?)')->execute([$uuid,$name,$email,$now,$now]);
                $q=$pdo->prepare('SELECT * FROM admin_users WHERE identity_uuid=?');$q->execute([$uuid]);$admins=$q->fetchAll(PDO::FETCH_ASSOC);
            }
            if (count($admins) !== 1 || (int)$admins[0]['is_active'] !== 1) {
                throw new SmsAdminProjectionException('local_access_missing');
            }
            $admin = $admins[0];
            if (strtolower(trim((string)$admin['email'])) !== $email
                || (!empty($admin['identity_uuid']) && !hash_equals((string)$admin['identity_uuid'], $uuid))) {
                throw new SmsAdminProjectionException('identity_conflict');
            }

            $q = $pdo->prepare('SELECT * FROM users WHERE identity_uuid=? OR LOWER(email)=? ORDER BY id'.$lock);
            $q->execute([$uuid, $email]);
            $users = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$users && $managed) {
                $now=date('Y-m-d H:i:s');$name=mb_substr((string)($profile['name']??$email),0,100);
                $pdo->prepare("INSERT INTO users(identity_uuid,name,email,password,role,is_active,email_verified_at,auth_version,created_at,updated_at) VALUES(?,?,?,NULL,'admin',1,?,1,?,?)")->execute([$uuid,$name,$email,$now,$now,$now]);
                $q=$pdo->prepare('SELECT * FROM users WHERE identity_uuid=?');$q->execute([$uuid]);$users=$q->fetchAll(PDO::FETCH_ASSOC);
            }
            if (!$users) throw new SmsAdminProjectionException('admin_projection_missing');
            if (count($users) !== 1) throw new SmsAdminProjectionException('identity_conflict');
            $user = $users[0];
            if (strtolower(trim((string)$user['email'])) !== $email
                || (!empty($user['identity_uuid']) && !hash_equals((string)$user['identity_uuid'], $uuid))) {
                throw new SmsAdminProjectionException('identity_conflict');
            }

            $changed = empty($user['identity_uuid']) || $user['role'] !== 'admin'
                || (int)$user['is_active'] !== 1 || empty($user['email_verified_at']);
            $version = max(1, (int)($user['auth_version'] ?? 1)) + ($changed ? 1 : 0);
            $now = date('Y-m-d H:i:s');
            $pdo->prepare('UPDATE admin_users SET identity_uuid=?,last_login_at=?,updated_at=? WHERE id=?')
                ->execute([$uuid, $recordLogin ? $now : $admin['last_login_at'], $now, $admin['id']]);
            $pdo->prepare('UPDATE users SET identity_uuid=?,role=?,is_active=1,email_verified_at=?,auth_version=?,last_login_at=?,updated_at=? WHERE id=?')
                ->execute([$uuid, 'admin', $user['email_verified_at'] ?: $now, $version, $recordLogin ? $now : $user['last_login_at'], $now, $user['id']]);
            $q = $pdo->prepare('SELECT * FROM users WHERE id=?');
            $q->execute([$user['id']]);
            $result = $q->fetch(PDO::FETCH_ASSOC);
            if ($ownsTransaction) $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}
