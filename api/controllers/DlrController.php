<?php
/**
 * DLR (Delivery Report) Controller
 */

class DlrController {
    public function webhook(): void {
        // Verify webhook secret
        $secret = Request::query('secret', '');
        if ($secret !== env('DLR_WEBHOOK_SECRET')) {
            // Log attempt but don't reveal whether secret is correct
            error_log('DLR webhook: Invalid secret');
            Response::error('Unauthorized', 401);
        }
        
        $data = Request::input();
        
        // LogicSMS format
        $messageId = $data['messageId'] ?? $data['Id'] ?? $data['external_id'] ?? null;
        $status = $data['status'] ?? $data['Status'] ?? $data['dlr_status'] ?? null;
        
        if (!$messageId || !$status) {
            Response::error('Missing messageId or status', 400);
        }
        
        // Find message by external_id
        $message = table('messages')->where('external_id', $messageId)->first();
        
        if (!$message) {
            // Log for debugging but don't error (gateway might retry)
            error_log("DLR webhook: Message not found for external_id: $messageId");
            Response::success(['message' => 'Message not found']);
        }
        
        // Log DLR
        table('dlr_logs')->insert([
            'message_id' => $message['id'],
            'external_id' => $messageId,
            'status' => $status,
            'raw_payload' => json_encode($data),
            'received_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Map status
        $mappedStatus = $this->mapStatus($status);
        
        $updateData = [
            'status' => $mappedStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        
        if ($mappedStatus === 'Delivered') {
            $updateData['delivered_at'] = date('Y-m-d H:i:s');
        } elseif ($mappedStatus === 'Failed') {
            $updateData['failed_at'] = date('Y-m-d H:i:s');
            $updateData['error_message'] = $data['error'] ?? $data['reason'] ?? 'Delivery failed';
        }
        
        table('messages')->where('id', $message['id'])->update($updateData);
        
        Response::success(['message' => 'DLR processed']);
    }
    
    public function status(array $params): void {
        $message = table('messages')
            ->where('id', $params['messageId'])
            ->first();
        
        if (!$message) {
            Response::error('Message not found', 404);
        }
        
        // Verify ownership through campaign
        $campaign = table('campaigns')
            ->where('id', $message['campaign_id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$campaign) {
            Response::error('Message not found', 404);
        }
        
        // Get DLR logs
        $dlrLogs = table('dlr_logs')
            ->where('message_id', $message['id'])
            ->orderBy('received_at', 'DESC')
            ->get();
        
        Response::success([
            'message' => $message,
            'dlr_logs' => $dlrLogs,
        ]);
    }
    
    private function mapStatus(string $status): string {
        $statusMap = [
            // LogicSMS statuses
            'DELIVERED' => 'Delivered',
            'DELIVRD' => 'Delivered',
            'ACCEPTED' => 'Awaiting DLR',
            'SENT' => 'Sent',
            'UNDELIV' => 'Failed',
            'FAILED' => 'Failed',
            'REJECTED' => 'Rejected',
            'EXPIRED' => 'Failed',
            
            // Generic statuses
            'delivered' => 'Delivered',
            'sent' => 'Sent',
            'failed' => 'Failed',
            'pending' => 'Awaiting DLR',
        ];
        
        return $statusMap[strtoupper($status)] ?? $statusMap[strtolower($status)] ?? 'Awaiting DLR';
    }
}
