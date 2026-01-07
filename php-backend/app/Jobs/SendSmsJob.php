<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\SmsGatewayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public int $backoff = 30; // Retry after 30 seconds

    public function __construct(
        public Message $message
    ) {}

    public function handle(SmsGatewayService $smsService): void
    {
        $campaign = $this->message->campaign;
        
        Log::info("Sending SMS for message {$this->message->id} to {$this->message->recipient}");

        try {
            $result = $smsService->send(
                $this->message->recipient,
                $this->message->content,
                $campaign->sender_id ?? config('sms.default_sender', 'IEOSUIA')
            );

            if ($result['success']) {
                $this->message->markSent($result['messageId'], $result);
                
                // Update campaign stats
                $campaign->increment('sent_count');
                
                Log::info("SMS sent successfully: {$this->message->id}, Gateway ID: {$result['messageId']}");
            } else {
                $this->message->markFailed($result['error'] ?? 'Unknown gateway error');
                $campaign->increment('failed_count');
                
                Log::error("SMS sending failed: {$this->message->id}, Error: " . ($result['error'] ?? 'Unknown'));
            }

        } catch (\Exception $e) {
            Log::error("SMS job exception for message {$this->message->id}: " . $e->getMessage());
            throw $e; // Re-throw to trigger retry
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SMS job permanently failed for message {$this->message->id}: " . $exception->getMessage());

        $this->message->markFailed($exception->getMessage());
        
        $campaign = $this->message->campaign;
        $campaign->increment('failed_count');

        // Refund cost for failed message
        $wallet = $campaign->user->wallet;
        if ($wallet && $this->message->cost > 0) {
            $wallet->refund(
                $this->message->cost,
                "SMS delivery failed refund - {$this->message->recipient}"
            );
        }

        // Check if campaign is complete
        $this->checkCampaignCompletion($campaign);
    }

    private function checkCampaignCompletion($campaign): void
    {
        $pending = $campaign->messages()
            ->whereIn('status', [Message::STATUS_PENDING, Message::STATUS_QUEUED])
            ->count();

        if ($pending === 0) {
            $campaign->update([
                'status' => Campaign::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            // Calculate actual cost
            $actualCost = $campaign->messages()
                ->whereIn('status', [Message::STATUS_SENT, Message::STATUS_AWAITING_DLR, Message::STATUS_DELIVERED])
                ->sum('cost');

            $campaign->update(['actual_cost' => $actualCost]);

            // Release any remaining reserved credits
            $wallet = $campaign->user->wallet;
            if ($wallet && $campaign->reserved_credits > $actualCost) {
                $refundAmount = $campaign->reserved_credits - $actualCost;
                $wallet->releaseReservation($refundAmount);
            }
        }
    }
}
