<?php
/**
 * Admin User Controller
 * Handles admin user management with database-driven authentication
 */

require_once __DIR__ . '/../services/AuditLogService.php';

class AdminUserController {
    
    /**
     * Create a new admin user
     */
    public function create(): void {
        $data = Request::validate([
            'email' => 'required|email|max:255',
            'password_1' => 'required|min:12|max:255',
            'password_2' => 'required|min:12|max:255',
            'password_3' => 'required|min:12|max:255',
            'name' => 'required|min:2|max:100',
            'setup_key' => 'required|max:100',
        ]);
        
        // Verify setup key (temporary security measure)
        $setupKey = env('ADMIN_SETUP_KEY', 'ieosuia-admin-setup-2024');
        if ($data['setup_key'] !== $setupKey) {
            Response::error('Invalid setup key', 403);
            return;
        }
        
        // Check if email already exists
        $existing = table('admin_users')->where('email', $data['email'])->first();
        if ($existing) {
            Response::error('Admin user with this email already exists', 400);
            return;
        }
        
        // Hash all 3 passwords using same method as regular users
        $hashedPassword1 = Auth::hashPassword($data['password_1']);
        $hashedPassword2 = Auth::hashPassword($data['password_2']);
        $hashedPassword3 = Auth::hashPassword($data['password_3']);
        
        // Create admin user with 3 passwords
        $adminId = table('admin_users')->insert([
            'email' => $data['email'],
            'password_1' => $hashedPassword1,
            'password_2' => $hashedPassword2,
            'password_3' => $hashedPassword3,
            'name' => $data['name'],
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Log admin creation
        AuditLogService::log('admin_user_created', 'security', $adminId, null, [
            'email' => $data['email'],
            'name' => $data['name'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ], null);
        
        Response::success([
            'message' => 'Admin user created successfully',
            'admin_id' => $adminId,
        ], 201);
    }
    
    /**
     * List all admin users (requires authenticated admin)
     */
    public function list(): void {
        Auth::check();
        
        if (!Auth::isAdmin()) {
            Response::error('Admin access required', 403);
            return;
        }
        
        $admins = table('admin_users')
            ->select(['id', 'email', 'name', 'is_active', 'last_login_at', 'created_at'])
            ->orderBy('created_at', 'DESC')
            ->get();
        
        Response::success(['admins' => $admins]);
    }
    
    /**
     * Update admin password
     */
    public function updatePassword(): void {
        $data = Request::validate([
            'email' => 'required|email',
            'current_password_1' => 'required',
            'current_password_2' => 'required',
            'current_password_3' => 'required',
            'new_password_1' => 'required|min:12|max:255',
            'new_password_2' => 'required|min:12|max:255',
            'new_password_3' => 'required|min:12|max:255',
            'setup_key' => 'required|max:100',
        ]);
        
        // Verify setup key
        $setupKey = env('ADMIN_SETUP_KEY', 'ieosuia-admin-setup-2024');
        if ($data['setup_key'] !== $setupKey) {
            Response::error('Invalid setup key', 403);
            return;
        }
        
        // Find admin user
        $admin = table('admin_users')->where('email', $data['email'])->first();
        if (!$admin) {
            Response::error('Admin user not found', 404);
            return;
        }
        
        // Verify all 3 current passwords
        if (!password_verify($data['current_password_1'], $admin['password_1'])) {
            Response::error('Current password 1 is incorrect', 401);
            return;
        }
        if (!password_verify($data['current_password_2'], $admin['password_2'])) {
            Response::error('Current password 2 is incorrect', 401);
            return;
        }
        if (!password_verify($data['current_password_3'], $admin['password_3'])) {
            Response::error('Current password 3 is incorrect', 401);
            return;
        }
        
        // Update all 3 passwords
        table('admin_users')->where('id', $admin['id'])->update([
            'password_1' => Auth::hashPassword($data['new_password_1']),
            'password_2' => Auth::hashPassword($data['new_password_2']),
            'password_3' => Auth::hashPassword($data['new_password_3']),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Log password change
        AuditLogService::log('admin_password_changed', 'security', $admin['id'], null, [
            'email' => $data['email'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ], null);
        
        Response::success(['message' => 'All passwords updated successfully']);
    }
    
    /**
     * Update admin user details (requires authenticated admin)
     */
    public function update(): void {
        Auth::check();
        
        if (!Auth::isAdmin()) {
            Response::error('Admin access required', 403);
            return;
        }
        
        $data = Request::validate([
            'id' => 'required',
            'name' => 'min:2|max:100',
            'email' => 'email|max:255',
        ]);
        
        $admin = table('admin_users')->where('id', $data['id'])->first();
        if (!$admin) {
            Response::error('Admin user not found', 404);
            return;
        }
        
        // Check for email conflicts
        if (isset($data['email']) && $data['email'] !== $admin['email']) {
            $existing = table('admin_users')->where('email', $data['email'])->first();
            if ($existing) {
                Response::error('Email already in use by another admin', 400);
                return;
            }
        }
        
        $updateData = ['updated_at' => date('Y-m-d H:i:s')];
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['email'])) $updateData['email'] = $data['email'];
        
        table('admin_users')->where('id', $data['id'])->update($updateData);
        
        // Log update
        AuditLogService::log('admin_user_updated', 'security', $data['id'], null, [
            'updated_fields' => array_keys($updateData),
            'updated_by' => Auth::id(),
        ], Auth::id());
        
        Response::success(['message' => 'Admin user updated successfully']);
    }
    
    /**
     * Toggle admin active status (requires authenticated admin)
     */
    public function toggleStatus(): void {
        Auth::check();
        
        if (!Auth::isAdmin()) {
            Response::error('Admin access required', 403);
            return;
        }
        
        $data = Request::validate([
            'id' => 'required',
        ]);
        
        $admin = table('admin_users')->where('id', $data['id'])->first();
        if (!$admin) {
            Response::error('Admin user not found', 404);
            return;
        }
        
        // Prevent self-deactivation
        $currentUserEmail = Auth::user()['email'] ?? null;
        if ($admin['email'] === $currentUserEmail) {
            Response::error('Cannot deactivate your own account', 400);
            return;
        }
        
        $newStatus = $admin['is_active'] ? 0 : 1;
        
        table('admin_users')->where('id', $data['id'])->update([
            'is_active' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $action = $newStatus ? 'activated' : 'deactivated';
        
        // Log status change
        AuditLogService::log("admin_user_{$action}", 'security', $data['id'], null, [
            'email' => $admin['email'],
            'changed_by' => Auth::id(),
        ], Auth::id());
        
        Response::success([
            'message' => "Admin user {$action} successfully",
            'is_active' => $newStatus,
        ]);
    }
    
    /**
     * Reset admin password (requires authenticated admin)
     */
    public function resetPasswordAdmin(): void {
        Auth::check();
        
        if (!Auth::isAdmin()) {
            Response::error('Admin access required', 403);
            return;
        }
        
        $data = Request::validate([
            'id' => 'required',
            'new_password' => 'required|min:12|max:255',
        ]);
        
        $admin = table('admin_users')->where('id', $data['id'])->first();
        if (!$admin) {
            Response::error('Admin user not found', 404);
            return;
        }
        
        table('admin_users')->where('id', $data['id'])->update([
            'password' => Auth::hashPassword($data['new_password']),
            'failed_attempts' => 0,
            'locked_until' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Log password reset
        AuditLogService::log('admin_password_reset', 'security', $data['id'], null, [
            'email' => $admin['email'],
            'reset_by' => Auth::id(),
        ], Auth::id());
        
        Response::success(['message' => 'Password reset successfully']);
    }
    
    /**
     * Delete admin user (requires authenticated admin)
     */
    public function delete(): void {
        Auth::check();
        
        if (!Auth::isAdmin()) {
            Response::error('Admin access required', 403);
            return;
        }
        
        $data = Request::validate([
            'id' => 'required',
        ]);
        
        $admin = table('admin_users')->where('id', $data['id'])->first();
        if (!$admin) {
            Response::error('Admin user not found', 404);
            return;
        }
        
        // Prevent self-deletion
        $currentUserEmail = Auth::user()['email'] ?? null;
        if ($admin['email'] === $currentUserEmail) {
            Response::error('Cannot delete your own account', 400);
            return;
        }
        
        table('admin_users')->where('id', $data['id'])->delete();
        
        // Log deletion
        AuditLogService::log('admin_user_deleted', 'security', $data['id'], null, [
            'email' => $admin['email'],
            'deleted_by' => Auth::id(),
        ], Auth::id());
        
        Response::success(['message' => 'Admin user deleted successfully']);
    }
    
    /**
     * Check if email belongs to an admin user (public endpoint for login form)
     */
    public function checkEmail(): void {
        $data = Request::validate([
            'email' => 'required|email|max:255',
        ]);
        
        try {
            $admin = table('admin_users')
                ->where('email', $data['email'])
                ->first();
            
            if (!$admin) {
                Response::success([
                    'is_admin' => false,
                ]);
                return;
            }
            
            // Calculate remaining attempts
            $remainingAttempts = max(0, 5 - ($admin['failed_attempts'] ?? 0));
            
            // Check if locked
            $lockedUntil = null;
            if ($admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
                $lockedUntil = $admin['locked_until'];
            }
            
            Response::success([
                'is_admin' => (bool) $admin['is_active'],
                'remaining_attempts' => $remainingAttempts,
                'locked_until' => $lockedUntil,
            ]);
        } catch (\Exception $e) {
            // Table might not exist yet - return not admin
            Response::success([
                'is_admin' => false,
            ]);
        }
    }
    
    /**
     * Check if email belongs to an admin user
     */
    public static function isAdminEmail(string $email): bool {
        try {
            $admin = table('admin_users')
                ->where('email', $email)
                ->where('is_active', 1)
                ->first();
            return $admin !== null;
        } catch (\Exception $e) {
            // Table might not exist yet
            return false;
        }
    }
    
    /**
     * Authenticate admin user with 3 passwords (used by login endpoint)
     * Returns array with admin data on success, or array with error info on failure
     */
    public static function authenticate(string $email, string $password1, string $password2, string $password3): array {
        $admin = table('admin_users')
            ->where('email', $email)
            ->where('is_active', 1)
            ->first();
        
        if (!$admin) {
            return ['success' => false, 'error' => 'Admin not found'];
        }
        
        // Check if account is locked
        if ($admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
            return [
                'success' => false, 
                'error' => 'Account locked',
                'locked_until' => $admin['locked_until'],
            ];
        }
        
        // Verify all 3 passwords together - don't reveal which one failed
        $allPasswordsValid = 
            password_verify($password1, $admin['password_1']) &&
            password_verify($password2, $admin['password_2']) &&
            password_verify($password3, $admin['password_3']);
        
        if (!$allPasswordsValid) {
            // Increment failed attempts
            $failedAttempts = ($admin['failed_attempts'] ?? 0) + 1;
            $lockUntil = null;
            
            // Lock after 5 failed attempts for 15 minutes
            if ($failedAttempts >= 5) {
                $lockUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            }
            
            table('admin_users')->where('id', $admin['id'])->update([
                'failed_attempts' => $failedAttempts,
                'locked_until' => $lockUntil,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            $remainingAttempts = max(0, 5 - $failedAttempts);
            
            return [
                'success' => false,
                'error' => 'Authentication failed',
                'remaining_attempts' => $remainingAttempts,
                'locked_until' => $lockUntil,
            ];
        }
        
        // Successful authentication - reset failed attempts and update login info
        table('admin_users')->where('id', $admin['id'])->update([
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        return [
            'success' => true,
            'admin' => $admin,
        ];
    }
}
