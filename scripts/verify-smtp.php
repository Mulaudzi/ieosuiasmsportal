<?php

declare(strict_types=1);

require dirname(__DIR__) . '/api/config/database.php';

require dirname(__DIR__) . '/api/lib/PHPMailer/Exception.php';
require dirname(__DIR__) . '/api/lib/PHPMailer/PHPMailer.php';
require dirname(__DIR__) . '/api/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

$settings = [
    'host' => (string) env('SMTP_HOST', ''),
    'port' => (int) env('SMTP_PORT', 465),
    'encryption' => (string) env('SMTP_ENCRYPTION', 'ssl'),
    'username' => (string) env('SMTP_USER', ''),
    'password' => (string) env('SMTP_PASS', ''),
];
$source = 'environment';

try {
    $statement = db()->prepare("SELECT host,port,encryption,username,password FROM smtp_settings WHERE setting_type='system' AND is_active=1 LIMIT 1");
    $statement->execute();
    $databaseSettings = $statement->fetch();
    if ($databaseSettings && $databaseSettings['host'] !== '' && $databaseSettings['password'] !== '') {
        $settings = $databaseSettings;
        $source = 'database';
    }
} catch (Throwable $exception) {
    echo 'SETTINGS_LOOKUP=FAILED' . PHP_EOL;
}

echo 'SMTP_SOURCE=' . $source . PHP_EOL;
echo 'SMTP_PORT=' . (int) $settings['port'] . PHP_EOL;
echo 'SMTP_ENCRYPTION=' . strtolower((string) $settings['encryption']) . PHP_EOL;
echo 'SMTP_USERNAME_CONFIGURED=' . ($settings['username'] !== '' ? 'YES' : 'NO') . PHP_EOL;
echo 'SMTP_PASSWORD_CONFIGURED=' . ($settings['password'] !== '' ? 'YES' : 'NO') . PHP_EOL;

$mailer = new PHPMailer(true);
$mailer->isSMTP();
$mailer->Host = (string) $settings['host'];
$mailer->Port = (int) $settings['port'];
$mailer->SMTPAuth = true;
$mailer->Username = (string) $settings['username'];
$mailer->Password = (string) $settings['password'];
$encryption = strtolower((string) $settings['encryption']);
if ($encryption === 'ssl') {
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
} elseif ($encryption === 'tls') {
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
} else {
    $mailer->SMTPSecure = '';
    $mailer->SMTPAutoTLS = false;
}
$mailer->Timeout = 20;

try {
    $connected = $mailer->smtpConnect();
    echo 'SMTP_AUTH=' . ($connected ? 'OK' : 'FAILED') . PHP_EOL;
    $mailer->smtpClose();
    exit($connected ? 0 : 1);
} catch (Throwable $exception) {
    echo 'SMTP_AUTH=FAILED' . PHP_EOL;
    echo 'SMTP_ERROR=' . $exception->getMessage() . PHP_EOL;
    exit(1);
}
