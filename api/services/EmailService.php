<?php
/**
 * Email Service - SMTP via PHPMailer or native mail()
 */

class EmailService {
    public function send(string $to, string $subject, string $body): array {
        $fromEmail = env('MAIL_FROM_ADDRESS', 'noreply@ieosuia.com');
        $fromName = env('MAIL_FROM_NAME', 'IEOSUIA SMS Portal');
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            "From: $fromName <$fromEmail>",
            "Reply-To: $fromEmail",
        ];
        
        $result = mail($to, $subject, $body, implode("\r\n", $headers));
        
        if ($result) {
            return ['success' => true, 'message_id' => uniqid('email_')];
        }
        
        return ['success' => false, 'error' => 'Failed to send email'];
    }
}
