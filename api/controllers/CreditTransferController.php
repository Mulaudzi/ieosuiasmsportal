<?php
require_once __DIR__.'/../domain/CreditTransferService.php';

final class CreditTransferController
{
    private function respond(callable $call): void
    {
        header('Cache-Control: private, no-store');
        try{Response::success($call());}
        catch(CreditTransferException|CreditApprovalException $e){Response::error($e->getMessage(),$e->status);}
        catch(Throwable $e){error_log('Credit transfer unavailable class='.get_class($e));Response::error('Transfer could not be confirmed. Refresh the transfer history before trying again; do not create a second request.',503);}
    }
    private function admin(): int
    {
        $user=Auth::user();
        if(!$user||($user['role']??'')!=='admin'||(int)($user['is_active']??0)!==1||empty($user['email_verified_at']))throw new CreditTransferException('A verified active administrator is required.',403);
        CreditTransferService::actor(db(),(int)$user['id']);return (int)$user['id'];
    }
    public function index(): void
    {
        $this->respond(function(){ $actor=$this->admin();$history=CreditTransferService::history(db(),$actor,true,(int)Request::query('page',1));$history['security']=CreditApprovalSecurity::status(db(),Auth::user());return $history; });
    }
    public function accounts(): void
    {
        $this->respond(function(){ $actor=$this->admin();RateLimiter::checkOrFail('credit_transfer_search:'.$actor,30,1);$search=Request::query('search','');if(!is_string($search))throw new CreditTransferException('Enter a customer name or email.');return ['accounts'=>CreditTransferService::accounts(db(),$actor,$search)]; });
    }
    public function quote(): void
    {
        $this->respond(function(){ $actor=$this->admin();RateLimiter::checkOrFail('credit_transfer_quote:'.$actor,10,1);return ['quote'=>CreditTransferService::quote(db(),$actor,Request::all())]; });
    }
    public function commit(): void
    {
        $this->respond(function(){ $actor=$this->admin();RateLimiter::checkOrFail('credit_transfer_commit:'.$actor,5,1);if(array_diff(array_keys(Request::all()),['quote_id','master_password','confirmed']))throw new CreditTransferException('The reviewed transfer cannot be changed during confirmation.');$id=Request::input('quote_id');if(!is_string($id))throw new CreditTransferException('Choose a reviewed transfer.');return ['transfer'=>CreditTransferService::execute(db(),$actor,$id,Request::input('master_password'),Request::input('confirmed')===true)]; });
    }
    public function customerHistory(): void
    {
        $this->respond(fn()=>CreditTransferService::history(db(),(int)Auth::id(),false,(int)Request::query('page',1)));
    }
    public function status(array $params): void
    {
        $this->respond(fn()=>CreditTransferService::status(db(),$this->admin(),(string)$params['id']));
    }
    private function customer(): int
    {
        $u=Auth::user();
        if(!$u||($u['role']??'')==='admin'||(int)($u['is_active']??0)!==1||empty($u['email_verified_at']))throw new CreditTransferException('An active, verified customer account is required.',403);
        return (int)$u['id'];
    }
    public function customerQuote(): void
    {
        $this->respond(function(){ $id=$this->customer();RateLimiter::checkOrFail('customer_transfer_quote:'.$id,10,1);return ['quote'=>CreditTransferService::customerQuote(db(),$id,Request::all())]; });
    }
    public function customerChallenge(): void
    {
        $this->respond(function(){
            $actor=$this->customer();RateLimiter::checkOrFail('customer_transfer_challenge:'.$actor,3,15);
            if(array_diff(array_keys(Request::all()),['quote_id']))throw new CreditTransferException('Unsupported verification fields.');
            $id=Request::input('quote_id');if(!is_string($id))throw new CreditTransferException('Choose a reviewed transfer.');
            require_once __DIR__.'/../services/EmailService.php';
            return CreditTransferService::challenge(db(),$actor,$id,[EmailService::class,'sendCreditTransferCode']);
        });
    }
    public function customerCommit(): void
    {
        $this->respond(function(){ $actor=$this->customer();RateLimiter::checkOrFail('customer_transfer_commit:'.$actor,10,1);if(array_diff(array_keys(Request::all()),['quote_id','verification_code','confirmed']))throw new CreditTransferException('The reviewed transfer cannot be changed during confirmation.');$id=Request::input('quote_id');if(!is_string($id))throw new CreditTransferException('Choose a reviewed transfer.');return ['transfer'=>CreditTransferService::execute(db(),$actor,$id,Request::input('verification_code'),Request::input('confirmed')===true,true)]; });
    }
    public function customerStatus(array $params): void
    {
        $this->respond(fn()=>CreditTransferService::status(db(),$this->customer(),(string)$params['id'],true));
    }
}
