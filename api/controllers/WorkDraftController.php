<?php
require_once __DIR__.'/../domain/WorkDraftService.php';
final class WorkDraftController
{
    private function respond(callable $operation): void
    {
        header('Cache-Control: no-store');
        try {
            try {db()->query('SELECT id FROM work_drafts LIMIT 0');}
            catch (PDOException $e) {Response::error('Account drafts are not available yet. Keep this form open or use its browser recovery until setup is complete.',503);return;}
            Response::success($operation());
        } catch (WorkDraftException $e) {Response::error($e->getMessage(),$e->status);}
        catch (InvalidArgumentException $e) {Response::error($e->getMessage(),422);}
    }
    public function index(): void { $this->respond(static fn()=>['drafts'=>WorkDraftService::listing(db(),(int)Auth::id(),WorkDraftService::kind(Request::query('kind')))]); }
    public function show(array $p): void { $this->respond(static fn()=>['draft'=>WorkDraftService::get(db(),(int)Auth::id(),$p['id'])]); }
    public function save(array $p): void { $this->respond(static fn()=>['draft'=>WorkDraftService::save(db(),(int)Auth::id(),$p['id'],Request::all())]); }
    public function destroy(array $p): void { $this->respond(static function()use($p){WorkDraftService::delete(db(),(int)Auth::id(),$p['id'],Request::input('expected_version'));return ['deleted'=>true];}); }
}
