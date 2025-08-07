<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\hrms\AttendancePageController;
 
class ProcessExpiredSessions extends Command
{
    // Command signature (what you call from terminal/scheduler)
    protected $signature = 'expired:sessions';
 
    // Description for Artisan list
    protected $description = 'Process expired attendance sessions.';
 
    public function handle()
    {
        // You can call your controller logic here
        $controller = new AttendancePageController();
        $controller->processExpiredSessions();
 
        $this->info('Expired sessions processed successfully.');
    }
}
