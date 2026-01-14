<?php
/**
 * SMTP Settings Controller - Admin management of email configuration
 */

require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class SmtpSettingsController
{
    /**
     * List all SMTP settings
     */
    public function index(): void
    {
        $user = Auth::user();
        // FIXED: Changed from checking non-existent 'role' column to 'account_type'
        if (($user['account_type'] ?? 'standard') !== 'admin') {
            Response::error('Admin access required', 403);
        }

        $settings = table('smtp_settings')->get();
        
        // Mask passwords for security
        foreach ($settings as &$setting) {
            $setting['password'] = $setting['password'] ? '********' : '';
            $setting['has_password'] = !empty($setting['password']);
        }

        Response::success(['settings' => $settings]);
    }

    /**
     * Get a single SMTP setting by type
     */
    public function show(array $params): void
    {
        $user = Auth::user();
        if (($user['account_type'] ?? 'standard') !== 'admin') {
            Response::error('Admin access required', 403);
        }

        $type = $params['type'] ?? null;
        if (!in_array($type, ['system', 'campaign'])) {
            Response::error('Invalid setting type', 400);
        }

        $setting = table('smtp_settings')->where('setting_type', $type)->first();
        
        if (!$setting) {
            Response::error('Setting not found', 404);
        }

        // Mask password
        $setting['password'] = $setting['password'] ? '********' : '';
        $setting['has_password'] = !empty($setting['password']);

        Response::success(['setting' => $setting]);
    }

    /**
     * Update SMTP settings
     */
    public function update(array $params): void
    {
        $user = Auth::user();
        if (($user['account_type'] ?? 'standard') !== 'admin') {
            Response::error('Admin access required', 403);
        }

        $type = $params['type'] ?? null;
        if (!in_array($type, ['system', 'campaign'])) {
            Response::error('Invalid setting type', 400);
        }

        $data = Request::validate([
            'host' => 'required|max:255',
            'port' => 'required',
            'encryption' => 'required',
            'username' => 'required|max:255',
            'password' => 'max:500',
            'from_email' => 'required|email|max:255',
            'from_name' => 'required|max:100',
        ]);

        $setting = table('smtp_settings')->where('setting_type', $type)->first();
        
        $updateData = [
            'host' => $data['host'],
            'port' => (int) $data['port'],
            'encryption' => $data['encryption'],
            'username' => $data['username'],
            'from_email' => $data['from_email'],
            'from_name' => $data['from_name'],
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Only update password if provided (not masked value)
        if (!empty($data['password']) && $data['password'] !== '********') {
            $updateData['password'] = $data['password'];
        }

        if ($setting) {
            table('smtp_settings')->where('id', $setting['id'])->update($updateData);
        } else {
            $updateData['setting_type'] = $type;
            $updateData['password'] = $data['password'] ?? '';
            $updateData['created_at'] = date('Y-m-d H:i:s');
            table('smtp_settings')->insert($updateData);
        }

        // Log the action
        require_once __DIR__ . '/../services/AuditLogService.php';
        AuditLogService::log(
            $user['id'],
            'smtp_settings_updated',
            'smtp_settings',
            $type,
            ['type' => $type],
            ['type' => $type, 'host' => $data['host']]
        );

        Response::success(['message' => 'SMTP settings updated successfully']);
    }

    /**
     * Test SMTP connection
     */
    public function test(array $params): void
    {
        $user = Auth::user();
        if (($user['account_type'] ?? 'standard') !== 'admin') {
            Response::error('Admin access required', 403);
        }

        $type = $params['type'] ?? null;
        if (!in_array($type, ['system', 'campaign'])) {
            Response::error('Invalid setting type', 400);
        }

        $data = Request::all();
        $testEmail = $data['test_email'] ?? $user['email'];

        // Get current settings or use provided data
        $setting = table('smtp_settings')->where('setting_type', $type)->first();
        
        $host = $data['host'] ?? $setting['host'] ?? '';
        $port = (int) ($data['port'] ?? $setting['port'] ?? 465);
        $encryption = $data['encryption'] ?? $setting['encryption'] ?? 'ssl';
        $username = $data['username'] ?? $setting['username'] ?? '';
        $password = $data['password'] ?? '';
        $fromEmail = $data['from_email'] ?? $setting['from_email'] ?? '';
        $fromName = $data['from_name'] ?? $setting['from_name'] ?? '';

        // Use stored password if masked value provided
        if ($password === '********' || empty($password)) {
            $password = $setting['password'] ?? '';
        }

        if (empty($host) || empty($username) || empty($fromEmail)) {
            Response::error('Missing required SMTP settings', 400);
        }

        try {
            $mailer = new PHPMailer(true);
            
            $mailer->isSMTP();
            $mailer->Host = $host;
            $mailer->Port = $port;
            $mailer->SMTPAuth = true;
            $mailer->Username = $username;
            $mailer->Password = $password;
            
            switch ($encryption) {
                case 'ssl':
                    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    break;
                case 'tls':
                    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    break;
                default:
                    $mailer->SMTPSecure = '';
                    $mailer->SMTPAutoTLS = false;
            }
            
            $mailer->setFrom($fromEmail, $fromName);
            $mailer->addAddress($testEmail);
            
            $mailer->isHTML(true);
            $mailer->Subject = 'SMTP Test - ' . ucfirst($type) . ' Email Configuration';
            $mailer->Body = $this->getTestEmailBody($type, $fromName);
            $mailer->AltBody = "This is a test email from your IEOSUIA Portal to verify the {$type} SMTP configuration is working correctly.";
            
            $mailer->CharSet = 'UTF-8';
            
            // Enable debug for detailed error
            $mailer->SMTPDebug = SMTP::DEBUG_OFF;
            
            $mailer->send();

            // Update test result in database
            if ($setting) {
                table('smtp_settings')->where('id', $setting['id'])->update([
                    'last_tested_at' => date('Y-m-d H:i:s'),
                    'last_test_result' => 'success',
                    'last_test_error' => null,
                ]);
            }

            Response::success([
                'message' => 'Test email sent successfully to ' . $testEmail,
                'result' => 'success'
            ]);

        } catch (Exception $e) {
            $errorMessage = $mailer->ErrorInfo ?? $e->getMessage();
            
            // Update test result in database
            if ($setting) {
                table('smtp_settings')->where('id', $setting['id'])->update([
                    'last_tested_at' => date('Y-m-d H:i:s'),
                    'last_test_result' => 'failed',
                    'last_test_error' => $errorMessage,
                ]);
            }

            Response::error('SMTP test failed: ' . $errorMessage, 500);
        }
    }

    /**
     * Get settings for use in email services
     */
    public static function getSettings(string $type): array
    {
        $setting = table('smtp_settings')
            ->where('setting_type', $type)
            ->where('is_active', 1)
            ->first();

        if ($setting && !empty($setting['host']) && !empty($setting['password'])) {
            return [
                'host' => $setting['host'],
                'port' => (int) $setting['port'],
                'encryption' => $setting['encryption'],
                'username' => $setting['username'],
                'password' => $setting['password'],
                'from_email' => $setting['from_email'],
                'from_name' => $setting['from_name'],
            ];
        }

        // Fallback to environment variables
        if ($type === 'campaign') {
            return [
                'host' => env('SMTP_HOST', 'sms.ieosuia.com'),
                'port' => (int) env('SMTP_PORT', 465),
                'encryption' => 'ssl',
                'username' => env('SMTP_USER', 'email@sms.ieosuia.com'),
                'password' => env('SMTP_PASS', ''),
                'from_email' => env('SMTP_FROM_EMAIL', 'email@sms.ieosuia.com'),
                'from_name' => env('SMTP_FROM_NAME', 'IEOSUIA Portal'),
            ];
        }

        // System email settings
        return [
            'host' => env('SMTP_HOST', 'sms.ieosuia.com'),
            'port' => (int) env('SMTP_PORT', 465),
            'encryption' => 'ssl',
            'username' => env('SMTP_USER', 'noreply@sms.ieosuia.com'),
            'password' => env('SMTP_PASS', ''),
            'from_email' => env('SMTP_FROM_EMAIL', 'noreply@sms.ieosuia.com'),
            'from_name' => env('SMTP_FROM_NAME', 'IEOSUIA SMS Portal'),
        ];
    }

    /**
     * Generate test email HTML body
     */
    private function getTestEmailBody(string $type, string $fromName): string
    {
        $typeLabel = $type === 'system' ? 'System (Verification/Password Reset)' : 'Campaign (Email Campaigns)';
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
        .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
        .success { background: #10b981; color: white; padding: 15px 25px; border-radius: 5px; display: inline-block; font-weight: bold; }
        .info { background: #e0e7ff; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; color: #666; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✅ SMTP Test Successful!</h1>
        </div>
        <div class="content">
            <p class="success">Your SMTP configuration is working correctly!</p>
            
            <div class="info">
                <p><strong>Configuration Type:</strong> {$typeLabel}</p>
                <p><strong>From Name:</strong> {$fromName}</p>
                <p><strong>Test Time:</strong> {$this->getCurrentTime()}</p>
            </div>
            
            <p>This test email confirms that your {$type} email configuration is set up correctly and can send emails.</p>
            
            <p>You can now use this configuration to:</p>
            <ul>
HTML;
        
        if ($type === 'system') {
            $html = <<<HTML
                <li>Send email verification emails</li>
                <li>Send password reset emails</li>
                <li>Send account notifications</li>
HTML;
        } else {
            $html = <<<HTML
                <li>Send email campaigns</li>
                <li>Send bulk marketing emails</li>
                <li>Send newsletter communications</li>
HTML;
        }
        
        return $html . <<<HTML
            </ul>
        </div>
        <div class="footer">
            <p>This is an automated test email from IEOSUIA SMS Portal</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function getCurrentTime(): string
    {
        return date('Y-m-d H:i:s T');
    }
}
