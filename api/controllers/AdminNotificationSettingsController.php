<?php
/**
 * Admin Notification Settings Controller
 * Manages which events trigger admin email notifications
 */

class AdminNotificationSettingsController
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
     * Get all notification settings
     */
    public function index(): void
    {
        $this->requireAdmin();

        $settings = table('admin_notification_settings')
            ->orderBy('id', 'ASC')
            ->get();

        Response::success(['settings' => $settings]);
    }

    /**
     * Update a notification setting
     */
    public function update(array $params): void
    {
        $this->requireAdmin();

        $eventType = $params['event_type'] ?? null;
        if (!$eventType) {
            Response::error('Event type is required', 400);
        }

        $setting = table('admin_notification_settings')
            ->where('event_type', $eventType)
            ->first();

        if (!$setting) {
            Response::error('Setting not found', 404);
        }

        $data = Request::all();
        $updates = [];

        if (isset($data['is_enabled'])) {
            $updates['is_enabled'] = $data['is_enabled'] ? 1 : 0;
        }
        if (isset($data['notify_email'])) {
            $updates['notify_email'] = $data['notify_email'] ? 1 : 0;
        }
        if (isset($data['notify_inapp'])) {
            $updates['notify_inapp'] = $data['notify_inapp'] ? 1 : 0;
        }

        if (empty($updates)) {
            Response::error('No valid fields to update', 400);
        }

        $updates['updated_at'] = date('Y-m-d H:i:s');

        table('admin_notification_settings')
            ->where('event_type', $eventType)
            ->update($updates);

        // Log the action
        require_once __DIR__ . '/../services/AuditLogService.php';
        AuditLogService::log(
            'notification_settings_updated',
            'admin_notification_settings',
            (int) $setting['id'],
            ['event_type' => $eventType],
            $updates
        );

        Response::success([
            'message' => 'Notification setting updated',
            'setting' => array_merge($setting, $updates)
        ]);
    }

    /**
     * Bulk update notification settings
     */
    public function bulkUpdate(): void
    {
        $this->requireAdmin();

        $data = Request::all();
        $settings = $data['settings'] ?? [];

        if (empty($settings)) {
            Response::error('No settings provided', 400);
        }

        $pdo = db();
        $updated = 0;

        foreach ($settings as $eventType => $values) {
            $updates = [];

            if (isset($values['is_enabled'])) {
                $updates['is_enabled'] = $values['is_enabled'] ? 1 : 0;
            }
            if (isset($values['notify_email'])) {
                $updates['notify_email'] = $values['notify_email'] ? 1 : 0;
            }
            if (isset($values['notify_inapp'])) {
                $updates['notify_inapp'] = $values['notify_inapp'] ? 1 : 0;
            }

            if (!empty($updates)) {
                $updates['updated_at'] = date('Y-m-d H:i:s');
                table('admin_notification_settings')
                    ->where('event_type', $eventType)
                    ->update($updates);
                $updated++;
            }
        }

        // Log the action
        require_once __DIR__ . '/../services/AuditLogService.php';
        AuditLogService::log(
            'notification_settings_bulk_updated',
            'admin_notification_settings',
            null,
            null,
            ['count' => $updated]
        );

        Response::success([
            'message' => "Updated {$updated} notification settings",
            'updated' => $updated
        ]);
    }

    /**
     * Check if a specific notification type is enabled
     */
    public static function isEnabled(string $eventType, string $channel = 'email'): bool
    {
        $setting = table('admin_notification_settings')
            ->where('event_type', $eventType)
            ->first();

        if (!$setting || !$setting['is_enabled']) {
            return false;
        }

        if ($channel === 'email') {
            return (bool) $setting['notify_email'];
        }
        if ($channel === 'inapp') {
            return (bool) $setting['notify_inapp'];
        }

        return (bool) $setting['is_enabled'];
    }
}
