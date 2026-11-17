<?php
require_once __DIR__.'/../domain/ContactTagService.php';
final class ContactTagController
{
    private function respond(callable $f):void
    {
        header('Cache-Control: no-store');try{Response::success($f());}
        catch(ContactTagException $e){Response::error($e->getMessage(),$e->status);}
        catch(WorkDraftException $e){Response::error($e->getMessage(),$e->status);}
        catch(InvalidArgumentException $e){Response::error($e->getMessage(),422);}
        catch(Throwable $e){error_log('Contact tag failure class='.get_class($e));Response::error('Contact tags could not be updated. Refresh and try again. Your SMS and email sending were not started.',503);}
    }
    private function throttle():void{RateLimiter::checkOrFail('contact_tags:'.Auth::id(),30,1);}
    public function index():void{$this->respond(fn()=>['tags'=>ContactTagService::listing(db(),(int)Auth::id())]);}
    public function create():void{$this->throttle();$this->respond(fn()=>['tag'=>ContactTagService::save(db(),(int)Auth::id(),Request::input('name'))]);}
    public function update(array $p):void{$this->throttle();$this->respond(fn()=>['tag'=>ContactTagService::save(db(),(int)Auth::id(),Request::input('name'),$p['id'])]);}
    public function delete(array $p):void{$this->throttle();$this->respond(function()use($p){ContactTagService::remove(db(),(int)Auth::id(),$p['id']);return ['deleted'=>true];});}
    public function assign():void{$this->throttle();$this->respond(fn()=>ContactTagService::assign(db(),(int)Auth::id(),Request::all()));}
    public function draft():void{$this->throttle();$this->respond(fn()=>ContactTagService::smsDraft(db(),(int)Auth::id(),Request::all(),(string)Request::input('draft_id','')));}
}
