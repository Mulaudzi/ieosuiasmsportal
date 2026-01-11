<?php
/**
 * Admin Controller - User management, stats, etc.
 */

require_once __DIR__ . '/../services/AuditLogService.php';

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
        
        $oldValues = ['is_active' => $user['is_active']];
        
        table('users')->where('id', $params['id'])->update([
            'is_active' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Log the action
        AuditLogService::log(
            'activate_user',
            'user',
            (int) $params['id'],
            $oldValues,
            ['is_active' => 1]
        );
        
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
        
        $oldValues = ['is_active' => $user['is_active']];
        
        table('users')->where('id', $params['id'])->update([
            'is_active' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Log the action
        AuditLogService::log(
            'deactivate_user',
            'user',
            (int) $params['id'],
            $oldValues,
            ['is_active' => 0]
        );
        
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
        
        $oldValues = ['role' => $user['role']];
        
        table('users')->where('id', $params['id'])->update([
            'role' => $data['role'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Log the action
        AuditLogService::log(
            'change_role',
            'user',
            (int) $params['id'],
            $oldValues,
            ['role' => $data['role']]
        );
        
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
    
    /**
     * Get audit logs
     */
    public function auditLogs(): void
    {
        $this->requireAdmin();
        
        $page = (int) Request::query('page', 1);
        $perPage = (int) Request::query('per_page', 50);
        
        $filters = [
            'action' => Request::query('action'),
            'entity_type' => Request::query('entity_type'),
            'user_id' => Request::query('user_id'),
            'from_date' => Request::query('from_date'),
            'to_date' => Request::query('to_date'),
        ];
        
        // Remove empty filters
        $filters = array_filter($filters);
        
        $logs = AuditLogService::getLogs($filters, $page, $perPage);
        $total = AuditLogService::getLogsCount($filters);
        
        Response::success([
            'logs' => $logs,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
            ],
        ]);
    }
    
    /**
     * Export audit logs as CSV
     */
    public function exportAuditLogs(): void
    {
        $this->requireAdmin();
        
        $format = Request::query('format', 'csv');
        
        $filters = [
            'action' => Request::query('action'),
            'entity_type' => Request::query('entity_type'),
            'user_id' => Request::query('user_id'),
            'from_date' => Request::query('from_date'),
            'to_date' => Request::query('to_date'),
        ];
        
        $filters = array_filter($filters);
        
        // Get all logs (up to 10000 for export)
        $logs = AuditLogService::getLogs($filters, 1, 10000);
        
        if ($format === 'csv') {
            $this->exportAuditLogsCSV($logs);
        } else {
            $this->exportAuditLogsPDF($logs);
        }
    }
    
    private function exportAuditLogsCSV(array $logs): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d_H-i-s') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // UTF-8 BOM for Excel
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        
        // Header row
        fputcsv($output, [
            'ID',
            'Date/Time',
            'Admin Name',
            'Admin Email',
            'Action',
            'Entity Type',
            'Entity ID',
            'Old Values',
            'New Values',
            'IP Address',
        ]);
        
        foreach ($logs as $log) {
            fputcsv($output, [
                $log['id'],
                $log['created_at'],
                $log['user_name'] ?? 'System',
                $log['user_email'] ?? '',
                ucwords(str_replace('_', ' ', $log['action'])),
                ucfirst($log['entity_type']),
                $log['entity_id'] ?? '',
                $log['old_values'] ?? '',
                $log['new_values'] ?? '',
                $log['ip_address'] ?? '',
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    private function exportAuditLogsPDF(array $logs): void
    {
        // Simple HTML-based PDF (using browser print)
        header('Content-Type: text/html; charset=utf-8');
        
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
        $html .= '<title>Audit Logs Export - ' . date('Y-m-d') . '</title>';
        $html .= '<style>
            body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
            h1 { font-size: 18px; margin-bottom: 10px; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f5f5f5; font-weight: bold; }
            tr:nth-child(even) { background-color: #fafafa; }
            .header { margin-bottom: 20px; }
            .meta { color: #666; font-size: 11px; }
            @media print { body { margin: 0; } }
        </style></head><body>';
        
        $html .= '<div class="header">';
        $html .= '<h1>IEOSUIA SMS Portal - Audit Logs Report</h1>';
        $html .= '<p class="meta">Generated: ' . date('Y-m-d H:i:s') . ' | Total Records: ' . count($logs) . '</p>';
        $html .= '</div>';
        
        $html .= '<table><thead><tr>';
        $html .= '<th>Date/Time</th><th>Admin</th><th>Action</th><th>Entity</th><th>Details</th><th>IP Address</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($logs as $log) {
            $details = '';
            if ($log['old_values'] && $log['new_values']) {
                $old = json_decode($log['old_values'], true);
                $new = json_decode($log['new_values'], true);
                if ($old && $new) {
                    foreach ($new as $key => $value) {
                        $oldVal = $old[$key] ?? 'N/A';
                        $details .= "{$key}: {$oldVal} → {$value}<br>";
                    }
                }
            }
            
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($log['created_at']) . '</td>';
            $html .= '<td>' . htmlspecialchars($log['user_name'] ?? 'System') . '<br><small>' . htmlspecialchars($log['user_email'] ?? '') . '</small></td>';
            $html .= '<td>' . htmlspecialchars(ucwords(str_replace('_', ' ', $log['action']))) . '</td>';
            $html .= '<td>' . htmlspecialchars(ucfirst($log['entity_type'])) . ' #' . htmlspecialchars($log['entity_id'] ?? 'N/A') . '</td>';
            $html .= '<td>' . $details . '</td>';
            $html .= '<td>' . htmlspecialchars($log['ip_address'] ?? 'N/A') . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '<script>window.onload = function() { window.print(); }</script>';
        $html .= '</body></html>';
        
        echo $html;
        exit;
    }
}
