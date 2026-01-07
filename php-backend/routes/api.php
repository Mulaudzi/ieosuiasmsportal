<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\DlrController;
use App\Http\Controllers\OptOutController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\AdminController;

/*
|--------------------------------------------------------------------------
| IEOSUIA SMS Portal API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api
| Protected routes require Bearer token authentication
|
*/

// =====================================================
// PUBLIC ROUTES (No authentication required)
// =====================================================

// Authentication
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/check', [AuthController::class, 'check']);
    Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// DLR Webhook (called by SMS gateway)
Route::post('/dlr/webhook', [DlrController::class, 'webhook']);

// Payment Webhooks (called by payment gateways)
Route::prefix('payments')->group(function () {
    Route::post('/payfast/itn', [WalletController::class, 'payfastItn']);
    Route::post('/ozow/notify', [WalletController::class, 'ozowNotify']);
});

// Public opt-out endpoint (for unsubscribe links)
Route::post('/optout/public', [OptOutController::class, 'publicOptOut']);


// =====================================================
// PROTECTED ROUTES (Require authentication)
// =====================================================

Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });

    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // SMS Campaigns
    Route::prefix('sms/campaigns')->group(function () {
        Route::get('/', [CampaignController::class, 'index'])->defaults('channel', 'sms');
        Route::post('/', [CampaignController::class, 'createSmsCampaign']);
        Route::post('/create', [CampaignController::class, 'createSmsCampaign']); // Alias
        Route::get('/{id}', [CampaignController::class, 'show']);
        Route::put('/{id}', [CampaignController::class, 'update']);
        Route::delete('/{id}', [CampaignController::class, 'destroy']);
        Route::post('/{id}/cancel', [CampaignController::class, 'cancel']);
        Route::post('/{id}/duplicate', [CampaignController::class, 'duplicate']);
    });

    // Email Campaigns
    Route::prefix('email/campaigns')->group(function () {
        Route::get('/', [CampaignController::class, 'index'])->defaults('channel', 'email');
        Route::post('/', [CampaignController::class, 'createEmailCampaign']);
        Route::post('/create', [CampaignController::class, 'createEmailCampaign']); // Alias
        Route::get('/{id}', [CampaignController::class, 'show']);
        Route::put('/{id}', [CampaignController::class, 'update']);
        Route::delete('/{id}', [CampaignController::class, 'destroy']);
    });

    // Generic Campaign Routes
    Route::prefix('campaigns')->group(function () {
        Route::get('/', [CampaignController::class, 'index']);
        Route::get('/{id}', [CampaignController::class, 'show']);
        Route::get('/{id}/messages', [CampaignController::class, 'messages']);
        Route::get('/{id}/stats', [CampaignController::class, 'stats']);
        Route::delete('/{id}', [CampaignController::class, 'destroy']);
        Route::post('/{id}/duplicate', [CampaignController::class, 'duplicate']);
    });

    // Contacts
    Route::prefix('contacts')->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::post('/', [ContactController::class, 'store']);
        Route::get('/{id}', [ContactController::class, 'show']);
        Route::put('/{id}', [ContactController::class, 'update']);
        Route::delete('/{id}', [ContactController::class, 'destroy']);
        Route::post('/import', [ContactController::class, 'import']);
        Route::get('/export', [ContactController::class, 'export']);
        Route::post('/bulk-delete', [ContactController::class, 'bulkDelete']);
        
        // Contact Groups
        Route::get('/groups', [ContactController::class, 'groups']);
        Route::post('/groups', [ContactController::class, 'createGroup']);
        Route::put('/groups/{id}', [ContactController::class, 'updateGroup']);
        Route::delete('/groups/{id}', [ContactController::class, 'deleteGroup']);
        Route::post('/groups/{id}/add', [ContactController::class, 'addToGroup']);
        Route::post('/groups/{id}/remove', [ContactController::class, 'removeFromGroup']);
    });

    // Templates
    Route::prefix('templates')->group(function () {
        Route::get('/', [TemplateController::class, 'index']);
        Route::post('/', [TemplateController::class, 'store']);
        Route::get('/{id}', [TemplateController::class, 'show']);
        Route::put('/{id}', [TemplateController::class, 'update']);
        Route::delete('/{id}', [TemplateController::class, 'destroy']);
        Route::post('/{id}/duplicate', [TemplateController::class, 'duplicate']);
    });

    // Wallet
    Route::prefix('wallet')->group(function () {
        Route::get('/', [WalletController::class, 'index']);
        Route::get('/balance', [WalletController::class, 'index']); // Alias
        Route::get('/history', [WalletController::class, 'history']);
        Route::post('/buy', [WalletController::class, 'buy']);
    });

    // Opt-Outs
    Route::prefix('optouts')->group(function () {
        Route::get('/', [OptOutController::class, 'index']);
        Route::post('/', [OptOutController::class, 'store']);
        Route::post('/bulk', [OptOutController::class, 'bulkStore']);
        Route::post('/check', [OptOutController::class, 'check']);
        Route::delete('/{id}', [OptOutController::class, 'destroy']);
    });

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('/campaigns', [ReportController::class, 'campaigns']);
        Route::get('/delivery', [ReportController::class, 'delivery']);
        Route::get('/optouts', [ReportController::class, 'optoutsAudit']);
        Route::get('/export/{type}', [ReportController::class, 'export']);
    });

    // Settings
    Route::prefix('settings')->group(function () {
        Route::get('/profile', [SettingsController::class, 'profile']);
        Route::put('/profile', [SettingsController::class, 'updateProfile']);
        Route::post('/branding', [SettingsController::class, 'uploadBranding']);
        Route::get('/sender-ids', [SettingsController::class, 'senderIds']);
        Route::post('/notifications', [SettingsController::class, 'notifications']);
        Route::get('/api-keys', [SettingsController::class, 'apiKeys']);
        Route::post('/api-keys', [SettingsController::class, 'createApiKey']);
        Route::delete('/api-keys/{id}', [SettingsController::class, 'revokeApiKey']);
    });


    // =====================================================
    // ADMIN ROUTES (Require admin role)
    // =====================================================

    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/health', [AdminController::class, 'health']);
        
        // User Management
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/users/{id}', [AdminController::class, 'showUser']);
        Route::put('/users/{id}/role', [AdminController::class, 'updateRole']);
        Route::post('/users/{id}/suspend', [AdminController::class, 'toggleSuspension']);
        Route::post('/users/{id}/credits', [AdminController::class, 'addCredits']);
        
        // Wallet Admin
        Route::post('/wallet/confirm-eft', [WalletController::class, 'confirmEft']);
        Route::get('/wallet/pending-eft', [AdminController::class, 'pendingEftPayments']);
        
        // Audit Logs
        Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
    });

});
