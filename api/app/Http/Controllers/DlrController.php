<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\DlrLog;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DLR (Delivery Report) Controller
 * 
 * Handles incoming delivery reports from SMS gateways.
 */
class DlrController extends Controller
{
    /**
     * Handle DLR webhook from LogicSMS
     * 
     * POST /api/dlr/webhook
     * 
     * LogicSMS sends XML:
     * <dlr>
     *   <msgid>123456</msgid>
     *   <status>delivered</status>
     *   <msisdn>27821234567</msisdn>
     * </dlr>
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function webhook(Request $request): JsonResponse
    {
        // Log raw payload for debugging
        Log::info('DLR Webhook received', [
            'content_type' => $request->header('Content-Type'),
            'raw' => $request->getContent(),
        ]);

        // Verify webhook secret if configured
        $secret = config('dlr.webhook_secret');
        if ($secret && $request->header('X-Webhook-Secret') !== $secret) {
            Log::warning('DLR webhook unauthorized attempt', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            // Parse based on content type
            $contentType = $request->header('Content-Type', 'application/json');
            
            if (str_contains($contentType, 'xml')) {
                return $this->handleXmlDlr($request);
            } else {
                return $this->handleJsonDlr($request);
            }

        } catch (\Exception $e) {
            Log::error('DLR webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle XML format DLR (LogicSMS)
     */
    private function handleXmlDlr(Request $request): JsonResponse
    {
        $xml = simplexml_load_string($request->getContent());
        
        if (!$xml) {
            return response()->json(['error' => 'Invalid XML'], 400);
        }

        $externalId = (string) ($xml->msgid ?? $xml->messageId ?? $xml->id);
        $status = strtolower((string) ($xml->status ?? $xml->Status ?? ''));
        $recipient = (string) ($xml->msisdn ?? $xml->recipient ?? '');

        return $this->processDlr($externalId, $status, $recipient, $request->getContent());
    }

    /**
     * Handle JSON format DLR
     */
    private function handleJsonDlr(Request $request): JsonResponse
    {
        $data = $request->json()->all();

        $externalId = $data['messageId'] ?? $data['msgid'] ?? $data['id'] ?? null;
        $status = strtolower($data['status'] ?? $data['Status'] ?? '');
        $recipient = $data['recipient'] ?? $data['msisdn'] ?? $data['to'] ?? '';

        if (!$externalId) {
            return response()->json(['error' => 'Missing messageId'], 400);
        }

        return $this->processDlr($externalId, $status, $recipient, json_encode($data));
    }

    /**
     * Process the DLR update
     */
    private function processDlr(string $externalId, string $status, string $recipient, string $rawPayload): JsonResponse
    {
        // Find the message by external ID
        $message = Message::where('external_id', $externalId)->first();

        if (!$message) {
            Log::warning('DLR for unknown message', [
                'external_id' => $externalId,
                'status' => $status,
            ]);
            
            // Still return success to prevent retries
            return response()->json(['received' => true, 'processed' => false]);
        }

        // Map gateway status to internal status
        $mappedStatus = $this->mapStatus($status);
        $previousStatus = $message->status;

        DB::transaction(function () use ($message, $mappedStatus, $status, $rawPayload, $previousStatus) {
            // Update message status
            $message->status = $mappedStatus;
            
            if ($mappedStatus === 'Delivered') {
                $message->delivered_at = now();
            }
            
            if (in_array($mappedStatus, ['Failed', 'Rejected', 'Expired'])) {
                $message->error_code = $status;
            }
            
            $message->save();

            // Log the DLR
            DlrLog::create([
                'message_id' => $message->id,
                'external_id' => $message->external_id,
                'status' => $status,
                'gateway' => $message->gateway,
                'raw_payload' => $rawPayload,
            ]);

            // Handle credit refund for failed messages
            if (in_array($mappedStatus, ['Failed', 'Rejected']) && $previousStatus !== 'Failed') {
                $this->refundCredits($message);
            }

            // Check if campaign is complete
            $this->checkCampaignCompletion($message->campaign_id);
        });

        Log::info('DLR processed', [
            'message_id' => $message->id,
            'external_id' => $externalId,
            'status' => $mappedStatus,
        ]);

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * Map gateway status to internal status
     */
    private function mapStatus(string $gatewayStatus): string
    {
        return match($gatewayStatus) {
            'delivered', 'delivery_success', 'success', 'ok' => 'Delivered',
            'failed', 'failure', 'error', 'undelivered' => 'Failed',
            'rejected', 'invalid', 'blacklisted' => 'Rejected',
            'expired', 'timeout' => 'Expired',
            'sent', 'submitted', 'accepted' => 'Sent',
            'pending', 'queued', 'buffered' => 'Awaiting DLR',
            default => 'Awaiting DLR',
        };
    }

    /**
     * Refund credits for failed message
     */
    private function refundCredits(Message $message): void
    {
        $campaign = $message->campaign;
        $user = $campaign->user;
        $wallet = $user->wallet;

        if (!$wallet || $message->cost <= 0) {
            return;
        }

        // Add refund
        $wallet->balance += $message->cost;
        $wallet->save();

        // Log transaction
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'refund',
            'amount' => $message->cost,
            'balance_after' => $wallet->balance,
            'description' => "Refund for failed message to {$message->recipient}",
            'campaign_id' => $campaign->id,
            'payment_status' => 'completed',
        ]);

        Log::info('Credits refunded', [
            'message_id' => $message->id,
            'amount' => $message->cost,
        ]);
    }

    /**
     * Check if campaign is complete and update status
     */
    private function checkCampaignCompletion(int $campaignId): void
    {
        $campaign = \App\Models\Campaign::find($campaignId);
        
        if (!$campaign || in_array($campaign->status, ['Completed', 'Failed'])) {
            return;
        }

        // Count pending messages
        $pendingCount = Message::where('campaign_id', $campaignId)
            ->whereIn('status', ['Pending', 'Queued', 'Sent', 'Awaiting DLR'])
            ->count();

        if ($pendingCount === 0) {
            // All messages processed
            $stats = Message::where('campaign_id', $campaignId)
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN status = "Delivered" THEN 1 ELSE 0 END) as delivered,
                    SUM(cost) as actual_cost
                ')
                ->first();

            $campaign->status = 'Completed';
            $campaign->completed_at = now();
            $campaign->actual_cost = $stats->actual_cost ?? 0;
            $campaign->save();

            // Release any remaining reserved credits
            $wallet = $campaign->user->wallet;
            if ($wallet && $wallet->reserved > 0) {
                $toRelease = min($wallet->reserved, $campaign->estimated_cost);
                $wallet->reserved -= $toRelease;
                $wallet->save();
            }

            Log::info('Campaign completed', [
                'campaign_id' => $campaignId,
                'delivered' => $stats->delivered,
                'total' => $stats->total,
            ]);
        }
    }
}
