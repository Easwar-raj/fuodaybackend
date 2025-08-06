<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\VerifyAttendanceStatuses::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('attendance:verify')->dailyAt('23:00');
        // Add this new line for processing expired sessions
        $schedule->call(function () {
            $controller = new \App\Http\Controllers\hrms\AttendancePageController();
            $controller->processExpiredSessions();
        })->dailyAt('00:01');
        // $schedule->command('inspire')->hourly();
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
