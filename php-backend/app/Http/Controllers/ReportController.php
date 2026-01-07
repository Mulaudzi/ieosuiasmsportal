<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Message;
use App\Models\Contact;
use App\Models\OptOut;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use League\Csv\Writer;

class ReportController extends Controller
{
    /**
     * Get campaign reports summary
     */
    public function campaigns(Request $request): JsonResponse
    {
        $user = $request->user();
        $channel = $request->query('channel');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $query = Campaign::forUser($user->id);

        if ($channel) {
            $query->where('channel', $channel);
        }

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        // Summary stats
        $summary = [
            'totalCampaigns' => (clone $query)->count(),
            'totalSent' => (clone $query)->sum('sent_count'),
            'totalDelivered' => (clone $query)->sum('delivered_count'),
            'totalFailed' => (clone $query)->sum('failed_count'),
            'totalCost' => (clone $query)->sum('actual_cost'),
            'averageDeliveryRate' => 0,
        ];

        if ($summary['totalSent'] > 0) {
            $summary['averageDeliveryRate'] = round(($summary['totalDelivered'] / $summary['totalSent']) * 100, 2);
        }

        // Status breakdown
        $statusBreakdown = (clone $query)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        // Channel breakdown
        $channelBreakdown = (clone $query)
            ->select('channel', DB::raw('count(*) as count'), DB::raw('sum(sent_count) as sent'))
            ->groupBy('channel')
            ->get();

        // Daily stats for chart
        $dailyStats = (clone $query)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as campaigns'),
                DB::raw('sum(sent_count) as sent'),
                DB::raw('sum(delivered_count) as delivered')
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();

        // Recent campaigns
        $recentCampaigns = $query
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['id', 'name', 'channel', 'status', 'sent_count', 'delivered_count', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'statusBreakdown' => $statusBreakdown,
                'channelBreakdown' => $channelBreakdown,
                'dailyStats' => $dailyStats,
                'recentCampaigns' => $recentCampaigns,
            ],
        ]);
    }

    /**
     * Get delivery reports
     */
    public function delivery(Request $request): JsonResponse
    {
        $user = $request->user();
        $campaignId = $request->query('campaign_id');
        $status = $request->query('status');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $query = Message::whereHas('campaign', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        });

        if ($campaignId) {
            $query->where('campaign_id', $campaignId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        // Summary
        $summary = [
            'total' => (clone $query)->count(),
            'delivered' => (clone $query)->where('status', 'Delivered')->count(),
            'failed' => (clone $query)->where('status', 'Failed')->count(),
            'pending' => (clone $query)->whereIn('status', ['Pending', 'Queued', 'Sent', 'Awaiting DLR'])->count(),
        ];

        // Status breakdown
        $statusBreakdown = (clone $query)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        // Hourly distribution
        $hourlyDistribution = (clone $query)
            ->where('status', 'Delivered')
            ->select(DB::raw('HOUR(delivered_at) as hour'), DB::raw('count(*) as count'))
            ->groupBy(DB::raw('HOUR(delivered_at)'))
            ->orderBy('hour')
            ->pluck('count', 'hour');

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'statusBreakdown' => $statusBreakdown,
                'hourlyDistribution' => $hourlyDistribution,
            ],
        ]);
    }

    /**
     * Export report as CSV
     */
    public function export(Request $request, string $type): Response|JsonResponse
    {
        $user = $request->user();
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        switch ($type) {
            case 'campaigns':
                return $this->exportCampaigns($user, $startDate, $endDate);
            case 'messages':
                return $this->exportMessages($user, $startDate, $endDate, $request->query('campaign_id'));
            case 'contacts':
                return $this->exportContacts($user);
            case 'transactions':
                return $this->exportTransactions($user, $startDate, $endDate);
            case 'optouts':
                return $this->exportOptOuts($user);
            default:
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid export type. Valid types: campaigns, messages, contacts, transactions, optouts',
                ], 400);
        }
    }

    private function exportCampaigns($user, $startDate, $endDate): Response
    {
        $query = Campaign::forUser($user->id);

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $campaigns = $query->orderBy('created_at', 'desc')->get();

        $csv = Writer::createFromString();
        $csv->insertOne(['ID', 'Name', 'Channel', 'Status', 'Total Recipients', 'Sent', 'Delivered', 'Failed', 'Cost', 'Created At']);

        foreach ($campaigns as $campaign) {
            $csv->insertOne([
                $campaign->id,
                $campaign->name,
                $campaign->channel,
                $campaign->status,
                $campaign->total_recipients,
                $campaign->sent_count,
                $campaign->delivered_count,
                $campaign->failed_count,
                $campaign->actual_cost,
                $campaign->created_at->format('Y-m-d H:i:s'),
            ]);
        }

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="campaigns_' . date('Y-m-d') . '.csv"',
        ]);
    }

    private function exportMessages($user, $startDate, $endDate, $campaignId): Response
    {
        $query = Message::whereHas('campaign', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->with('campaign:id,name');

        if ($campaignId) {
            $query->where('campaign_id', $campaignId);
        }

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $messages = $query->orderBy('created_at', 'desc')->limit(10000)->get();

        $csv = Writer::createFromString();
        $csv->insertOne(['ID', 'Campaign', 'Recipient', 'Status', 'Cost', 'Sent At', 'Delivered At', 'Error']);

        foreach ($messages as $message) {
            $csv->insertOne([
                $message->id,
                $message->campaign->name ?? 'N/A',
                $message->recipient,
                $message->status,
                $message->cost,
                $message->sent_at?->format('Y-m-d H:i:s'),
                $message->delivered_at?->format('Y-m-d H:i:s'),
                $message->error_message,
            ]);
        }

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="messages_' . date('Y-m-d') . '.csv"',
        ]);
    }

    private function exportContacts($user): Response
    {
        $contacts = Contact::forUser($user->id)->with('groups')->get();

        $csv = Writer::createFromString();
        $csv->insertOne(['ID', 'First Name', 'Last Name', 'Phone', 'Email', 'Company', 'Groups', 'Opted Out', 'Created At']);

        foreach ($contacts as $contact) {
            $csv->insertOne([
                $contact->id,
                $contact->first_name,
                $contact->last_name,
                $contact->phone,
                $contact->email,
                $contact->company,
                $contact->groups->pluck('name')->implode(', '),
                $contact->opt_out ? 'Yes' : 'No',
                $contact->created_at->format('Y-m-d H:i:s'),
            ]);
        }

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="contacts_' . date('Y-m-d') . '.csv"',
        ]);
    }

    private function exportTransactions($user, $startDate, $endDate): Response
    {
        $wallet = $user->wallet;

        if (!$wallet) {
            $csv = Writer::createFromString();
            $csv->insertOne(['No transactions found']);
            return response($csv->toString(), 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="transactions_' . date('Y-m-d') . '.csv"',
            ]);
        }

        $query = $wallet->transactions();

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $transactions = $query->orderBy('created_at', 'desc')->get();

        $csv = Writer::createFromString();
        $csv->insertOne(['ID', 'Type', 'Amount', 'Description', 'Reference', 'Status', 'Created At']);

        foreach ($transactions as $transaction) {
            $csv->insertOne([
                $transaction->id,
                $transaction->type,
                $transaction->amount,
                $transaction->description,
                $transaction->reference,
                $transaction->status,
                $transaction->created_at->format('Y-m-d H:i:s'),
            ]);
        }

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="transactions_' . date('Y-m-d') . '.csv"',
        ]);
    }

    private function exportOptOuts($user): Response
    {
        $optOuts = OptOut::forUser($user->id)->with('campaign:id,name')->get();

        $csv = Writer::createFromString();
        $csv->insertOne(['ID', 'Recipient', 'Channel', 'Reason', 'Source', 'Campaign', 'Created At']);

        foreach ($optOuts as $optOut) {
            $csv->insertOne([
                $optOut->id,
                $optOut->recipient,
                $optOut->channel,
                $optOut->reason,
                $optOut->source,
                $optOut->campaign->name ?? 'N/A',
                $optOut->created_at->format('Y-m-d H:i:s'),
            ]);
        }

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="optouts_' . date('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * Get opt-out audit report
     */
    public function optoutsAudit(Request $request): JsonResponse
    {
        $user = $request->user();

        $summary = [
            'total' => OptOut::forUser($user->id)->count(),
            'sms' => OptOut::forUser($user->id)->where('channel', 'sms')->count(),
            'email' => OptOut::forUser($user->id)->where('channel', 'email')->count(),
            'all' => OptOut::forUser($user->id)->where('channel', 'all')->count(),
        ];

        $bySource = OptOut::forUser($user->id)
            ->select('source', DB::raw('count(*) as count'))
            ->groupBy('source')
            ->pluck('count', 'source');

        $recent = OptOut::forUser($user->id)
            ->with('campaign:id,name')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        $monthlyTrend = OptOut::forUser($user->id)
            ->select(
                DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                DB::raw('count(*) as count')
            )
            ->groupBy(DB::raw('DATE_FORMAT(created_at, "%Y-%m")'))
            ->orderBy('month', 'desc')
            ->limit(12)
            ->pluck('count', 'month');

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'bySource' => $bySource,
                'monthlyTrend' => $monthlyTrend,
                'recent' => $recent,
            ],
        ]);
    }
}
