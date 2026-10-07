<?php
// Pure routing/subject checks. No database, SMTP connection or real email is used.
require_once __DIR__ . '/../controllers/ContactFormController.php';

function checkContact($condition, string $description): void {
    if (!$condition) { throw new RuntimeException($description); }
    echo "PASS {$description}\n";
}

$recipient = new ReflectionMethod(ContactFormController::class, 'recipientForPurpose');
$recipient->setAccessible(true);
foreach (['general', 'support', 'sales'] as $purpose) {
    checkContact($recipient->invoke(null, $purpose) === 'support@ieosuia.com', "{$purpose} routes to support");
}
foreach (['', 'admin', 'SUPPORT', 'attacker@example.invalid'] as $purpose) {
    checkContact($recipient->invoke(null, $purpose) === null, 'Unknown purpose rejected');
}

$subject = new ReflectionMethod(ContactFormController::class, 'siteSubject');
$subject->setAccessible(true);
$actual = $subject->invoke(null, 'Support Request - Test Visitor');
checkContact($actual === '[IEOSUIA SMS Portal | sms.ieosuia.com] Support Request - Test Visitor', 'Subject identifies the actual site');
$injection = $subject->invoke(null, "Test\r\nBcc: attacker@example.invalid");
checkContact(!str_contains($injection, "\r") && !str_contains($injection, "\n"), 'Subject cannot contain additional header lines');
checkContact(str_starts_with($injection, '[IEOSUIA SMS Portal | sms.ieosuia.com] '), 'Visitor content cannot replace the site prefix');
