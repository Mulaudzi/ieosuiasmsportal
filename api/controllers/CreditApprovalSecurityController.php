<?php
require_once __DIR__.'/../domain/CreditApprovalSecurity.php';

final class CreditApprovalSecurityController
{
    private function admin(): array
    {
        $user=Auth::user();
        if (!$user || ($user['role']??'')!=='admin') Response::error('Unauthorized.',403);
        header('Cache-Control: no-store');
        return $user;
    }

    public function status(): void
    {
        $user=$this->admin();
        try { $status=CreditApprovalSecurity::status(db(),$user); }
        catch (CreditApprovalException $e) { Response::error($e->getMessage(),$e->status); }
        Response::success($status);
    }

    public function update(): void
    {
        $user=$this->admin();
        try { CreditApprovalSecurity::configure(db(),$user,Request::input('current_password'),Request::input('new_password'),Request::input('confirm_password')); }
        catch (CreditApprovalException $e) { Response::error($e->getMessage(),$e->status); }
        Response::success(['message'=>'Master password saved. Every manual credit approval requires this password.']);
    }
}
