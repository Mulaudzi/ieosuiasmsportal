<?php
require_once __DIR__.'/../domain/EmailCampaignService.php';

final class EmailCampaignController
{
    private function service(): EmailCampaignService
    {
        if (!EmailCampaignPolicy::enabled()) throw new EmailCampaignException('Email campaigns are not enabled yet. SMS and account emails are unchanged.',503);
        return new EmailCampaignService(db());
    }
    private function respond(callable $operation): void
    {
        header('Cache-Control: no-store');
        try {Response::success($operation());}
        catch(EmailCampaignException $e) {Response::error($e->getMessage(),$e->status);}
        catch(Throwable $e) {error_log('email_campaign_api_failure class='.get_class($e));Response::error('Email campaigns are temporarily unavailable. No automatic resubmission will occur.',503);}
    }
    private function actor(): int
    {
        $user=Auth::user();
        if (!$user || empty($user['email_verified_at'])) throw new EmailCampaignException('Verify your portal email address first.',403);
        return (int)$user['id'];
    }
    private function throttle(): void {RateLimiter::checkOrFail('email_campaign:'.Auth::id(),20,1);}
    public function limits(): void
    {
        $this->respond(static fn()=>['enabled'=>EmailCampaignPolicy::enabled(),'emails_per_credit'=>5,'readiness'=>EmailCampaignPolicy::readiness(),'max_recipients'=>min(10000,max(1,(int)env('EMAIL_CAMPAIGN_MAX_RECIPIENTS',1000))),'sender_policy'=>'Approved business name via IEOSUIA; replies to your verified account email.','billing_basis'=>'0.2 SMS credit per SMTP-accepted email. Acceptance is not inbox delivery.']);
    }
    public function sender(): void {$this->respond(fn()=>['sender'=>$this->service()->sender($this->actor())]);}
    public function saveSender(): void {$this->throttle();$this->respond(fn()=>['sender'=>$this->service()->saveSender($this->actor(),Request::all())]);}
    public function index(): void {$this->respond(fn()=>['campaigns'=>$this->service()->listing($this->actor())]);}
    public function quote(): void {$this->throttle();$this->respond(fn()=>['quote'=>$this->service()->quote($this->actor(),Request::all())]);}
    public function create(): void {$this->throttle();$this->respond(fn()=>['campaign'=>$this->service()->create($this->actor(),Request::all())]);}
    public function show(array $p): void {$this->respond(fn()=>['campaign'=>$this->service()->detail($this->actor(),$p['id'])]);}
    public function send(array $p): void {$this->throttle();$this->respond(fn()=>['campaign'=>$this->service()->queue($this->actor(),$p['id'])]);}
    public function cancel(array $p): void {$this->respond(fn()=>['campaign'=>$this->service()->cancel($this->actor(),$p['id'])]);}
    public function adminIndex(): void {$this->respond(fn()=>$this->service()->adminOverview($this->actor()));}
    public function adminShow(array $p): void {$this->respond(fn()=>['campaign'=>$this->service()->detail($this->actor(),$p['id'],true)]);}
    public function review(array $p): void {$this->throttle();$this->respond(fn()=>['campaign'=>$this->service()->review($this->actor(),$p['id'],Request::all())]);}
    public function reviewSender(array $p): void
    {
        $this->throttle();$this->respond(function()use($p) {
            if (!preg_match('/^[1-9][0-9]{0,17}$/D',$p['id']))throw new EmailCampaignException('Sender not found.',404);
            return ['sender'=>$this->service()->reviewSender($this->actor(),(int)$p['id'],Request::all())];
        });
    }
    public function preferences(array $p): void
    {
        // Public bearer capability, NOT a general-purpose bounce webhook. GET is scanner-safe.
        header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        header('Content-Type: text/html; charset=utf-8');
        try {
            // Unsubscribe must remain available even when campaign sending is disabled.
            $service=new EmailCampaignService(db());$token=(string)Request::query('token','');
            $post=($_SERVER['REQUEST_METHOD']??'GET')==='POST';
            $report=Request::query('report')==='1';
            $service->unsubscribe($p['id'],$token,$post,$report);
            if($post) echo '<!doctype html><html><title>Email preferences saved</title><h1>You are unsubscribed</h1><p>No further campaigns from this sender will be sent to this address. An email already in transit may still arrive.</p>'.($report?'<p>Your abuse report has been recorded and sending paused for review.</p>':'').'</html>';
            else echo '<!doctype html><html><title>Email preferences</title><h1>'.($report?'Report unwanted email':'Unsubscribe').'</h1><p>This applies to email campaigns from this sender, not your transactional account emails or SMS preferences.</p><form method="post"><button type="submit">'.($report?'Report abuse and unsubscribe':'Confirm unsubscribe').'</button></form></html>';
        } catch(Throwable $e) {http_response_code($e instanceof EmailCampaignException?$e->status:503);echo '<!doctype html><html><title>Preference link unavailable</title><h1>This link is unavailable</h1><p>Contact sms@ieosuia.com for help with unwanted email.</p></html>';}
        exit;
    }
    public function feedback(): void
    {
        $this->respond(static function() {
            require_once __DIR__.'/../domain/EmailCampaignWebhook.php';
            $raw=(string)file_get_contents('php://input',false,null,0,32769);
            $event=EmailCampaignWebhook::verify($raw,(string)($_SERVER['HTTP_X_EMAIL_TIMESTAMP']??''),(string)($_SERVER['HTTP_X_EMAIL_SIGNATURE']??''));
            return (new EmailCampaignService(db()))->feedback($event,hash('sha256',$raw));
        });
    }
}
