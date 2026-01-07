<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserRole;
use App\Models\Campaign;
use App\Models\Message;
use App\Models\WalletTransaction;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminController extends Controller
{
    /**
     * Get system statistics
     */
    public function stats(Request $request): JsonResponse
    {
        $stats = [
            'users' => [
                'total' => User::count(),
                'verified' => User::whereNotNull('email_verified_at')->count(),
                'newToday' => User::whereDate('created_at', today())->count(),
                'newThisMonth' => User::whereMonth('created_at', now()->month)->count(),
            ],
            'campaigns' => [
                'total' => Campaign::count(),
                'sms' => Campaign::where('channel', 'sms')->count(),
                'email' => Campaign::where('channel', 'email')->count(),
                'active' => Campaign::whereIn('status', ['Queued', 'Sending'])->count(),
            ],
            'messages' => [
                'total' => Message::count(),
                'delivered' => Message::where('status', 'Delivered')->count(),
                'failed' => Message::where('status', 'Failed')->count(),
                'pending' => Message::whereIn('status', ['Pending', 'Queued', 'Sent', 'Awaiting DLR'])->count(),
            ],
            'revenue' => [
                'total' => WalletTransaction::where('type', 'credit')->where('status', 'completed')->sum('amount'),
                'thisMonth' => WalletTransaction::where('type', 'credit')
                    ->where('status', 'completed')
                    ->whereMonth('created_at', now()->month)
                    ->sum('amount'),
                'today' => WalletTransaction::where('type', 'credit')
                    ->where('status', 'completed')
                    ->whereDate('created_at', today())
                    ->sum('amount'),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * List all users (paginated)
     */
    public function users(Request $request): JsonResponse
    {
        $perPage = $request->query('per_page', 25);
        $search = $request->query('search');
        $accountType = $request->query('account_type');

        $query = User::with(['wallet', 'roles', 'account']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($accountType) {
            $query->where('account_type', $accountType);
        }

        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Get single user details
     */
    public function showUser(Request $request, int $id): JsonResponse
    {
        $user = User::with(['wallet', 'roles', 'account'])->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'User not found',
            ], 404);
        }

        $stats = [
            'campaigns' => Campaign::where('user_id', $id)->count(),
            'messagesSent' => Message::whereHas('campaign', fn($q) => $q->where('user_id', $id))->count(),
            'totalSpent' => WalletTransaction::whereHas('wallet', fn($q) => $q->where('user_id', $id))
                ->where('type', 'debit')
                ->sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'stats' => $stats,
            ],
        ]);
    }

    /**
     * Update user role
     */
    public function updateRole(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'role' => ['required', 'in:admin,moderator,user'],
            'action' => ['required', 'in:add,remove'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'User not found',
            ], 404);
        }

        $role = $request->input('role');
        $action = $request->input('action');

        if ($action === 'add') {
            UserRole::assignRole($user->id, $role);
            $message = "Role '{$role}' added to user";
        } else {
            UserRole::removeRole($user->id, $role);
            $message = "Role '{$role}' removed from user";
        }

        AuditLog::log(
            $request->user()->id,
            'update_role',
            'user',
            $id,
            ['action' => $action, 'role' => $role],
            []
        );

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'roles' => $user->roles()->pluck('role'),
            ],
        ]);
    }

    /**
     * Suspend/unsuspend user
     */
    public function toggleSuspension(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'User not found',
            ], 404);
        }

        // Prevent self-suspension
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot suspend yourself',
            ], 422);
        }

        $isSuspended = $user->hasRole('suspended');

        if ($isSuspended) {
            UserRole::removeRole($user->id, 'suspended');
            $message = 'User unsuspended';
        } else {
            UserRole::assignRole($user->id, 'suspended');
            // Revoke all tokens
            $user->tokens()->delete();
            $message = 'User suspended';
        }

        AuditLog::log(
            $request->user()->id,
            $isSuspended ? 'unsuspend' : 'suspend',
            'user',
            $id,
            [],
            []
        );

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * Add credits to user wallet
     */
    public function addCredits(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'User not found',
            ], 404);
        }

        $wallet = $user->wallet;

        if (!$wallet) {
            return response()->json([
                'success' => false,
                'error' => 'User wallet not found',
            ], 404);
        }

        $amount = (float) $request->input('amount');
        $reason = $request->input('reason');

        $wallet->credit($amount, "Admin credit: {$reason}", 'ADMIN-' . $request->user()->id);

        AuditLog::log(
            $request->user()->id,
            'add_credits',
            'wallet',
            $wallet->id,
            ['amount' => $amount, 'reason' => $reason],
            []
        );

        return response()->json([
            'success' => true,
            'message' => "Added R{$amount} to user wallet",
            'data' => [
                'newBalance' => $wallet->fresh()->balance,
            ],
        ]);
    }

    /**
     * Get audit logs
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $perPage = $request->query('per_page', 50);
        $userId = $request->query('user_id');
        $action = $request->query('action');
        $entityType = $request->query('entity_type');

        $query = AuditLog::with('user:id,name,email');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($action) {
            $query->where('action', $action);
        }

        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Get pending EFT payments
     */
    public function pendingEftPayments(Request $request): JsonResponse
    {
        $payments = WalletTransaction::where('payment_method', 'eft')
            ->where('status', 'pending')
            ->with('wallet.user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $payments,
        ]);
    }

    /**
     * Get system health
     */
    public function health(Request $request): JsonResponse
    {
        $health = [
            'database' => true,
            'queue' => true,
            'storage' => true,
            'smsGateway' => true,
            'emailService' => true,
        ];

        // Check database
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $health['database'] = false;
        }

        // Check queue (simplified)
        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'status' => array_reduce($health, fn($c, $v) => $c && $v, true) ? 'healthy' : 'degraded',
                'services' => $health,
                'queue' => [
                    'pending' => $pendingJobs,
                    'failed' => $failedJobs,
                ],
                'timestamp' => now()->toISOString(),
            ],
        ]);
    }
}
