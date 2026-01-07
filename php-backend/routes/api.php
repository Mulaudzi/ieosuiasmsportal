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
});

// DLR Webhook (called by SMS gateway)
Route::post('/dlr/webhook', [DlrController::class, 'webhook']);

// Payment Webhooks (called by payment gateways)
Route::prefix('payments')->group(function () {
    Route::post('/payfast/itn', [WalletController::class, 'payfastItn']);
    Route::post('/ozow/notify', [WalletController::class, 'ozowNotify']);
});


// =====================================================
// PROTECTED ROUTES (Require authentication)
// =====================================================

Route::middleware('auth:sanctum')->group(function () {

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
        Route::post('/create', [CampaignController::class, 'createSmsCampaign']);
        Route::get('/{id}', [CampaignController::class, 'show']);
    });

    // Email Campaigns
    Route::prefix('email/campaigns')->group(function () {
        Route::get('/', [CampaignController::class, 'index'])->defaults('channel', 'email');
        Route::post('/create', [CampaignController::class, 'createEmailCampaign']);
        Route::get('/{id}', [CampaignController::class, 'show']);
    });

    // Generic Campaign Routes
    Route::prefix('campaigns')->group(function () {
        Route::get('/', [CampaignController::class, 'index']);
        Route::get('/{id}', [CampaignController::class, 'show']);
        Route::delete('/{id}', [CampaignController::class, 'destroy']);
        Route::post('/{id}/duplicate', [CampaignController::class, 'duplicate']);
    });

    // Contacts
    Route::prefix('contacts')->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::post('/', [ContactController::class, 'store']);
        Route::post('/import', [ContactController::class, 'import']);
        Route::get('/export', [ContactController::class, 'export']);
        Route::delete('/', [ContactController::class, 'destroy']);
        
        // Contact Groups
        Route::get('/groups', [ContactController::class, 'groups']);
        Route::post('/groups', [ContactController::class, 'createGroup']);
        Route::post('/groups/{id}/add', [ContactController::class, 'addToGroup']);
    });

    // Wallet
    Route::prefix('wallet')->group(function () {
        Route::get('/', [WalletController::class, 'index']);
        Route::get('/history', [WalletController::class, 'history']);
        Route::post('/buy', [WalletController::class, 'buy']);
        Route::post('/confirm-eft', [WalletController::class, 'confirmEft']); // Admin only
    });

    // Opt-Outs
    Route::prefix('optouts')->group(function () {
        Route::get('/', [OptOutController::class, 'index']);
        Route::post('/', [OptOutController::class, 'store']);
        Route::delete('/{id}', [OptOutController::class, 'destroy']);
        Route::get('/export', [OptOutController::class, 'export']);
    });

    // Templates
    Route::prefix('templates')->group(function () {
        Route::get('/', [TemplateController::class, 'index']);
        Route::post('/', [TemplateController::class, 'store']);
        Route::get('/{id}', [TemplateController::class, 'show']);
        Route::put('/{id}', [TemplateController::class, 'update']);
        Route::delete('/{id}', [TemplateController::class, 'destroy']);
    });

    // Settings
    Route::prefix('settings')->group(function () {
        Route::get('/', [SettingsController::class, 'index']);
        Route::put('/', [SettingsController::class, 'update']);
        Route::put('/password', [SettingsController::class, 'updatePassword']);
        Route::post('/api-key/regenerate', [SettingsController::class, 'regenerateApiKey']);
    });

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('/summary', [ReportController::class, 'summary']);
        Route::get('/campaigns', [ReportController::class, 'campaigns']);
        Route::get('/delivery', [ReportController::class, 'delivery']);
        Route::get('/optouts', [ReportController::class, 'optouts']);
        Route::get('/export', [ReportController::class, 'export']);
    });

});


// =====================================================
// ADMIN ROUTES (Require admin role)
// =====================================================

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    
    // User management
    Route::get('/users', [AdminController::class, 'users']);
    Route::get('/users/{id}', [AdminController::class, 'showUser']);
    Route::put('/users/{id}', [AdminController::class, 'updateUser']);
    Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
    
    // System stats
    Route::get('/stats', [AdminController::class, 'systemStats']);
    
    // EFT confirmation
    Route::post('/wallet/confirm-eft', [WalletController::class, 'confirmEft']);
    
});
