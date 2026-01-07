<?php

namespace App\Http\Controllers;

use App\Models\OptOut;
use App\Models\Contact;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class OptOutController extends Controller
{
    /**
     * List all opt-outs for the user
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $channel = $request->query('channel');
        $search = $request->query('search');
        $perPage = $request->query('per_page', 50);

        $query = OptOut::forUser($user->id)->with('campaign:id,name');

        if ($channel) {
            $query->where('channel', $channel);
        }

        if ($search) {
            $query->where('recipient', 'like', "%{$search}%");
        }

        $optOuts = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $optOuts->items(),
            'meta' => [
                'current_page' => $optOuts->currentPage(),
                'last_page' => $optOuts->lastPage(),
                'per_page' => $optOuts->perPage(),
                'total' => $optOuts->total(),
            ],
        ]);
    }

    /**
     * Add a new opt-out (manual)
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recipient' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'in:sms,email,all'],
            'reason' => ['sometimes', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $recipient = $request->input('recipient');
        $channel = $request->input('channel');

        // Check if already opted out
        $exists = OptOut::forUser($user->id)
            ->forRecipient($recipient)
            ->where(function ($q) use ($channel) {
                $q->where('channel', $channel)
                  ->orWhere('channel', 'all');
            })
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'error' => 'This recipient is already opted out.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $optOut = OptOut::create([
                'user_id' => $user->id,
                'recipient' => $recipient,
                'channel' => $channel,
                'reason' => $request->input('reason', 'Manual opt-out'),
                'source' => 'manual',
            ]);

            // Also update contact if exists
            $contact = Contact::forUser($user->id)
                ->where(function ($q) use ($recipient) {
                    $q->where('phone', $recipient)
                      ->orWhere('email', $recipient);
                })
                ->first();

            if ($contact) {
                $contact->optOut();
            }

            DB::commit();

            AuditLog::log($user->id, 'create', 'opt_out', $optOut->id, [], [
                'recipient' => $recipient,
                'channel' => $channel,
            ]);

            return response()->json([
                'success' => true,
                'data' => $optOut,
                'message' => 'Opt-out added successfully',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to add opt-out: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove an opt-out (re-subscribe)
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $optOut = OptOut::forUser($user->id)->find($id);

        if (!$optOut) {
            return response()->json([
                'success' => false,
                'error' => 'Opt-out record not found',
            ], 404);
        }

        DB::beginTransaction();

        try {
            // Also update contact if exists
            $contact = Contact::forUser($user->id)
                ->where(function ($q) use ($optOut) {
                    $q->where('phone', $optOut->recipient)
                      ->orWhere('email', $optOut->recipient);
                })
                ->first();

            if ($contact) {
                // Check if there are other opt-outs for this contact
                $otherOptOuts = OptOut::forUser($user->id)
                    ->where('id', '!=', $id)
                    ->where(function ($q) use ($optOut) {
                        $q->where('recipient', $optOut->recipient);
                    })
                    ->exists();

                if (!$otherOptOuts) {
                    $contact->optIn();
                }
            }

            AuditLog::log($user->id, 'delete', 'opt_out', $optOut->id, [
                'recipient' => $optOut->recipient,
                'channel' => $optOut->channel,
            ], []);

            $optOut->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Opt-out removed successfully. Recipient can now receive messages.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to remove opt-out: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk add opt-outs
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recipients' => ['required', 'array', 'min:1', 'max:1000'],
            'recipients.*' => ['string', 'max:255'],
            'channel' => ['required', 'in:sms,email,all'],
            'reason' => ['sometimes', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $recipients = array_unique($request->input('recipients'));
        $channel = $request->input('channel');
        $reason = $request->input('reason', 'Bulk opt-out');

        $added = 0;
        $skipped = 0;

        DB::beginTransaction();

        try {
            foreach ($recipients as $recipient) {
                $exists = OptOut::forUser($user->id)
                    ->forRecipient($recipient)
                    ->where(function ($q) use ($channel) {
                        $q->where('channel', $channel)
                          ->orWhere('channel', 'all');
                    })
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                OptOut::create([
                    'user_id' => $user->id,
                    'recipient' => $recipient,
                    'channel' => $channel,
                    'reason' => $reason,
                    'source' => 'bulk',
                ]);

                $added++;
            }

            // Update contacts
            Contact::forUser($user->id)
                ->where(function ($q) use ($recipients) {
                    $q->whereIn('phone', $recipients)
                      ->orWhereIn('email', $recipients);
                })
                ->update(['opt_out' => true]);

            DB::commit();

            AuditLog::log($user->id, 'bulk_create', 'opt_out', null, [], [
                'count' => $added,
                'channel' => $channel,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'added' => $added,
                    'skipped' => $skipped,
                ],
                'message' => "Added {$added} opt-outs, skipped {$skipped} duplicates",
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to add opt-outs: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if a recipient is opted out
     */
    public function check(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recipient' => ['required', 'string'],
            'channel' => ['sometimes', 'in:sms,email,all'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $recipient = $request->input('recipient');
        $channel = $request->input('channel', 'all');

        $isOptedOut = OptOut::isOptedOut($user->id, $recipient, $channel);

        return response()->json([
            'success' => true,
            'data' => [
                'recipient' => $recipient,
                'channel' => $channel,
                'optedOut' => $isOptedOut,
            ],
        ]);
    }
}
