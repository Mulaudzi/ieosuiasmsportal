<?php
// Rendering only. Never initializes SMTP or uses a real challenge/code.
require_once __DIR__.'/../services/EmailService.php';
$render = new ReflectionMethod(EmailService::class, 'renderSmsLoginCode');
$n = 0;
function mailCheck(bool $ok, string $why): void { global $n; $n++; if (!$ok) throw new RuntimeException($why); }
foreach (['login', 'enable', 'disable', 'deployment_test'] as $purpose) {
    $v = $render->invoke(null, '012345', $purpose);
    mailCheck(str_contains($v['html'], '012345') && str_contains($v['text'], '012345'), 'Leading zero code preserved in HTML and text');
    mailCheck(str_contains($v['html'], 'width=device-width') && str_contains($v['html'], 'max-width:560px'), 'Fluid layout');
    mailCheck(!preg_match('/<(img|script|iframe|form)\b/i', $v['html']), 'No tracking, forms or external rendering dependencies');
    mailCheck(!str_contains($v['html'], 'password reset'), 'No misleading password reset footer');
    mailCheck(str_contains($v['text'], 'security@ieosuia.com'), 'Account security help included in plain text');
    if ($purpose === 'deployment_test') {
        mailCheck(str_contains($v['text'], 'cannot sign you in') && !str_contains($v['text'], 'Expires in'), 'Sample cannot be mistaken for a live challenge');
    } else {
        mailCheck(str_contains($v['text'], '10 minutes') && str_contains($v['text'], 'Use once') && str_contains($v['text'], 'Never share'), 'Expiry and security guidance');
        mailCheck(str_contains($v['text'], match($purpose) {'login'=>'finish signing in','enable'=>'enable your extra','disable'=>'disable your extra'}), 'Correct purpose');
    }
}
foreach (['12345','1234567','12345x','123456' . "\n",'<code>'] as $bad) {
    try { $render->invoke(null, $bad, 'login'); mailCheck(false, 'Invalid code accepted'); }
    catch (InvalidArgumentException $e) { mailCheck(!str_contains($e->getMessage(), $bad), 'No code in exception'); }
}
try { $render->invoke(null, '012345', '<img>'); mailCheck(false, 'Invalid purpose accepted'); }
catch (InvalidArgumentException $e) { mailCheck(true, 'Unknown purpose rejected'); }
mailCheck((new ReflectionProperty(EmailService::class, 'mailer'))->getValue() === null, 'SMTP never initialized');
if (isset($argv[1])) {
    $preview = $render->invoke(null, '012345', 'login');
    file_put_contents($argv[1], $preview['html']);
}
echo "PASS: $n security email rendering checks. No email sent.\n";
