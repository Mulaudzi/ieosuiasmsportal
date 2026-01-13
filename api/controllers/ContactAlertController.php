<?php
/**
 * Contact Alert Recipients Controller
 * Manages email addresses that receive instant alerts for new contact form submissions
 */

class ContactAlertController
{
    /**
     * Check if user is admin
     */
    private static function requireAdmin(): void
    {
        $user = Auth::user();
        if (!$user || $user['account_type'] !== 'admin') {
            Response::error('Unauthorized', 403);
            exit;
        }
    }
    
    /**
     * Get all alert recipients
     * GET /admin/contact-alerts
     */
    public static function index(): void
    {
        self::requireAdmin();
        
        $recipients = table('contact_alert_recipients')
            ->orderBy('purpose', 'ASC')
            ->orderBy('email', 'ASC')
            ->get();
        
        Response::success(['recipients' => $recipients]);
    }
    
    /**
     * Add a new alert recipient
     * POST /admin/contact-alerts
     */
    public static function store(): void
    {
        self::requireAdmin();
        
        $data = Request::all();
        $user = Auth::user();
        
        // Validate required fields
        if (empty($data['email'])) {
            Response::error('Email is required', 400);
            return;
        }
        
        $email = filter_var(trim($data['email']), FILTER_VALIDATE_EMAIL);
        if (!$email) {
            Response::error('Invalid email address', 400);
            return;
        }
        
        $purpose = $data['purpose'] ?? 'all';
        if (!in_array($purpose, ['all', 'general', 'support', 'sales'])) {
            $purpose = 'all';
        }
        
        // Check if already exists
        $existing = table('contact_alert_recipients')
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->first();
        
        if ($existing) {
            Response::error('This email is already configured for this purpose', 400);
            return;
        }
        
        // Insert new recipient
        $id = table('contact_alert_recipients')->insert([
            'email' => $email,
            'name' => trim($data['name'] ?? '') ?: null,
            'purpose' => $purpose,
            'is_active' => 1,
            'created_by' => $user['id'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Response::success([
            'message' => 'Alert recipient added',
            'recipient' => [
                'id' => $id,
                'email' => $email,
                'name' => $data['name'] ?? null,
                'purpose' => $purpose,
                'is_active' => true,
            ],
        ], 201);
    }
    
    /**
     * Update an alert recipient
     * PUT /admin/contact-alerts/{id}
     */
    public static function update(array $params): void
    {
        self::requireAdmin();
        
        $id = $params['id'] ?? null;
        if (!$id) {
            Response::error('Recipient ID is required', 400);
            return;
        }
        
        $recipient = table('contact_alert_recipients')->where('id', $id)->first();
        if (!$recipient) {
            Response::error('Recipient not found', 404);
            return;
        }
        
        $data = Request::all();
        $updates = [];
        
        if (isset($data['name'])) {
            $updates['name'] = trim($data['name']) ?: null;
        }
        if (isset($data['purpose'])) {
            if (in_array($data['purpose'], ['all', 'general', 'support', 'sales'])) {
                $updates['purpose'] = $data['purpose'];
            }
        }
        if (isset($data['is_active'])) {
            $updates['is_active'] = $data['is_active'] ? 1 : 0;
        }
        
        if (empty($updates)) {
            Response::error('No valid fields to update', 400);
            return;
        }
        
        $updates['updated_at'] = date('Y-m-d H:i:s');
        
        table('contact_alert_recipients')->where('id', $id)->update($updates);
        
        Response::success([
            'message' => 'Recipient updated',
            'recipient' => array_merge($recipient, $updates),
        ]);
    }
    
    /**
     * Delete an alert recipient
     * DELETE /admin/contact-alerts/{id}
     */
    public static function destroy(array $params): void
    {
        self::requireAdmin();
        
        $id = $params['id'] ?? null;
        if (!$id) {
            Response::error('Recipient ID is required', 400);
            return;
        }
        
        $recipient = table('contact_alert_recipients')->where('id', $id)->first();
        if (!$recipient) {
            Response::error('Recipient not found', 404);
            return;
        }
        
        table('contact_alert_recipients')->where('id', $id)->delete();
        
        Response::success(['message' => 'Recipient deleted']);
    }
    
    /**
     * Get all active recipients for a given purpose
     */
    public static function getRecipientsForPurpose(string $purpose): array
    {
        try {
            return table('contact_alert_recipients')
                ->where('is_active', 1)
                ->where(function($q) use ($purpose) {
                    $q->where('purpose', 'all')
                      ->orWhere('purpose', $purpose);
                })
                ->get();
        } catch (\Exception $e) {
            error_log("Failed to get contact alert recipients: " . $e->getMessage());
            return [];
        }
    }
}
