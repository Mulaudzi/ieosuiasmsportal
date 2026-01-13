<?php
/**
 * Opt-Out Controller
 */

class OptOutController {
    public function index(): void {
        $userId = Auth::id();
        $page = (int) Request::query('page', 1);
        $perPage = (int) Request::query('per_page', 20);
        
        $total = table('opt_outs')->where('user_id', $userId)->count();
        
        $optOuts = table('opt_outs')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();
        
        Response::paginate($optOuts, $total, $page, $perPage);
    }
    
    public function store(): void {
        $data = Request::validate([
            'recipient' => 'required|max:50',
            'channel' => 'max:20',
            'reason' => 'max:255',
            'source' => 'max:50',
        ]);
        
        $userId = Auth::id();
        
        // Check if already opted out
        $existing = table('opt_outs')
            ->where('user_id', $userId)
            ->where('recipient', $data['recipient'])
            ->first();
        
        if ($existing) {
            Response::error('Recipient already opted out', 400);
        }
        
        $optOutId = table('opt_outs')->insert([
            'user_id' => $userId,
            'recipient' => $data['recipient'],
            'channel' => $data['channel'] ?? 'sms',
            'reason' => $data['reason'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $optOut = table('opt_outs')->where('id', $optOutId)->first();
        
        Response::created(['opt_out' => $optOut]);
    }
    
    public function destroy(array $params): void {
        $optOut = table('opt_outs')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$optOut) {
            Response::error('Opt-out not found', 404);
        }
        
        table('opt_outs')->where('id', $params['id'])->delete();
        
        Response::noContent();
    }
}
