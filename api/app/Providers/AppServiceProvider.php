<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register SMS Gateway Service
        $this->app->singleton(\App\Services\SmsGatewayService::class, function ($app) {
            return new \App\Services\SmsGatewayService();
        });

        // Register Email Service
        $this->app->singleton(\App\Services\EmailService::class, function ($app) {
            return new \App\Services\EmailService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configure API rate limiting
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(
                (int) config('app.api_rate_limit', 120)
            )->by($request->user()?->id ?: $request->ip());
        });

        // Stricter limit for auth endpoints
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Limit for payment endpoints
        RateLimiter::for('payments', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}
