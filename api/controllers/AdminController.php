<?php
/**
 * Admin Controller - User management, stats, etc.
 */

require_once __DIR__ . '/../services/AuditLogService.php';

class AdminController
{
    /**
     * Check if user is admin
     * 
     * FIXED: Changed from checking non-existent 'role' column to 'account_type' column
     * This was a critical bug that prevented admin authorization from working.
     * 
     * @return void
     * @throws Response::error if user is not admin
     */
    private function requireAdmin(): void
    {
        $user = Auth::user();
        // FIXED: Use account_type instead of role (role column doesn't exist in database)
        if (!$user || ($user['account_type'] ?? 'standard') !== 'admin') {
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
        
        // Sender IDs removed - not used
        $pendingSenderIds = 0;
        
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
            // FIXED: Changed from 'role' to 'account_type' column
            $stmt = $pdo->prepare("
                SELECT id, name, email, account_type, is_active, email_verified_at, created_at, last_login_at
                FROM users 
                WHERE name LIKE ? OR email LIKE ?
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ");
            $searchTerm = "%$search%";
            $stmt->execute([$searchTerm, $searchTerm, $perPage, ($page - 1) * $perPage]);
            $users = $stmt->fetchAll();
        } else {
            // FIXED: Changed from 'role' to 'account_type' column
            $users = table('users')
                ->select(['id', 'name', 'email', 'account_type', 'is_active', 'email_verified_at', 'created_at', 'last_login_at'])
                ->orderBy('created_at', 'DESC')
                ->limit($perPage)
                ->offset(($page - 1) * $perPage)
                ->get();
        }
        
        Response::success(['users' => $users]);
    }
    
    // Sender ID functionality removed
    
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
     * Change user account type (role)
     * 
     * FIXED: Updated to use 'account_type' column instead of non-existent 'role' column.
     * The input parameter is still called 'role' for API compatibility, but it maps to 'account_type' in database.
     * 
     * @param array $params Route parameters containing user ID
     * @return void
     */
    public function changeRole(array $params): void
    {
        $this->requireAdmin();
        
        $user = table('users')->where('id', $params['id'])->first();
        if (!$user) {
            Response::error('User not found', 404);
        }
        
        // Note: Input field is 'role' but maps to 'account_type' column in database
        $data = Request::validate([
            'role' => 'required|in:standard,individual,business,organization,admin',
        ]);
        
        // Prevent changing own account type
        if ((int) $user['id'] === Auth::id()) {
            Response::error('Cannot change your own account type', 400);
        }
        
        $oldValues = ['account_type' => $user['account_type'] ?? 'standard'];
        
        // FIXED: Update account_type column (not role)
        table('users')->where('id', $params['id'])->update([
            'account_type' => $data['role'], // Maps input 'role' to database 'account_type'
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Log the action
        AuditLogService::log(
            'change_role',
            'user',
            (int) $params['id'],
            $oldValues,
            ['account_type' => $data['role']]
        );
        
        Response::success(['message' => 'User account type updated']);
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
    
    /**
     * Get system health status
     */
    public function systemHealth(): void
    {
        $this->requireAdmin();
        
        $health = [
            'database' => $this->checkDatabaseHealth(),
            'smtp' => $this->checkSmtpHealth(),
            'api' => $this->checkApiHealth(),
            'storage' => $this->checkStorageHealth(),
        ];
        
        // Overall status
        $statuses = array_column($health, 'status');
        if (in_array('error', $statuses)) {
            $health['overall'] = 'error';
        } elseif (in_array('warning', $statuses)) {
            $health['overall'] = 'warning';
        } else {
            $health['overall'] = 'healthy';
        }
        
        Response::success(['health' => $health]);
    }
    
    private function checkDatabaseHealth(): array
    {
        $start = microtime(true);
        try {
            $pdo = db();
            $stmt = $pdo->query("SELECT 1");
            $stmt->fetch();
            $responseTime = round((microtime(true) - $start) * 1000, 2);
            
            // Get some stats
            $userCount = table('users')->count();
            $messageCount = table('messages')->count();
            
            return [
                'status' => $responseTime < 100 ? 'healthy' : ($responseTime < 500 ? 'warning' : 'error'),
                'response_time_ms' => $responseTime,
                'message' => 'Database connected',
                'details' => [
                    'users' => $userCount,
                    'messages' => $messageCount,
                ],
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'response_time_ms' => null,
                'message' => 'Database connection failed',
                'error' => $e->getMessage(),
            ];
        }
    }
    
    private function checkSmtpHealth(): array
    {
        try {
            // Check if SMTP settings exist and were recently tested
            $smtpSettings = table('smtp_settings')
                ->where('setting_type', 'system')
                ->where('is_active', 1)
                ->first();
            
            if (!$smtpSettings) {
                return [
                    'status' => 'warning',
                    'message' => 'SMTP not configured',
                    'last_tested' => null,
                ];
            }
            
            $lastTestResult = $smtpSettings['last_test_result'] ?? null;
            $lastTestedAt = $smtpSettings['last_tested_at'] ?? null;
            
            return [
                'status' => $lastTestResult === 'success' ? 'healthy' : ($lastTestResult === 'failed' ? 'error' : 'warning'),
                'message' => $lastTestResult === 'success' ? 'SMTP connected' : ($lastTestResult === 'failed' ? 'SMTP test failed' : 'SMTP not tested'),
                'last_tested' => $lastTestedAt,
                'host' => $smtpSettings['host'] ?? null,
                'error' => $smtpSettings['last_test_error'] ?? null,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error checking SMTP',
                'error' => $e->getMessage(),
            ];
        }
    }
    
    private function checkApiHealth(): array
    {
        // Measure time for a simple operation
        $start = microtime(true);
        $auth = Auth::user();
        $responseTime = round((microtime(true) - $start) * 1000, 2);
        
        // Get recent error rate from audit logs (last hour)
        $pdo = db();
        $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN action LIKE '%failed%' OR action LIKE '%error%' THEN 1 ELSE 0 END) as errors
            FROM audit_logs
            WHERE created_at >= ?
        ");
        $stmt->execute([$oneHourAgo]);
        $stats = $stmt->fetch();
        
        $errorRate = $stats['total'] > 0 ? round(($stats['errors'] / $stats['total']) * 100, 1) : 0;
        
        return [
            'status' => $responseTime < 50 && $errorRate < 5 ? 'healthy' : ($responseTime < 200 && $errorRate < 20 ? 'warning' : 'error'),
            'response_time_ms' => $responseTime,
            'message' => 'API responsive',
            'details' => [
                'requests_last_hour' => (int) $stats['total'],
                'error_rate' => $errorRate . '%',
            ],
        ];
    }
    
    private function checkStorageHealth(): array
    {
        $uploadDir = __DIR__ . '/../uploads';
        
        if (!is_dir($uploadDir)) {
            return [
                'status' => 'warning',
                'message' => 'Upload directory not found',
            ];
        }
        
        $isWritable = is_writable($uploadDir);
        $freeSpace = disk_free_space($uploadDir);
        $totalSpace = disk_total_space($uploadDir);
        $usedPercent = $totalSpace > 0 ? round((($totalSpace - $freeSpace) / $totalSpace) * 100, 1) : 0;
        
        return [
            'status' => $isWritable && $usedPercent < 80 ? 'healthy' : ($isWritable && $usedPercent < 95 ? 'warning' : 'error'),
            'message' => $isWritable ? 'Storage accessible' : 'Storage not writable',
            'details' => [
                'free_space_gb' => round($freeSpace / 1073741824, 2),
                'total_space_gb' => round($totalSpace / 1073741824, 2),
                'used_percent' => $usedPercent . '%',
            ],
        ];
    }
    
    /**
     * Get activity heatmap data
     */
    public function activityHeatmap(): void
    {
        $this->requireAdmin();
        
        $pdo = db();
        $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
        
        // Registration activity by hour and day of week
        $stmt = $pdo->prepare("
            SELECT 
                HOUR(created_at) as hour,
                DAYOFWEEK(created_at) as day_of_week,
                COUNT(*) as count
            FROM users
            WHERE created_at >= ?
            GROUP BY HOUR(created_at), DAYOFWEEK(created_at)
        ");
        $stmt->execute([$thirtyDaysAgo]);
        $registrations = $stmt->fetchAll();
        
        // Campaign activity by hour and day of week
        $stmt = $pdo->prepare("
            SELECT 
                HOUR(created_at) as hour,
                DAYOFWEEK(created_at) as day_of_week,
                COUNT(*) as count
            FROM campaigns
            WHERE created_at >= ?
            GROUP BY HOUR(created_at), DAYOFWEEK(created_at)
        ");
        $stmt->execute([$thirtyDaysAgo]);
        $campaigns = $stmt->fetchAll();
        
        // Messages by hour and day of week  
        $stmt = $pdo->prepare("
            SELECT 
                HOUR(sent_at) as hour,
                DAYOFWEEK(sent_at) as day_of_week,
                COUNT(*) as count
            FROM messages
            WHERE sent_at >= ? AND sent_at IS NOT NULL
            GROUP BY HOUR(sent_at), DAYOFWEEK(sent_at)
        ");
        $stmt->execute([$thirtyDaysAgo]);
        $messages = $stmt->fetchAll();
        
        // Delivered messages by hour and day of week (for delivery success heatmap)
        $stmt = $pdo->prepare("
            SELECT 
                HOUR(sent_at) as hour,
                DAYOFWEEK(sent_at) as day_of_week,
                COUNT(*) as count
            FROM messages
            WHERE sent_at >= ? AND sent_at IS NOT NULL AND status = 'Delivered'
            GROUP BY HOUR(sent_at), DAYOFWEEK(sent_at)
        ");
        $stmt->execute([$thirtyDaysAgo]);
        $delivered = $stmt->fetchAll();
        
        // Failed messages by hour and day of week
        $stmt = $pdo->prepare("
            SELECT 
                HOUR(sent_at) as hour,
                DAYOFWEEK(sent_at) as day_of_week,
                COUNT(*) as count
            FROM messages
            WHERE sent_at >= ? AND sent_at IS NOT NULL AND status = 'Failed'
            GROUP BY HOUR(sent_at), DAYOFWEEK(sent_at)
        ");
        $stmt->execute([$thirtyDaysAgo]);
        $failed = $stmt->fetchAll();
        
        // Calculate delivery rate by hour and day
        $deliveryRates = $this->buildDeliveryRateGrid($messages, $delivered);
        
        // Format into heatmap grid (7 days x 24 hours)
        $registrationGrid = $this->buildHeatmapGrid($registrations);
        $campaignGrid = $this->buildHeatmapGrid($campaigns);
        $messageGrid = $this->buildHeatmapGrid($messages);
        $deliveredGrid = $this->buildHeatmapGrid($delivered);
        $failedGrid = $this->buildHeatmapGrid($failed);
        
        Response::success([
            'heatmap' => [
                'registrations' => $registrationGrid,
                'campaigns' => $campaignGrid,
                'messages' => $messageGrid,
                'delivered' => $deliveredGrid,
                'failed' => $failedGrid,
                'delivery_rates' => $deliveryRates,
            ],
            'period' => '30 days',
        ]);
    }
    
    private function buildHeatmapGrid(array $data): array
    {
        // Initialize 7x24 grid (Sunday=1 to Saturday=7, hours 0-23)
        $grid = [];
        for ($day = 1; $day <= 7; $day++) {
            $grid[$day] = array_fill(0, 24, 0);
        }
        
        // Fill with data
        foreach ($data as $row) {
            $day = (int) $row['day_of_week'];
            $hour = (int) $row['hour'];
            $grid[$day][$hour] = (int) $row['count'];
        }
        
        // Convert to array format with day names
        $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $result = [];
        for ($day = 1; $day <= 7; $day++) {
            $result[] = [
                'day' => $dayNames[$day - 1],
                'hours' => $grid[$day],
            ];
        }
        
        return $result;
    }
    
    private function buildDeliveryRateGrid(array $totalMessages, array $deliveredMessages): array
    {
        // Build lookup for delivered counts
        $deliveredLookup = [];
        foreach ($deliveredMessages as $row) {
            $key = $row['day_of_week'] . '-' . $row['hour'];
            $deliveredLookup[$key] = (int) $row['count'];
        }
        
        // Build total lookup
        $totalLookup = [];
        foreach ($totalMessages as $row) {
            $key = $row['day_of_week'] . '-' . $row['hour'];
            $totalLookup[$key] = (int) $row['count'];
        }
        
        // Calculate rates
        $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $result = [];
        
        for ($day = 1; $day <= 7; $day++) {
            $hours = [];
            for ($hour = 0; $hour < 24; $hour++) {
                $key = $day . '-' . $hour;
                $total = $totalLookup[$key] ?? 0;
                $delivered = $deliveredLookup[$key] ?? 0;
                
                if ($total > 0) {
                    $hours[$hour] = round(($delivered / $total) * 100, 1);
                } else {
                    $hours[$hour] = null; // No data
                }
            }
            $result[] = [
                'day' => $dayNames[$day - 1],
                'hours' => $hours,
            ];
        }
        
        return $result;
    }
    
    /**
     * Export heatmap data
     */
    public function exportHeatmap(): void
    {
        $this->requireAdmin();
        
        $format = Request::query('format', 'csv');
        
        $pdo = db();
        $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
        
        // Get comprehensive message stats by hour and day
        $stmt = $pdo->prepare("
            SELECT 
                HOUR(m.sent_at) as hour,
                DAYOFWEEK(m.sent_at) as day_of_week,
                COUNT(*) as total_sent,
                SUM(CASE WHEN m.status = 'Delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN m.status = 'Failed' THEN 1 ELSE 0 END) as failed
            FROM messages m
            WHERE m.sent_at >= ? AND m.sent_at IS NOT NULL
            GROUP BY HOUR(m.sent_at), DAYOFWEEK(m.sent_at)
            ORDER BY day_of_week, hour
        ");
        $stmt->execute([$thirtyDaysAgo]);
        $messageStats = $stmt->fetchAll();
        
        // Get registration stats
        $stmt = $pdo->prepare("
            SELECT 
                HOUR(created_at) as hour,
                DAYOFWEEK(created_at) as day_of_week,
                COUNT(*) as count
            FROM users
            WHERE created_at >= ?
            GROUP BY HOUR(created_at), DAYOFWEEK(created_at)
            ORDER BY day_of_week, hour
        ");
        $stmt->execute([$thirtyDaysAgo]);
        $registrationStats = $stmt->fetchAll();
        
        // Get campaign stats
        $stmt = $pdo->prepare("
            SELECT 
                HOUR(created_at) as hour,
                DAYOFWEEK(created_at) as day_of_week,
                COUNT(*) as count
            FROM campaigns
            WHERE created_at >= ?
            GROUP BY HOUR(created_at), DAYOFWEEK(created_at)
            ORDER BY day_of_week, hour
        ");
        $stmt->execute([$thirtyDaysAgo]);
        $campaignStats = $stmt->fetchAll();
        
        if ($format === 'csv') {
            $this->exportHeatmapCSV($messageStats, $registrationStats, $campaignStats);
        } else {
            $this->exportHeatmapPDF($messageStats, $registrationStats, $campaignStats);
        }
    }
    
    private function exportHeatmapCSV(array $messageStats, array $registrationStats, array $campaignStats): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="heatmap_data_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
        
        $dayNames = ['', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        
        // Message delivery section
        fputcsv($output, ['MESSAGE DELIVERY STATISTICS (Last 30 Days)']);
        fputcsv($output, ['Day', 'Hour', 'Total Sent', 'Delivered', 'Failed', 'Delivery Rate %']);
        
        foreach ($messageStats as $row) {
            $rate = $row['total_sent'] > 0 ? round(($row['delivered'] / $row['total_sent']) * 100, 1) : 0;
            fputcsv($output, [
                $dayNames[(int)$row['day_of_week']],
                $row['hour'] . ':00',
                $row['total_sent'],
                $row['delivered'],
                $row['failed'],
                $rate . '%',
            ]);
        }
        
        fputcsv($output, []); // Empty row
        
        // Registration section
        fputcsv($output, ['USER REGISTRATIONS (Last 30 Days)']);
        fputcsv($output, ['Day', 'Hour', 'Registrations']);
        
        foreach ($registrationStats as $row) {
            fputcsv($output, [
                $dayNames[(int)$row['day_of_week']],
                $row['hour'] . ':00',
                $row['count'],
            ]);
        }
        
        fputcsv($output, []); // Empty row
        
        // Campaign section
        fputcsv($output, ['CAMPAIGNS CREATED (Last 30 Days)']);
        fputcsv($output, ['Day', 'Hour', 'Campaigns']);
        
        foreach ($campaignStats as $row) {
            fputcsv($output, [
                $dayNames[(int)$row['day_of_week']],
                $row['hour'] . ':00',
                $row['count'],
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    private function exportHeatmapPDF(array $messageStats, array $registrationStats, array $campaignStats): void
    {
        header('Content-Type: text/html; charset=utf-8');
        
        $dayNames = ['', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
        $html .= '<title>Activity Heatmap Report - ' . date('Y-m-d') . '</title>';
        $html .= '<style>
            body { font-family: Arial, sans-serif; font-size: 11px; margin: 20px; color: #333; }
            h1 { font-size: 20px; margin-bottom: 5px; color: #1a1a1a; }
            h2 { font-size: 14px; margin-top: 25px; margin-bottom: 10px; color: #444; border-bottom: 2px solid #007bff; padding-bottom: 5px; }
            .subtitle { color: #666; font-size: 12px; margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th { background-color: #007bff; color: white; padding: 8px; text-align: left; font-weight: 600; }
            td { border: 1px solid #ddd; padding: 6px 8px; }
            tr:nth-child(even) { background-color: #f8f9fa; }
            .rate-high { background-color: #d4edda; color: #155724; }
            .rate-medium { background-color: #fff3cd; color: #856404; }
            .rate-low { background-color: #f8d7da; color: #721c24; }
            .summary { background-color: #e7f3ff; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
            .summary h3 { margin: 0 0 10px 0; font-size: 13px; }
            .stat-grid { display: flex; gap: 20px; flex-wrap: wrap; }
            .stat-item { text-align: center; }
            .stat-value { font-size: 24px; font-weight: bold; color: #007bff; }
            .stat-label { font-size: 10px; color: #666; }
            @media print { body { margin: 0; } .page-break { page-break-before: always; } }
        </style></head><body>';
        
        $html .= '<h1>Activity Heatmap Report</h1>';
        $html .= '<p class="subtitle">Generated: ' . date('F j, Y \a\t g:i A') . ' | Period: Last 30 Days</p>';
        
        // Summary stats
        $totalSent = array_sum(array_column($messageStats, 'total_sent'));
        $totalDelivered = array_sum(array_column($messageStats, 'delivered'));
        $totalFailed = array_sum(array_column($messageStats, 'failed'));
        $overallRate = $totalSent > 0 ? round(($totalDelivered / $totalSent) * 100, 1) : 0;
        $totalRegistrations = array_sum(array_column($registrationStats, 'count'));
        $totalCampaigns = array_sum(array_column($campaignStats, 'count'));
        
        $html .= '<div class="summary">';
        $html .= '<h3>Summary Statistics</h3>';
        $html .= '<div class="stat-grid">';
        $html .= '<div class="stat-item"><div class="stat-value">' . number_format($totalSent) . '</div><div class="stat-label">Messages Sent</div></div>';
        $html .= '<div class="stat-item"><div class="stat-value">' . number_format($totalDelivered) . '</div><div class="stat-label">Delivered</div></div>';
        $html .= '<div class="stat-item"><div class="stat-value">' . $overallRate . '%</div><div class="stat-label">Delivery Rate</div></div>';
        $html .= '<div class="stat-item"><div class="stat-value">' . number_format($totalRegistrations) . '</div><div class="stat-label">Registrations</div></div>';
        $html .= '<div class="stat-item"><div class="stat-value">' . number_format($totalCampaigns) . '</div><div class="stat-label">Campaigns</div></div>';
        $html .= '</div></div>';
        
        // Message delivery table
        $html .= '<h2>📊 Message Delivery by Hour & Day</h2>';
        $html .= '<table><thead><tr><th>Day</th><th>Hour</th><th>Sent</th><th>Delivered</th><th>Failed</th><th>Rate</th></tr></thead><tbody>';
        
        foreach ($messageStats as $row) {
            $rate = $row['total_sent'] > 0 ? round(($row['delivered'] / $row['total_sent']) * 100, 1) : 0;
            $rateClass = $rate >= 90 ? 'rate-high' : ($rate >= 70 ? 'rate-medium' : 'rate-low');
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($dayNames[(int)$row['day_of_week']]) . '</td>';
            $html .= '<td>' . $row['hour'] . ':00</td>';
            $html .= '<td>' . number_format($row['total_sent']) . '</td>';
            $html .= '<td>' . number_format($row['delivered']) . '</td>';
            $html .= '<td>' . number_format($row['failed']) . '</td>';
            $html .= '<td class="' . $rateClass . '">' . $rate . '%</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        
        // Registration table
        $html .= '<h2>👥 User Registrations by Hour & Day</h2>';
        $html .= '<table><thead><tr><th>Day</th><th>Hour</th><th>Registrations</th></tr></thead><tbody>';
        
        foreach ($registrationStats as $row) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($dayNames[(int)$row['day_of_week']]) . '</td>';
            $html .= '<td>' . $row['hour'] . ':00</td>';
            $html .= '<td>' . number_format($row['count']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        
        // Campaign table
        $html .= '<h2>📨 Campaigns Created by Hour & Day</h2>';
        $html .= '<table><thead><tr><th>Day</th><th>Hour</th><th>Campaigns</th></tr></thead><tbody>';
        
        foreach ($campaignStats as $row) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($dayNames[(int)$row['day_of_week']]) . '</td>';
            $html .= '<td>' . $row['hour'] . ':00</td>';
            $html .= '<td>' . number_format($row['count']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        
        $html .= '<script>window.onload = function() { window.print(); }</script>';
        $html .= '</body></html>';
        
        echo $html;
        exit;
    }
}
