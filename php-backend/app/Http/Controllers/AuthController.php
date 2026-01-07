<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Wallet;
use App\Models\UserRole;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Authentication Controller
 * 
 * This controller handles user registration, login, and authentication.
 * Replace mock responses with actual database operations.
 */
class AuthController extends Controller
{
    /**
     * Register a new user
     * 
     * POST /api/auth/register
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
            ],
            'account_type' => ['sometimes', 'string', 'in:Individual,Business,Organization'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Create user
            $user = User::create([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'password' => Hash::make($request->input('password')),
                'account_type' => $request->input('account_type', 'Individual'),
            ]);

            // Assign default 'user' role
            UserRole::create([
                'user_id' => $user->id,
                'role' => 'user',
            ]);

            // Create wallet with 0 balance
            Wallet::create([
                'user_id' => $user->id,
                'balance' => 0.00,
                'reserved' => 0.00,
            ]);

            // Create default settings
            UserSetting::create([
                'user_id' => $user->id,
                'timezone' => 'Africa/Johannesburg',
            ]);

            // Generate API token
            $token = $user->createToken('auth-token')->plainTextToken;

            DB::commit();

            // Log the registration
            activity()
                ->causedBy($user)
                ->performedOn($user)
                ->log('User registered');

            return response()->json([
                'success' => true,
                'data' => [
                    'userId' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'accountType' => $user->account_type,
                    'token' => $token,
                ],
                'message' => 'Registration successful',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'error' => 'Registration failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Login user
     * 
     * POST /api/auth/login
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Find user by email
        $user = User::where('email', $request->input('email'))->first();

        // Verify credentials
        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid email or password',
            ], 401);
        }

        // Check if user is soft deleted
        if ($user->deleted_at !== null) {
            return response()->json([
                'success' => false,
                'error' => 'Account has been deactivated. Please contact support.',
            ], 403);
        }

        // Revoke existing tokens (optional - for single session)
        // $user->tokens()->delete();

        // Generate new token
        $token = $user->createToken('auth-token')->plainTextToken;

        // Get user roles
        $roles = $user->roles()->pluck('role')->toArray();

        // Get wallet balance
        $wallet = $user->wallet;

        // Log the login
        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['ip' => $request->ip()])
            ->log('User logged in');

        return response()->json([
            'success' => true,
            'data' => [
                'userId' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'accountType' => $user->account_type,
                'roles' => $roles,
                'balance' => $wallet?->balance ?? 0,
                'token' => $token,
            ],
            'message' => 'Login successful',
        ]);
    }

    /**
     * Logout user
     * 
     * POST /api/auth/logout
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Revoke current token
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Logout failed',
            ], 500);
        }
    }

    /**
     * Get current authenticated user
     * 
     * GET /api/auth/me
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $user->wallet;
        $roles = $user->roles()->pluck('role')->toArray();
        $settings = $user->settings;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'accountType' => $user->account_type,
                'emailVerified' => $user->email_verified_at !== null,
                'roles' => $roles,
                'balance' => $wallet?->balance ?? 0,
                'settings' => $settings,
                'createdAt' => $user->created_at->toISOString(),
            ],
        ]);
    }

    /**
     * Check if user is authenticated (for frontend auth checks)
     * 
     * GET /api/auth/check
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function check(Request $request): JsonResponse
    {
        if (Auth::check()) {
            return response()->json([
                'success' => true,
                'authenticated' => true,
                'userId' => Auth::id(),
            ]);
        }

        return response()->json([
            'success' => true,
            'authenticated' => false,
        ]);
    }

    /**
     * Refresh token
     * 
     * POST /api/auth/refresh
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Delete current token
        $request->user()->currentAccessToken()->delete();
        
        // Create new token
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
            ],
        ]);
    }
}
