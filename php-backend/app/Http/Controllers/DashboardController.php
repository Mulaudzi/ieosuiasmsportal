<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Message;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Dashboard Controller
 * 
 * Provides aggregated statistics for the user dashboard.
 */
class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     * 
     * GET /api/dashboard/stats
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user->id;

        // Get wallet balance
        $balance = $user->wallet?->balance ?? 0;

        // Get message counts by channel
        $smsSent = Message::whereHas('campaign', function ($q) use ($userId) {
            $q->where('user_id', $userId)->where('channel', 'sms');
        })->whereIn('status', ['Sent', 'Delivered', 'Awaiting DLR'])->count();

        $emailsSent = Message::whereHas('campaign', function ($q) use ($userId) {
            $q->where('user_id', $userId)->where('channel', 'email');
        })->whereIn('status', ['Sent', 'Delivered'])->count();

        // Get total contacts
        $totalContacts = Contact::where('user_id', $userId)
            ->whereNull('deleted_at')
            ->count();

        // Get message status breakdown
        $messageStats = Message::whereHas('campaign', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })
        ->select('status', DB::raw('count(*) as count'))
        ->groupBy('status')
        ->pluck('count', 'status')
        ->toArray();

        $queued = $messageStats['Queued'] ?? 0;
        $delivered = $messageStats['Delivered'] ?? 0;
        $failed = $messageStats['Failed'] ?? 0;
        $awaitingDlr = $messageStats['Awaiting DLR'] ?? 0;
        $pending = $messageStats['Pending'] ?? 0;

        // Calculate delivery rate
        $totalProcessed = $delivered + $failed;
        $deliveryRate = $totalProcessed > 0 
            ? round(($delivered / $totalProcessed) * 100, 1) 
            : 0;

        // Get daily sent data for chart (last 7 days)
        $dailySent = Message::whereHas('campaign', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })
        ->where('sent_at', '>=', Carbon::now()->subDays(7))
        ->whereIn('status', ['Sent', 'Delivered', 'Awaiting DLR'])
        ->select(
            DB::raw('DATE(sent_at) as date'),
            DB::raw('SUM(CASE WHEN campaigns.channel = "sms" THEN 1 ELSE 0 END) as sms'),
            DB::raw('SUM(CASE WHEN campaigns.channel = "email" THEN 1 ELSE 0 END) as email')
        )
        ->join('campaigns', 'messages.campaign_id', '=', 'campaigns.id')
        ->groupBy('date')
        ->orderBy('date')
        ->get()
        ->map(fn ($row) => [
            'date' => $row->date,
            'sms' => (int) $row->sms,
            'email' => (int) $row->email,
        ])
        ->toArray();

        // Fill missing days with zeros
        $chartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $dayData = collect($dailySent)->firstWhere('date', $date);
            $chartData[] = [
                'date' => $date,
                'day' => Carbon::parse($date)->format('D'),
                'sms' => $dayData['sms'] ?? 0,
                'email' => $dayData['email'] ?? 0,
            ];
        }

        // Get recent campaigns
        $recentCampaigns = Campaign::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'channel' => $campaign->channel,
                'status' => $campaign->status,
                'recipients' => $campaign->total_recipients,
                'sentAt' => $campaign->sent_at?->toISOString(),
                'createdAt' => $campaign->created_at->toISOString(),
            ]);

        // Get monthly comparison
        $thisMonth = Message::whereHas('campaign', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })
        ->whereMonth('sent_at', Carbon::now()->month)
        ->whereYear('sent_at', Carbon::now()->year)
        ->count();

        $lastMonth = Message::whereHas('campaign', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })
        ->whereMonth('sent_at', Carbon::now()->subMonth()->month)
        ->whereYear('sent_at', Carbon::now()->subMonth()->year)
        ->count();

        $monthlyChange = $lastMonth > 0 
            ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
            : ($thisMonth > 0 ? 100 : 0);

        return response()->json([
            'success' => true,
            'data' => [
                'balance' => (float) $balance,
                'smsSent' => $smsSent,
                'emailsSent' => $emailsSent,
                'totalContacts' => $totalContacts,
                'queued' => $queued + $pending,
                'delivered' => $delivered,
                'failed' => $failed,
                'awaitingDlr' => $awaitingDlr,
                'deliveryRate' => $deliveryRate,
                'monthlyChange' => $monthlyChange,
                'charts' => [
                    'dailySent' => $chartData,
                ],
                'recentCampaigns' => $recentCampaigns,
            ],
        ]);
    }
}
