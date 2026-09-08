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
        if (strtolower((string) env('ADMIN_BOOTSTRAP_ENABLED', 'false')) !== 'true') {
            Response::error('Admin bootstrap is disabled', 404);
        }
        if (table('users')->where('role', 'admin')->first() || table('admin_users')->first()) {
            Response::error('Admin bootstrap has already been completed', 409);
        }
        $data = Request::validate([
            'email' => 'required|email|max:255',
            'password' => 'required|min:8|max:255',
            'pin' => 'required|min:4|max:12',
            'name' => 'required|min:2|max:100',
            'setup_key' => 'required|max:100',
        ]);
        
        // Verify setup key (temporary security measure)
        $setupKey = trim((string) env('ADMIN_SETUP_KEY', ''));
        if (strlen($setupKey) < 24) {
            Response::error('Admin bootstrap is not configured', 503);
        }
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
        
        if (!preg_match('/^\d{4,12}$/', (string) $data['pin'])) {
            Response::error('PIN must contain 4 to 12 digits', 422);
        }
        
        $adminId = table('admin_users')->insert([
            'email' => $data['email'],
            'password' => Auth::hashPassword($data['password']),
            'pin_hash' => Auth::hashPassword($data['pin']),
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
     * Public credential maintenance is intentionally unavailable.
     */
    public function updatePassword(): void {
        Response::error('Public admin password maintenance is disabled', 404);
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
            'new_password' => 'required|min:8|max:255',
            'new_pin' => 'min:4|max:12',
        ]);
        
        $admin = table('admin_users')->where('id', $data['id'])->first();
        if (!$admin) {
            Response::error('Admin user not found', 404);
            return;
        }
        
        $updates = [
            'password' => Auth::hashPassword($data['new_password']),
            'failed_attempts' => 0,
            'locked_until' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (!empty($data['new_pin'])) {
            if (!preg_match('/^\d{4,12}$/', (string) $data['new_pin'])) {
                Response::error('PIN must contain 4 to 12 digits', 422);
            }
            $updates['pin_hash'] = Auth::hashPassword($data['new_pin']);
        }
        table('admin_users')->where('id', $data['id'])->update($updates);
        
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
     * Check if email belongs to an admin user (legacy internal helper)
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
     * Authenticate an administrator with a password and numeric PIN.
     * Returns array with admin data on success, or array with error info on failure
     */
    public static function authenticate(string $email, string $password, string $pin): array {
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
        
        // The fallback keeps login available during the short deployment window
        // before migration 003 drops the legacy columns.
        $storedPassword = (string) ($admin['password'] ?? $admin['password_1'] ?? '');
        $storedPin = (string) ($admin['pin_hash'] ?? $admin['password_2'] ?? '');
        $allPasswordsValid =
            $pin !== '' &&
            password_verify($password, $storedPassword) &&
            password_verify($pin, $storedPin);
        
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
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        return [
            'success' => true,
            'admin' => $admin,
        ];
    }
}
