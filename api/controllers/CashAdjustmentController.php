<?php
require_once __DIR__.'/../domain/CustomerCashAdjustment.php';
final class CashAdjustmentController
{
 private function actor():int{$u=Auth::user();if(!$u||($u['role']??'')!=='admin'||empty($u['is_active'])||empty($u['email_verified_at']))throw new CashAdjustmentException('An active, verified administrator is required.',403);CustomerCashAdjustment::actor(db(),(int)$u['id']);return (int)$u['id'];}
 private function respond(callable $call):void
 {
  header('Cache-Control: private, no-store');
  try {$data=$call();}
  catch(CashAdjustmentException|CreditApprovalException $e){Response::error($e->getMessage(),$e->status);return;}
  catch(Throwable $e){error_log('Rand credit unavailable fingerprint='.substr(hash('sha256',get_class($e).':'.$e->getMessage()),0,16));Response::error('The rand credit could not be confirmed. Check this review status before trying again; do not create another adjustment.',503);return;}
  Response::success($data);
 }
 public function index():void{$this->respond(function(){$actor=$this->actor();$user=Request::query('user_id');if($user!==null&&(!is_string($user)||!preg_match('/^[1-9][0-9]{0,17}$/D',$user)))throw new CashAdjustmentException('Choose a valid customer.');return CustomerCashAdjustment::history(db(),$actor,$user===null?null:(int)$user,(int)Request::query('page',1));});}
 public function accounts():void{$this->respond(function(){$actor=$this->actor();RateLimiter::checkOrFail('rand_credit_search:'.$actor,30,1);$search=Request::query('search','');if(!is_string($search))throw new CashAdjustmentException('Enter a customer name or email.');return ['accounts'=>CustomerCashAdjustment::accounts(db(),$actor,$search)];});}
 public function quote():void{$this->respond(function(){$actor=$this->actor();RateLimiter::checkOrFail('rand_credit_quote:'.$actor,10,1);return ['quote'=>CustomerCashAdjustment::quote(db(),$actor,Request::all())];});}
 public function commit():void{$this->respond(function(){$actor=$this->actor();RateLimiter::checkOrFail('rand_credit_commit:'.$actor,5,1);if(array_diff(array_keys(Request::all()),['quote_id','master_password','confirmed']))throw new CashAdjustmentException('The reviewed rand credit cannot be changed during confirmation.');$id=Request::input('quote_id');if(!is_string($id))throw new CashAdjustmentException('Choose a reviewed adjustment.');return ['adjustment'=>CustomerCashAdjustment::execute(db(),$actor,$id,Request::input('master_password'),Request::input('confirmed')===true)];});}
 public function status(array $params):void{$this->respond(fn()=>CustomerCashAdjustment::status(db(),$this->actor(),(string)$params['id']));}
}
