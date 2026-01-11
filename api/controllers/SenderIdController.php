<?php
/**
 * Sender ID Controller - Manage SMS and Email sender identities
 */

class SenderIdController
{
    /**
     * List all sender IDs for the authenticated user
     */
    public function index(): void
    {
        $userId = Auth::id();
        $type = Request::query('type'); // 'sms' or 'email'
        
        $query = table('sender_ids')->where('user_id', $userId);
        
        if ($type && in_array($type, ['sms', 'email'])) {
            $query->where('type', $type);
        }
        
        $senderIds = $query->orderBy('is_default', 'DESC')
            ->orderBy('created_at', 'DESC')
            ->get();
        
        Response::success(['sender_ids' => $senderIds]);
    }
    
    /**
     * Create a new sender ID
     */
    public function store(): void
    {
        $data = Request::validate([
            'type' => 'required|in:sms,email',
            'sender_id' => 'max:20',
            'sender_email' => 'email|max:255',
            'sender_name' => 'max:100',
        ]);
        
        $userId = Auth::id();
        $type = $data['type'];
        
        // Validate based on type
        if ($type === 'sms') {
            if (empty($data['sender_id'])) {
                Response::error('Sender ID is required for SMS', 400);
            }
            // Validate sender ID format (alphanumeric, max 11 chars)
            if (!preg_match('/^[A-Za-z0-9]{3,11}$/', $data['sender_id'])) {
                Response::error('Sender ID must be 3-11 alphanumeric characters', 400);
            }
        } else {
            if (empty($data['sender_email'])) {
                Response::error('Sender email is required for Email type', 400);
            }
        }
        
        // Check for duplicates
        $existing = table('sender_ids')
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where($type === 'sms' ? 'sender_id' : 'sender_email', $data[$type === 'sms' ? 'sender_id' : 'sender_email'])
            ->first();
        
        if ($existing) {
            Response::error('This sender ID already exists', 400);
        }
        
        // Check if this should be default (first of its type)
        $existingCount = table('sender_ids')
            ->where('user_id', $userId)
            ->where('type', $type)
            ->count();
        
        $isDefault = $existingCount === 0 ? 1 : 0;
        
        $senderId = table('sender_ids')->insert([
            'user_id' => $userId,
            'type' => $type,
            'sender_id' => $type === 'sms' ? $data['sender_id'] : null,
            'sender_email' => $type === 'email' ? $data['sender_email'] : null,
            'sender_name' => $data['sender_name'] ?? null,
            'status' => 'pending', // Requires admin approval
            'is_default' => $isDefault,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $record = table('sender_ids')->where('id', $senderId)->first();
        
        Response::created([
            'sender_id' => $record,
            'message' => 'Sender ID created. Pending approval.',
        ]);
    }
    
    /**
     * Update a sender ID
     */
    public function update(array $params): void
    {
        $senderId = table('sender_ids')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$senderId) {
            Response::error('Sender ID not found', 404);
        }
        
        $data = Request::validate([
            'sender_name' => 'max:100',
        ]);
        
        table('sender_ids')->where('id', $params['id'])->update([
            'sender_name' => $data['sender_name'] ?? $senderId['sender_name'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $record = table('sender_ids')->where('id', $params['id'])->first();
        
        Response::success(['sender_id' => $record]);
    }
    
    /**
     * Delete a sender ID
     */
    public function destroy(array $params): void
    {
        $senderId = table('sender_ids')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$senderId) {
            Response::error('Sender ID not found', 404);
        }
        
        // Cannot delete default sender ID if it's the only one
        if ($senderId['is_default']) {
            $otherCount = table('sender_ids')
                ->where('user_id', Auth::id())
                ->where('type', $senderId['type'])
                ->where('id', '!=', $params['id'])
                ->count();
            
            if ($otherCount === 0) {
                Response::error('Cannot delete the only sender ID', 400);
            }
            
            // Set another as default
            $other = table('sender_ids')
                ->where('user_id', Auth::id())
                ->where('type', $senderId['type'])
                ->where('id', '!=', $params['id'])
                ->first();
            
            if ($other) {
                table('sender_ids')->where('id', $other['id'])->update([
                    'is_default' => 1,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
        
        table('sender_ids')->where('id', $params['id'])->delete();
        
        Response::noContent();
    }
    
    /**
     * Set a sender ID as default
     */
    public function setDefault(array $params): void
    {
        $senderId = table('sender_ids')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$senderId) {
            Response::error('Sender ID not found', 404);
        }
        
        if ($senderId['status'] !== 'approved') {
            Response::error('Only approved sender IDs can be set as default', 400);
        }
        
        // Remove default from others of same type
        table('sender_ids')
            ->where('user_id', Auth::id())
            ->where('type', $senderId['type'])
            ->update([
                'is_default' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        // Set this as default
        table('sender_ids')->where('id', $params['id'])->update([
            'is_default' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Response::success(['message' => 'Default sender ID updated']);
    }
    
    /**
     * Get default sender ID for a type
     */
    public static function getDefault(int $userId, string $type): ?array
    {
        return table('sender_ids')
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('status', 'approved')
            ->where('is_default', 1)
            ->first();
    }
    
    /**
     * Admin: Approve a sender ID
     */
    public function approve(array $params): void
    {
        $user = Auth::user();
        if ($user['role'] !== 'admin') {
            Response::error('Unauthorized', 403);
        }
        
        $senderId = table('sender_ids')->where('id', $params['id'])->first();
        
        if (!$senderId) {
            Response::error('Sender ID not found', 404);
        }
        
        table('sender_ids')->where('id', $params['id'])->update([
            'status' => 'approved',
            'verified_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Response::success(['message' => 'Sender ID approved']);
    }
    
    /**
     * Admin: Reject a sender ID
     */
    public function reject(array $params): void
    {
        $user = Auth::user();
        if ($user['role'] !== 'admin') {
            Response::error('Unauthorized', 403);
        }
        
        $senderId = table('sender_ids')->where('id', $params['id'])->first();
        
        if (!$senderId) {
            Response::error('Sender ID not found', 404);
        }
        
        table('sender_ids')->where('id', $params['id'])->update([
            'status' => 'rejected',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Response::success(['message' => 'Sender ID rejected']);
    }
}
