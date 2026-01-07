<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 3600; // 1 hour max

    public function __construct(
        public Campaign $campaign
    ) {}

    public function handle(): void
    {
        Log::info("Processing campaign {$this->campaign->id}: {$this->campaign->name}");

        // Update status to Sending
        $this->campaign->update([
            'status' => Campaign::STATUS_SENDING,
            'sent_at' => now(),
        ]);

        // Get pending messages
        $messages = $this->campaign->messages()->pending()->get();

        if ($messages->isEmpty()) {
            Log::warning("Campaign {$this->campaign->id} has no pending messages");
            $this->campaign->update(['status' => Campaign::STATUS_COMPLETED]);
            return;
        }

        // Dispatch individual send jobs
        foreach ($messages as $message) {
            if ($this->campaign->isSms()) {
                SendSmsJob::dispatch($message)->onQueue('sms');
            } else {
                SendEmailJob::dispatch($message)->onQueue('email');
            }

            // Mark as queued
            $message->update(['status' => Message::STATUS_QUEUED]);
        }

        Log::info("Dispatched {$messages->count()} messages for campaign {$this->campaign->id}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Campaign {$this->campaign->id} processing failed: " . $exception->getMessage());

        $this->campaign->update([
            'status' => Campaign::STATUS_FAILED,
        ]);

        // Release reserved credits
        $wallet = $this->campaign->user->wallet;
        if ($wallet && $this->campaign->reserved_credits > 0) {
            $wallet->releaseReservation($this->campaign->reserved_credits);
            $wallet->refund(
                $this->campaign->reserved_credits,
                "Campaign failed refund: {$this->campaign->name}"
            );
        }
    }
}
