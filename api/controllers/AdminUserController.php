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
            'password' => 'required|min:12|max:255',
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
        
        // Hash password using same method as regular users
        $hashedPassword = Auth::hashPassword($data['password']);
        
        // Create admin user
        $adminId = table('admin_users')->insert([
            'email' => $data['email'],
            'password' => $hashedPassword,
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
            'current_password' => 'required',
            'new_password' => 'required|min:12|max:255',
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
        
        // Verify current password
        if (!password_verify($data['current_password'], $admin['password'])) {
            Response::error('Current password is incorrect', 401);
            return;
        }
        
        // Update password
        table('admin_users')->where('id', $admin['id'])->update([
            'password' => Auth::hashPassword($data['new_password']),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Log password change
        AuditLogService::log('admin_password_changed', 'security', $admin['id'], null, [
            'email' => $data['email'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ], null);
        
        Response::success(['message' => 'Password updated successfully']);
    }
    
    /**
     * Authenticate admin user (used by login endpoint)
     */
    public static function authenticate(string $email, string $password): ?array {
        $admin = table('admin_users')
            ->where('email', $email)
            ->where('is_active', 1)
            ->first();
        
        if (!$admin) {
            return null;
        }
        
        // Check if account is locked
        if ($admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
            return null;
        }
        
        // Verify password
        if (!password_verify($password, $admin['password'])) {
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
            
            return null;
        }
        
        // Successful authentication - reset failed attempts and update login info
        table('admin_users')->where('id', $admin['id'])->update([
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        return $admin;
    }
}
