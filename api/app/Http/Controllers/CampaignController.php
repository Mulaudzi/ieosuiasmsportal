<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Message;
use App\Models\Contact;
use App\Models\OptOut;
use App\Jobs\ProcessCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * Campaign Controller
 * 
 * Handles SMS and Email campaign creation, management, and retrieval.
 */
class CampaignController extends Controller
{
    /**
     * List all campaigns for the authenticated user
     * 
     * GET /api/campaigns
     * GET /api/sms/campaigns
     * GET /api/email/campaigns
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $channel = $request->query('channel'); // 'sms' or 'email'
        $status = $request->query('status');
        $perPage = (int) $request->query('per_page', 15);

        $query = Campaign::where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc');

        if ($channel) {
            $query->where('channel', $channel);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $campaigns = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $campaigns->items(),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'per_page' => $campaigns->perPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    /**
     * Create a new SMS campaign
     * 
     * POST /api/sms/campaigns/create
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function createSmsCampaign(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:918'], // 6 SMS parts max
            'sender_id' => ['sometimes', 'string', 'max:11'],
            'recipients' => ['required_without:group_ids', 'array'],
            'recipients.*' => ['string'],
            'group_ids' => ['required_without:recipients', 'array'],
            'group_ids.*' => ['integer', 'exists:contact_groups,id'],
            'schedule_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $message = $request->input('message');
        
        // Calculate message parts (160 chars for first, 153 for subsequent)
        $messageLength = mb_strlen($message);
        $parts = $messageLength <= 160 ? 1 : (int) ceil($messageLength / 153);

        // Collect recipients
        $recipients = [];
        
        if ($request->has('recipients')) {
            $recipients = array_merge($recipients, $request->input('recipients'));
        }

        if ($request->has('group_ids')) {
            $groupContacts = Contact::where('user_id', $user->id)
                ->whereHas('groups', function ($q) use ($request) {
                    $q->whereIn('contact_groups.id', $request->input('group_ids'));
                })
                ->where('opt_out', false)
                ->whereNotNull('phone')
                ->pluck('phone')
                ->toArray();
            
            $recipients = array_merge($recipients, $groupContacts);
        }

        // Remove duplicates and opt-outs
        $recipients = array_unique($recipients);
        $optedOut = OptOut::where('user_id', $user->id)
            ->whereIn('recipient', $recipients)
            ->whereIn('channel', ['sms', 'all'])
            ->pluck('recipient')
            ->toArray();
        
        $recipients = array_diff($recipients, $optedOut);
        $totalRecipients = count($recipients);

        if ($totalRecipients === 0) {
            return response()->json([
                'success' => false,
                'error' => 'No valid recipients found. Check for opt-outs or invalid numbers.',
            ], 422);
        }

        // Calculate cost
        $pricePerCredit = (float) config('sms.price_per_credit', 0.38);
        $estimatedCost = $totalRecipients * $parts * $pricePerCredit;

        // Check wallet balance
        $wallet = $user->wallet;
        $availableBalance = $wallet->balance - $wallet->reserved;

        if ($availableBalance < $estimatedCost) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient credits. Please top up your wallet.',
                'data' => [
                    'required' => $estimatedCost,
                    'available' => $availableBalance,
                ],
            ], 402);
        }

        try {
            DB::beginTransaction();

            // Reserve credits
            $wallet->reserved += $estimatedCost;
            $wallet->save();

            // Create campaign
            $campaign = Campaign::create([
                'user_id' => $user->id,
                'name' => $request->input('name'),
                'channel' => 'sms',
                'status' => $request->has('schedule_at') ? 'Pending' : 'Queued',
                'sender_id' => $request->input('sender_id', config('sms.default_sender')),
                'content' => $message,
                'schedule_at' => $request->input('schedule_at'),
                'total_recipients' => $totalRecipients,
                'estimated_cost' => $estimatedCost,
            ]);

            // Create message records
            $messages = [];
            foreach ($recipients as $recipient) {
                $messages[] = [
                    'campaign_id' => $campaign->id,
                    'recipient' => $recipient,
                    'content' => $message,
                    'status' => 'Pending',
                    'parts' => $parts,
                    'cost' => $parts * $pricePerCredit,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Bulk insert messages
            Message::insert($messages);

            DB::commit();

            // Dispatch processing job if not scheduled
            if (!$request->has('schedule_at')) {
                ProcessCampaign::dispatch($campaign);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'campaignId' => $campaign->id,
                    'name' => $campaign->name,
                    'status' => $campaign->status,
                    'totalRecipients' => $totalRecipients,
                    'messageParts' => $parts,
                    'estimatedCost' => $estimatedCost,
                    'scheduledAt' => $campaign->schedule_at?->toISOString(),
                ],
                'message' => 'Campaign created successfully',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to create campaign. Please try again.',
            ], 500);
        }
    }

    /**
     * Create a new Email campaign
     * 
     * POST /api/email/campaigns/create
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function createEmailCampaign(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'from_email' => ['sometimes', 'email'],
            'from_name' => ['sometimes', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'html_content' => ['sometimes', 'string'],
            'recipients' => ['required_without:group_ids', 'array'],
            'recipients.*' => ['email'],
            'group_ids' => ['required_without:recipients', 'array'],
            'group_ids.*' => ['integer', 'exists:contact_groups,id'],
            'schedule_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        // Collect recipients
        $recipients = [];
        
        if ($request->has('recipients')) {
            $recipients = array_merge($recipients, $request->input('recipients'));
        }

        if ($request->has('group_ids')) {
            $groupContacts = Contact::where('user_id', $user->id)
                ->whereHas('groups', function ($q) use ($request) {
                    $q->whereIn('contact_groups.id', $request->input('group_ids'));
                })
                ->where('opt_out', false)
                ->whereNotNull('email')
                ->pluck('email')
                ->toArray();
            
            $recipients = array_merge($recipients, $groupContacts);
        }

        // Remove duplicates and opt-outs
        $recipients = array_unique($recipients);
        $optedOut = OptOut::where('user_id', $user->id)
            ->whereIn('recipient', $recipients)
            ->whereIn('channel', ['email', 'all'])
            ->pluck('recipient')
            ->toArray();
        
        $recipients = array_diff($recipients, $optedOut);
        $totalRecipients = count($recipients);

        if ($totalRecipients === 0) {
            return response()->json([
                'success' => false,
                'error' => 'No valid recipients found.',
            ], 422);
        }

        // Calculate cost
        $pricePerEmail = (float) config('email.price_per_credit', 0.05);
        $estimatedCost = $totalRecipients * $pricePerEmail;

        // Check wallet balance
        $wallet = $user->wallet;
        $availableBalance = $wallet->balance - $wallet->reserved;

        if ($availableBalance < $estimatedCost) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient credits.',
            ], 402);
        }

        try {
            DB::beginTransaction();

            // Reserve credits
            $wallet->reserved += $estimatedCost;
            $wallet->save();

            // Create campaign
            $campaign = Campaign::create([
                'user_id' => $user->id,
                'name' => $request->input('name'),
                'channel' => 'email',
                'status' => $request->has('schedule_at') ? 'Pending' : 'Queued',
                'from_email' => $request->input('from_email', config('mail.from.address')),
                'from_name' => $request->input('from_name', config('mail.from.name')),
                'subject' => $request->input('subject'),
                'content' => $request->input('content'),
                'html_content' => $request->input('html_content'),
                'schedule_at' => $request->input('schedule_at'),
                'total_recipients' => $totalRecipients,
                'estimated_cost' => $estimatedCost,
            ]);

            // Create message records
            $messages = [];
            foreach ($recipients as $recipient) {
                $messages[] = [
                    'campaign_id' => $campaign->id,
                    'recipient' => $recipient,
                    'content' => $request->input('subject'),
                    'status' => 'Pending',
                    'cost' => $pricePerEmail,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            Message::insert($messages);

            DB::commit();

            if (!$request->has('schedule_at')) {
                ProcessCampaign::dispatch($campaign);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'campaignId' => $campaign->id,
                    'name' => $campaign->name,
                    'status' => $campaign->status,
                    'totalRecipients' => $totalRecipients,
                    'estimatedCost' => $estimatedCost,
                ],
                'message' => 'Email campaign created successfully',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to create campaign.',
            ], 500);
        }
    }

    /**
     * Get campaign details
     * 
     * GET /api/campaigns/{id}
     * GET /api/sms/campaigns/{id}
     * GET /api/email/campaigns/{id}
     * 
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $campaign = Campaign::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'error' => 'Campaign not found',
            ], 404);
        }

        // Get message statistics
        $stats = Message::where('campaign_id', $campaign->id)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Get recent message logs
        $logs = Message::where('campaign_id', $campaign->id)
            ->orderBy('updated_at', 'desc')
            ->limit(100)
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'recipient' => $msg->recipient,
                'status' => $msg->status,
                'sentAt' => $msg->sent_at?->toISOString(),
                'deliveredAt' => $msg->delivered_at?->toISOString(),
                'errorMessage' => $msg->error_message,
            ]);

        $totalMessages = array_sum($stats);
        $delivered = $stats['Delivered'] ?? 0;
        $deliveryRate = $totalMessages > 0 ? round(($delivered / $totalMessages) * 100, 1) : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'channel' => $campaign->channel,
                'status' => $campaign->status,
                'senderId' => $campaign->sender_id,
                'content' => $campaign->content,
                'subject' => $campaign->subject,
                'totalRecipients' => $campaign->total_recipients,
                'estimatedCost' => (float) $campaign->estimated_cost,
                'actualCost' => (float) $campaign->actual_cost,
                'scheduledAt' => $campaign->schedule_at?->toISOString(),
                'sentAt' => $campaign->sent_at?->toISOString(),
                'completedAt' => $campaign->completed_at?->toISOString(),
                'createdAt' => $campaign->created_at->toISOString(),
                'stats' => [
                    'pending' => $stats['Pending'] ?? 0,
                    'queued' => $stats['Queued'] ?? 0,
                    'sent' => $stats['Sent'] ?? 0,
                    'awaitingDlr' => $stats['Awaiting DLR'] ?? 0,
                    'delivered' => $delivered,
                    'failed' => $stats['Failed'] ?? 0,
                    'optedOut' => $stats['Opted-Out'] ?? 0,
                    'deliveryRate' => $deliveryRate,
                ],
                'logs' => $logs,
            ],
        ]);
    }

    /**
     * Delete a campaign
     * 
     * DELETE /api/campaigns/{id}
     * 
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $campaign = Campaign::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'error' => 'Campaign not found',
            ], 404);
        }

        // Only allow deletion of draft/pending campaigns
        if (!in_array($campaign->status, ['Draft', 'Pending'])) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot delete a campaign that has been processed',
            ], 422);
        }

        // Release reserved credits
        if ($campaign->estimated_cost > 0) {
            $wallet = $user->wallet;
            $wallet->reserved -= $campaign->estimated_cost;
            $wallet->save();
        }

        // Soft delete
        $campaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campaign deleted successfully',
        ]);
    }

    /**
     * Duplicate a campaign
     * 
     * POST /api/campaigns/{id}/duplicate
     * 
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $campaign = Campaign::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'error' => 'Campaign not found',
            ], 404);
        }

        $newCampaign = $campaign->replicate();
        $newCampaign->name = $campaign->name . ' (Copy)';
        $newCampaign->status = 'Draft';
        $newCampaign->schedule_at = null;
        $newCampaign->sent_at = null;
        $newCampaign->completed_at = null;
        $newCampaign->actual_cost = 0;
        $newCampaign->save();

        return response()->json([
            'success' => true,
            'data' => [
                'campaignId' => $newCampaign->id,
                'name' => $newCampaign->name,
            ],
            'message' => 'Campaign duplicated successfully',
        ], 201);
    }
}
