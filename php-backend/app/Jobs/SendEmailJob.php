<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Campaign;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $backoff = 60;

    public function __construct(
        public Message $message
    ) {}

    public function handle(EmailService $emailService): void
    {
        $campaign = $this->message->campaign;
        
        Log::info("Sending email for message {$this->message->id} to {$this->message->recipient}");

        try {
            $result = $emailService->send(
                $this->message->recipient,
                $this->message->subject ?? $campaign->subject,
                $this->message->content,
                $campaign->from_email ?? config('mail.from.address'),
                $campaign->from_name ?? config('mail.from.name')
            );

            if ($result['success']) {
                $this->message->markSent($result['messageId'] ?? uniqid('email_'), $result);
                
                // Emails are typically delivered immediately
                $this->message->markDelivered();
                
                $campaign->increment('sent_count');
                $campaign->increment('delivered_count');
                
                Log::info("Email sent successfully: {$this->message->id}");
            } else {
                $this->message->markFailed($result['error'] ?? 'Unknown email error');
                $campaign->increment('failed_count');
                
                Log::error("Email sending failed: {$this->message->id}, Error: " . ($result['error'] ?? 'Unknown'));
            }

        } catch (\Exception $e) {
            Log::error("Email job exception for message {$this->message->id}: " . $e->getMessage());
            throw $e;
        }

        $this->checkCampaignCompletion($campaign);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Email job permanently failed for message {$this->message->id}: " . $exception->getMessage());

        $this->message->markFailed($exception->getMessage());
        
        $campaign = $this->message->campaign;
        $campaign->increment('failed_count');

        // Refund cost for failed message
        $wallet = $campaign->user->wallet;
        if ($wallet && $this->message->cost > 0) {
            $wallet->refund(
                $this->message->cost,
                "Email delivery failed refund - {$this->message->recipient}"
            );
        }

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
                ->whereIn('status', [Message::STATUS_DELIVERED])
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
