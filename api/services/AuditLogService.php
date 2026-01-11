<?php
/**
 * Audit Log Service - Tracks admin and system actions
 */

class AuditLogService
{
    /**
     * Log an action
     */
    public static function log(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null
    ): void {
        try {
            $pdo = db();
            
            // Get user ID from Auth if not provided
            if ($userId === null) {
                $userId = Auth::id();
            }
            
            // Get IP and User Agent
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                $action,
                $entityType,
                $entityId,
                $oldValues ? json_encode($oldValues) : null,
                $newValues ? json_encode($newValues) : null,
                $ipAddress,
                $userAgent
            ]);
        } catch (Exception $e) {
            // Log error but don't fail the main operation
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get audit logs with filters
     */
    public static function getLogs(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $pdo = db();
        
        $sql = "
            SELECT al.*, u.name as user_name, u.email as user_email
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE 1=1
        ";
        $params = [];
        
        if (!empty($filters['action'])) {
            $sql .= " AND al.action = ?";
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['entity_type'])) {
            $sql .= " AND al.entity_type = ?";
            $params[] = $filters['entity_type'];
        }
        
        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['from_date'])) {
            $sql .= " AND al.created_at >= ?";
            $params[] = $filters['from_date'];
        }
        
        if (!empty($filters['to_date'])) {
            $sql .= " AND al.created_at <= ?";
            $params[] = $filters['to_date'];
        }
        
        $sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get count of logs with filters
     */
    public static function getLogsCount(array $filters = []): int
    {
        $pdo = db();
        
        $sql = "SELECT COUNT(*) as count FROM audit_logs al WHERE 1=1";
        $params = [];
        
        if (!empty($filters['action'])) {
            $sql .= " AND al.action = ?";
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['entity_type'])) {
            $sql .= " AND al.entity_type = ?";
            $params[] = $filters['entity_type'];
        }
        
        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return (int) $stmt->fetch()['count'];
    }
}
