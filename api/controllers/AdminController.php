<?php
/**
 * Admin Controller - User management, stats, etc.
 */

class AdminController
{
    /**
     * Check if user is admin
     */
    private function requireAdmin(): void
    {
        $user = Auth::user();
        if (!$user || $user['role'] !== 'admin') {
            Response::error('Unauthorized', 403);
        }
    }
    
    /**
     * Get admin dashboard stats
     */
    public function stats(): void
    {
        $this->requireAdmin();
        
        $pdo = db();
        
        // Total users
        $totalUsers = table('users')->count();
        
        // Active users
        $activeUsers = table('users')->where('is_active', 1)->count();
        
        // Pending sender IDs
        $pendingSenderIds = table('sender_ids')->where('status', 'pending')->count();
        
        // Total campaigns
        $totalCampaigns = table('campaigns')->count();
        
        // Total messages
        $totalMessages = table('messages')->count();
        
        // Total revenue (sum of credits purchased)
        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM wallet_transactions WHERE type = 'credit' AND status = 'completed'");
        $revenue = $stmt->fetch()['total'] ?? 0;
        
        Response::success([
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'pending_sender_ids' => $pendingSenderIds,
            'total_campaigns' => $totalCampaigns,
            'total_messages' => $totalMessages,
            'total_revenue' => (float) $revenue,
        ]);
    }
    
    /**
     * List all users
     */
    public function users(): void
    {
        $this->requireAdmin();
        
        $page = (int) Request::query('page', 1);
        $perPage = (int) Request::query('per_page', 50);
        $search = Request::query('search', '');
        
        $pdo = db();
        
        if ($search) {
            $stmt = $pdo->prepare("
                SELECT id, name, email, role, is_active, email_verified_at, created_at, last_login_at
                FROM users 
                WHERE name LIKE ? OR email LIKE ?
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ");
            $searchTerm = "%$search%";
            $stmt->execute([$searchTerm, $searchTerm, $perPage, ($page - 1) * $perPage]);
            $users = $stmt->fetchAll();
        } else {
            $users = table('users')
                ->select(['id', 'name', 'email', 'role', 'is_active', 'email_verified_at', 'created_at', 'last_login_at'])
                ->orderBy('created_at', 'DESC')
                ->limit($perPage)
                ->offset(($page - 1) * $perPage)
                ->get();
        }
        
        Response::success(['users' => $users]);
    }
    
    /**
     * Get all sender IDs (for admin)
     */
    public function senderIds(): void
    {
        $this->requireAdmin();
        
        $status = Request::query('status');
        
        $pdo = db();
        
        $sql = "
            SELECT s.*, u.name as user_name, u.email as user_email
            FROM sender_ids s
            LEFT JOIN users u ON s.user_id = u.id
        ";
        
        if ($status && in_array($status, ['pending', 'approved', 'rejected'])) {
            $sql .= " WHERE s.status = ?";
            $stmt = $pdo->prepare($sql . " ORDER BY s.created_at DESC");
            $stmt->execute([$status]);
        } else {
            $stmt = $pdo->query($sql . " ORDER BY s.created_at DESC");
        }
        
        $senderIds = $stmt->fetchAll();
        
        Response::success(['sender_ids' => $senderIds]);
    }
    
    /**
     * Activate a user
     */
    public function activateUser(array $params): void
    {
        $this->requireAdmin();
        
        $user = table('users')->where('id', $params['id'])->first();
        if (!$user) {
            Response::error('User not found', 404);
        }
        
        table('users')->where('id', $params['id'])->update([
            'is_active' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Response::success(['message' => 'User activated']);
    }
    
    /**
     * Deactivate a user
     */
    public function deactivateUser(array $params): void
    {
        $this->requireAdmin();
        
        $user = table('users')->where('id', $params['id'])->first();
        if (!$user) {
            Response::error('User not found', 404);
        }
        
        // Prevent self-deactivation
        if ((int) $user['id'] === Auth::id()) {
            Response::error('Cannot deactivate your own account', 400);
        }
        
        table('users')->where('id', $params['id'])->update([
            'is_active' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Response::success(['message' => 'User deactivated']);
    }
    
    /**
     * Change user role
     */
    public function changeRole(array $params): void
    {
        $this->requireAdmin();
        
        $user = table('users')->where('id', $params['id'])->first();
        if (!$user) {
            Response::error('User not found', 404);
        }
        
        $data = Request::validate([
            'role' => 'required|in:user,moderator,admin',
        ]);
        
        // Prevent changing own role
        if ((int) $user['id'] === Auth::id()) {
            Response::error('Cannot change your own role', 400);
        }
        
        table('users')->where('id', $params['id'])->update([
            'role' => $data['role'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Response::success(['message' => 'User role updated']);
    }
    
    /**
     * Get single user details
     */
    public function showUser(array $params): void
    {
        $this->requireAdmin();
        
        $user = table('users')
            ->where('id', $params['id'])
            ->first();
        
        if (!$user) {
            Response::error('User not found', 404);
        }
        
        // Remove sensitive fields
        unset($user['password'], $user['otp_code'], $user['email_verification_token']);
        
        // Get wallet info
        $wallet = table('wallets')->where('user_id', $params['id'])->first();
        $user['wallet'] = $wallet;
        
        // Get campaign count
        $user['campaign_count'] = table('campaigns')->where('user_id', $params['id'])->count();
        
        // Get message count
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM messages m
            JOIN campaigns c ON m.campaign_id = c.id
            WHERE c.user_id = ?
        ");
        $stmt->execute([$params['id']]);
        $user['message_count'] = $stmt->fetch()['count'] ?? 0;
        
        Response::success(['user' => $user]);
    }
}
