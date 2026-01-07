<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Models\Campaign;
use App\Jobs\ProcessCampaignJob;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Process scheduled campaigns every minute
        $schedule->call(function () {
            $campaigns = Campaign::where('status', 'Pending')
                ->where('scheduled_at', '<=', now())
                ->get();

            foreach ($campaigns as $campaign) {
                $campaign->update(['status' => 'Queued']);
                ProcessCampaignJob::dispatch($campaign);
            }
        })->everyMinute()->name('process-scheduled-campaigns')->withoutOverlapping();

        // Clean up old jobs
        $schedule->command('queue:prune-failed --hours=168')->daily();
        
        // Clear old audit logs (older than 90 days)
        $schedule->call(function () {
            \App\Models\AuditLog::where('created_at', '<', now()->subDays(90))->delete();
        })->weekly();

        // Check for stale campaigns (sending for more than 24 hours)
        $schedule->call(function () {
            Campaign::where('status', 'Sending')
                ->where('sent_at', '<', now()->subHours(24))
                ->update(['status' => 'Completed']);
        })->hourly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
