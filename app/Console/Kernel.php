<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('check:report')->twiceDaily(7, 19);
        // $schedule->command('inspire')->hourly();
        $schedule->command('updateStatus:campaignLog')->everySixHours();
        // These command will pick job from failed and enqueue back to respective queues
        // $schedule->command('onekfailed:consume')->everyFifteenMinutes();
        $schedule->command('enqueue:failedOnek')->everyFifteenMinutes();
        $schedule->command('enqueue:failedEmail')->everyFifteenMinutes();
        $schedule->command('enqueue:failedSms')->everyFifteenMinutes();
        $schedule->command('enqueue:failedCondition')->everyFifteenMinutes();
        // $schedule->command('enqueue:failedRcs')->everyFifteenMinutes();
        // $schedule->command('enqueue:failedVoice')->everyFifteenMinutes();
        // $schedule->command('enqueue:failedWhatsapp')->everyFifteenMinutes();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
