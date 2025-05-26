<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Holidays;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Policies;
use App\Models\Schedule;
use App\Models\WebUser;
use Carbon\Carbon;

class AttendanceService
{
    public static function verifyAttendanceStatuses()
    {
        $today = Carbon::today();
        $weekday = strtolower($today->format('l'));
        $attendances = Attendance::whereDate('date', $today)->get();

        // === Fetch Policies ===
        $policies = Policies::whereIn('title', [
            "What is your organization's general shift timing?",
            "What are the total weekly working hours in your organization?",
            "What is the standard work time per day in your organization?",
            "How many hours of break time are provided per day in your organization?",
            "Do employees receive LOP for late arrivals? If yes, after how many warnings?",
            "Is LOP applied for unauthorized leaves?",
            "Is LOP applied when employees exhaust their leave quota?",
            "Which days are considered weekly holidays in your organization?",
            "salary_period",
            "salary_date"
        ])->pluck('policy', 'title');

        // === Parse Policies ===
        $defaultShiftStart = '09:00';
        $defaultShiftEnd = '18:00';
        if (isset($policies["What is your organization's general shift timing?"])) {
            $parts = explode(' - ', $policies["What is your organization's general shift timing?"]);
            if (count($parts) === 2) {
                $defaultShiftStart = Carbon::parse($parts[0])->format('H:i');
                $defaultShiftEnd = Carbon::parse($parts[1])->format('H:i');
            }
        }

        $dailyWorkHours = isset($policies["What is the standard work time per day in your organization?"])
            ? (int) filter_var($policies["What is the standard work time per day in your organization?"], FILTER_SANITIZE_NUMBER_INT)
            : 8;

        $breakTimeHours = isset($policies["How many hours of break time are provided per day in your organization?"])
            ? (int) filter_var($policies["How many hours of break time are provided per day in your organization?"], FILTER_SANITIZE_NUMBER_INT)
            : 1;

        $lateArrivalWarnings = null;
        if (isset($policies["Do employees receive LOP for late arrivals? If yes, after how many warnings?"])) {
            preg_match('/\d+/', $policies["Do employees receive LOP for late arrivals? If yes, after how many warnings?"], $matches);
            if ($matches) {
                $lateArrivalWarnings = (int) $matches[0];
            }
        }

        $weeklyHolidays = [];
        if (isset($policies["Which days are considered weekly holidays in your organization?"])) {
            $weeklyHolidays = array_map('strtolower', array_map('trim', explode(',', $policies["Which days are considered weekly holidays in your organization?"])));
        }

        // Payslip generation day check
        $salaryPeriod = $policies['salary_period'] ?? null;
        $salaryDateDay = $policies['salary_date'] ?? null;

        if ($salaryPeriod && $salaryDateDay) {
            $startDay = (int) explode('To', $salaryPeriod)[0];
            $triggerDay = $startDay - 1;
            if ((int)$today->format('d') === $triggerDay) {
                foreach ($attendances as $attendance) {
                    $userId = $attendance->user_id;
                    $webUserId = WebUser::find($userId)->web_user_id ?? null;
                    if (!$webUserId) continue;

                    $payroll = Payroll::where('web_user_id', $webUserId)->first();
                    if (!$payroll) continue;

                    // === Determine period days ===
                    [$periodStart, $periodEnd] = explode('To', $salaryPeriod);
                    $periodDays = (int) Carbon::createFromFormat('d', trim($periodEnd))
                        ->diffInDays(Carbon::createFromFormat('d', trim($periodStart)), false) + 1;

                    $basic = $payroll->monthy_salary ?? '0';
                    $basicFloat = (float) $basic;

                    $earnings = Payroll::where('web_user_id', $webUserId)->where('type', 'earnings')->sum('amount');
                    $deductions = Payroll::where('web_user_id', $webUserId)->where('type', 'deductions')->sum('amount');

                    $gross = $basicFloat + $earnings;
                    $totalDeductions = $deductions;
                    $totalSalary = $gross - $totalDeductions;

                    Payslip::create([
                        'payroll_id' => $payroll->id,
                        'date' => Carbon::createFromFormat('d', $salaryDateDay)->format('Y-m-d'),
                        'time' => null,
                        'month' => $today->format('F'),
                        'basic' => $basicFloat,
                        'overtime' => null,
                        'total_paid_days' => $periodDays,
                        'lop' => 0,
                        'gross' => $gross,
                        'total_deductions' => $totalDeductions,
                        'total_salary' => $totalSalary,
                        'status' => 'unpaid',
                    ]);
                }
            }
        }

        // === Check if today is a company holiday ===
        $isCompanyHoliday = Holidays::whereDate('date', $today)->exists();

        foreach ($attendances as $attendance) {
            $userId = $attendance->user_id;

            // === Check holiday types
            if ($isCompanyHoliday || in_array($weekday, $weeklyHolidays)) {
                $attendance->status = 'Holiday';
                $attendance->save();
                continue;
            }

            // === Check leave request for today
            $leaveRequest = LeaveRequest::where('user_id', $userId)
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->where('status', 'Approved')
                ->first();

            if ($leaveRequest) {
                $attendance->status = $leaveRequest->type === 'Permission' ? 'Permission' : 'Leave';
                $attendance->save();
                continue;
            }

            // === Shift Timings
            $schedule = Schedule::where('user_id', $userId)->whereDate('date', $today)->first();
            $shiftStart = $schedule ? Carbon::parse($schedule->start_time)->format('H:i') : $defaultShiftStart;
            $shiftEnd = $schedule ? Carbon::parse($schedule->end_time)->format('H:i') : $defaultShiftEnd;

            $checkin = $attendance->checkin ? Carbon::parse($attendance->checkin) : null;
            $checkout = $attendance->checkout ? Carbon::parse($attendance->checkout) : null;

            $originalStatus = $attendance->status;
            $newStatus = 'Absent';

            if (!$checkin && !$checkout) {
                $newStatus = 'Absent';
            } else {
                if ($checkin && $checkout) {
                    $hoursWorked = $checkout->diffInMinutes($checkin) / 60 - $breakTimeHours;
                    $shiftStartCarbon = Carbon::createFromFormat('H:i', $shiftStart);
                    $lateThreshold = $shiftStartCarbon->copy()->addMinutes(15);

                    if ($hoursWorked >= $dailyWorkHours) {
                        if ($checkin->gt($lateThreshold)) {
                            $attendance->late_warnings = ($attendance->late_warnings ?? 0) + 1;
                            $newStatus = ($lateArrivalWarnings && $attendance->late_warnings > $lateArrivalWarnings) ? 'LOP' : 'Late Present';
                        } else {
                            $newStatus = 'Present';
                        }
                    } elseif ($hoursWorked >= ($dailyWorkHours / 2)) {
                        $newStatus = 'Half Day';
                    } else {
                        $newStatus = 'LOP';
                    }
                } elseif ($checkin && !$checkout) {
                    $newStatus = 'Checkout Missing';
                } elseif (!$checkin && $checkout) {
                    $newStatus = 'Checkin Missing';
                }
            }

            $attendance->status = $newStatus;
            $attendance->save();

            // === If updated to LOP, update payslip
            if ($newStatus === 'LOP') {
                $webUserId = WebUser::find($userId)->web_user_id ?? null;
                if (!$webUserId) continue;

                $payroll = Payroll::where('web_user_id', $webUserId)->first();
                if (!$payroll) continue;

                $payslip = Payslip::where('payroll_id', $payroll->id)->where('month', $today->month)->whereYear('date', $today->year)->first();
                if (!$payslip) continue;

                $existingLOP = (int) ($payslip->lop ?? 0);
                $payslip->lop = $existingLOP + 1;

                // Recalculate salary
                [$periodStart, $periodEnd] = explode('To', $salaryPeriod);
                $periodDays = (int) Carbon::createFromFormat('d', trim($periodEnd))
                    ->diffInDays(Carbon::createFromFormat('d', trim($periodStart)), false) + 1;

                $perDaySalary = $payslip->total_paid_days ? ((float)$payslip->gross / $payslip->total_paid_days) : 0;
                $deductedAmount = $perDaySalary;

                $payslip->total_deductions = (float)$payslip->total_deductions + $deductedAmount;
                $payslip->total_salary = (float)$payslip->total_salary - $deductedAmount;

                $payslip->save();
            }
        }
    }
}
