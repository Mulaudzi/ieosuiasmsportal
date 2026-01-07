<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SettingsController extends Controller
{
    /**
     * Get user profile
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $account = $user->account;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'accountType' => $user->account_type,
                    'emailVerified' => $user->email_verified_at !== null,
                    'createdAt' => $user->created_at->toISOString(),
                ],
                'account' => $account ? [
                    'companyName' => $account->company_name,
                    'address' => $account->address,
                    'city' => $account->city,
                    'province' => $account->province,
                    'postalCode' => $account->postal_code,
                    'country' => $account->country,
                    'vatNumber' => $account->vat_number,
                    'logoUrl' => $account->logo_url,
                    'website' => $account->website,
                ] : null,
            ],
        ]);
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'current_password' => ['required_with:new_password', 'string'],
            'new_password' => ['sometimes', 'string', 'min:8', 'confirmed'],
            'company_name' => ['sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'province' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $oldUserValues = $user->toArray();

        // Verify current password if changing password
        if ($request->has('new_password')) {
            if (!Hash::check($request->input('current_password'), $user->password)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Current password is incorrect',
                ], 422);
            }

            $user->password = Hash::make($request->input('new_password'));
        }

        // Update user fields
        if ($request->has('name')) {
            $user->name = $request->input('name');
        }
        if ($request->has('phone')) {
            $user->phone = $request->input('phone');
        }

        $user->save();

        // Update or create account
        $accountData = $request->only([
            'company_name', 'address', 'city', 'province',
            'postal_code', 'country', 'vat_number', 'website'
        ]);

        if (!empty($accountData)) {
            $account = $user->account;
            
            if ($account) {
                $account->update($accountData);
            } else {
                $user->account()->create($accountData);
            }
        }

        AuditLog::log($user->id, 'update', 'user', $user->id, $oldUserValues, $user->fresh()->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
        ]);
    }

    /**
     * Upload branding (logo)
     */
    public function uploadBranding(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'logo' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $file = $request->file('logo');

        // Generate unique filename
        $filename = 'logos/' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();

        // Store file
        $path = Storage::disk('public')->putFileAs('', $file, $filename);

        // Get or create account
        $account = $user->account;
        
        if (!$account) {
            $account = $user->account()->create([
                'logo_url' => Storage::disk('public')->url($path),
            ]);
        } else {
            // Delete old logo if exists
            if ($account->logo_url) {
                $oldPath = str_replace(Storage::disk('public')->url(''), '', $account->logo_url);
                Storage::disk('public')->delete($oldPath);
            }
            
            $account->update([
                'logo_url' => Storage::disk('public')->url($path),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'logoUrl' => $account->logo_url,
            ],
            'message' => 'Logo uploaded successfully',
        ]);
    }

    /**
     * Get sender IDs
     */
    public function senderIds(Request $request): JsonResponse
    {
        $user = $request->user();

        // For now, return default sender IDs
        // In production, this would be stored in a sender_ids table
        $senderIds = [
            [
                'id' => 1,
                'senderId' => config('sms.default_sender', 'IEOSUIA'),
                'status' => 'approved',
                'isDefault' => true,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $senderIds,
        ]);
    }

    /**
     * Update notification preferences
     */
    public function notifications(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email_campaign_completed' => ['sometimes', 'boolean'],
            'email_low_balance' => ['sometimes', 'boolean'],
            'email_weekly_report' => ['sometimes', 'boolean'],
            'sms_campaign_completed' => ['sometimes', 'boolean'],
            'sms_low_balance' => ['sometimes', 'boolean'],
            'low_balance_threshold' => ['sometimes', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        // Store notification preferences in account or a separate table
        $account = $user->account;
        
        if (!$account) {
            $account = $user->account()->create([]);
        }

        // In a full implementation, you'd have a notification_preferences JSON column
        // For now, we'll just acknowledge the update

        return response()->json([
            'success' => true,
            'message' => 'Notification preferences updated',
        ]);
    }

    /**
     * Get API keys
     */
    public function apiKeys(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get user's API tokens
        $tokens = $user->tokens()->get(['id', 'name', 'created_at', 'last_used_at', 'expires_at']);

        return response()->json([
            'success' => true,
            'data' => $tokens->map(function ($token) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'createdAt' => $token->created_at->toISOString(),
                    'lastUsedAt' => $token->last_used_at?->toISOString(),
                    'expiresAt' => $token->expires_at?->toISOString(),
                ];
            }),
        ]);
    }

    /**
     * Create new API key
     */
    public function createApiKey(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'expires_in_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $expiresAt = null;

        if ($request->has('expires_in_days')) {
            $expiresAt = now()->addDays($request->input('expires_in_days'));
        }

        $token = $user->createToken(
            $request->input('name'),
            ['*'],
            $expiresAt
        );

        AuditLog::log($user->id, 'create', 'api_key', null, [], ['name' => $request->input('name')]);

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token->plainTextToken,
                'name' => $request->input('name'),
                'expiresAt' => $expiresAt?->toISOString(),
            ],
            'message' => 'API key created. Please save this token - it will not be shown again.',
        ], 201);
    }

    /**
     * Revoke API key
     */
    public function revokeApiKey(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $token = $user->tokens()->find($id);

        if (!$token) {
            return response()->json([
                'success' => false,
                'error' => 'API key not found',
            ], 404);
        }

        AuditLog::log($user->id, 'delete', 'api_key', $id, ['name' => $token->name], []);

        $token->delete();

        return response()->json([
            'success' => true,
            'message' => 'API key revoked',
        ]);
    }
}
