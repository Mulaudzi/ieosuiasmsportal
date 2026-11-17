<?php
/** Source regression checks for removing the obsolete admin-alert recipient. No I/O to live services. */
$source = file_get_contents(__DIR__ . '/../controllers/AuthController.php');
token_get_all($source, TOKEN_PARSE);
$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}

check(stripos($source, 'godtheson@ieosuia.com') === false, 'Obsolete recipient must be absent');
check(!str_contains($source, 'sendAdminLoginAlert'), 'No dead helper or dangling alert call');
$start = strpos($source, '// Admin authentication failed');
$end = strpos($source, '// Find regular user', $start);
check($start !== false && $end !== false, 'Failed-admin branch is present');
$failure = substr($source, $start, $end - $start);
check(str_contains($failure, "AuditLogService::log('admin_login_failed', 'security'"), 'Failed attempts remain audited');
check(str_contains($failure, "'attempted_email' => \$data['email']"), 'Audit retains attempted identity');
check(str_contains($failure, "'message' => 'Authentication failed'"), 'Error remains generic');
check(str_contains($failure, "'remaining_attempts' => \$authResult['remaining_attempts'] ?? null"), 'Remaining attempts retained');
check(str_contains($failure, "'locked_until' => \$authResult['locked_until'] ?? null"), 'Lockout information retained');
check(str_contains($failure, '], 401);'), 'Failed login stays unauthorized');
check(!str_contains($failure, 'EmailService::') && !str_contains($failure, '@ieosuia.com'), 'No replacement mailbox or send in failed-login branch');
check(str_contains($source, 'RateLimiter::checkOrFail("login_ip:{$ip}", 20, 15)'), 'IP rate limit retained');
check(str_contains($source, 'RateLimiter::checkOrFail("login:{$data[\'email\']}", 5, 15)'), 'Account rate limit retained');
echo "PASS: {$checks} admin-alert removal source checks. No emails or remote requests.\n";
