<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AttendanceService;

class VerifyAttendanceStatuses extends Command
{
    protected $signature = 'attendance:verify';

    protected $description = 'Verify and update attendance statuses for the day';

    public function handle()
    {
        AttendanceService::verifyAttendanceStatuses();
        $this->info('Attendance statuses verified and updated.');
    }
}
