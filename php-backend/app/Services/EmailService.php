<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use Illuminate\Support\Facades\Log;

class EmailService
{
    private PHPMailer $mailer;

    public function __construct()
    {
        $this->mailer = new PHPMailer(true);
        $this->configure();
    }

    private function configure(): void
    {
        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = config('mail.mailers.smtp.host', 'smtp.mailgun.org');
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = config('mail.mailers.smtp.username', '');
            $this->mailer->Password = config('mail.mailers.smtp.password', '');
            $this->mailer->SMTPSecure = config('mail.mailers.smtp.encryption', PHPMailer::ENCRYPTION_STARTTLS);
            $this->mailer->Port = config('mail.mailers.smtp.port', 587);

            // Default charset
            $this->mailer->CharSet = 'UTF-8';
            $this->mailer->Encoding = 'base64';

        } catch (Exception $e) {
            Log::error("PHPMailer configuration error: " . $e->getMessage());
        }
    }

    /**
     * Send an email
     */
    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $fromEmail = null,
        ?string $fromName = null,
        array $attachments = [],
        ?string $replyTo = null
    ): array {
        try {
            // Reset mailer for new email
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->clearReplyTos();

            // Sender
            $fromEmail = $fromEmail ?? config('mail.from.address', 'noreply@ieosuia.com');
            $fromName = $fromName ?? config('mail.from.name', 'IEOSUIA SMS Portal');
            $this->mailer->setFrom($fromEmail, $fromName);

            // Reply-to
            if ($replyTo) {
                $this->mailer->addReplyTo($replyTo);
            }

            // Recipient
            $this->mailer->addAddress($to);

            // Content
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $htmlBody;
            $this->mailer->AltBody = strip_tags($htmlBody);

            // Attachments
            foreach ($attachments as $attachment) {
                if (isset($attachment['path'])) {
                    $this->mailer->addAttachment(
                        $attachment['path'],
                        $attachment['name'] ?? basename($attachment['path'])
                    );
                } elseif (isset($attachment['content'])) {
                    $this->mailer->addStringAttachment(
                        $attachment['content'],
                        $attachment['name'] ?? 'attachment'
                    );
                }
            }

            // Send
            $this->mailer->send();

            Log::info("Email sent successfully", [
                'to' => $to,
                'subject' => $subject,
            ]);

            return [
                'success' => true,
                'messageId' => $this->mailer->getLastMessageID() ?: uniqid('email_'),
            ];

        } catch (Exception $e) {
            Log::error("Email sending failed", [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send bulk emails
     */
    public function sendBulk(array $emails): array
    {
        $results = [];

        foreach ($emails as $email) {
            $results[] = [
                'recipient' => $email['to'],
                'result' => $this->send(
                    $email['to'],
                    $email['subject'],
                    $email['body'],
                    $email['from_email'] ?? null,
                    $email['from_name'] ?? null
                ),
            ];

            // Small delay between emails
            usleep(50000); // 50ms
        }

        return $results;
    }

    /**
     * Validate email address
     */
    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Render template with variables
     */
    public static function renderTemplate(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace("{{$key}}", $value, $template);
            $template = str_replace("{{{$key}}}", $value, $template);
        }

        return $template;
    }
}
