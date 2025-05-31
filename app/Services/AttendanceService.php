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

        $webUsers = WebUser::all()->groupBy('admin_user_id');

        foreach ($webUsers as $adminUserId => $usersGroup) {
            // === Fetch Policies for this Admin ===
            $policies = Policies::where('admin_user_id', $adminUserId)
                ->whereIn('title', [
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

            // === Parse Policies
            $shiftStart = '09:00';
            $shiftEnd = '18:00';
            if (isset($policies["What is your organization's general shift timing?"])) {
                $parts = explode(' - ', $policies["What is your organization's general shift timing?"]);
                if (count($parts) === 2) {
                    $shiftStart = Carbon::parse($parts[0])->format('H:i');
                    $shiftEnd = Carbon::parse($parts[1])->format('H:i');
                }
            }

            $dailyWorkHours = (int) filter_var($policies["What is the standard work time per day in your organization?"] ?? '8', FILTER_SANITIZE_NUMBER_INT);
            $breakTimeHours = (int) filter_var($policies["How many hours of break time are provided per day in your organization?"] ?? '1', FILTER_SANITIZE_NUMBER_INT);

            $lateArrivalWarnings = null;
            if (!empty($policies["Do employees receive LOP for late arrivals? If yes, after how many warnings?"])) {
                preg_match('/\d+/', $policies["Do employees receive LOP for late arrivals? If yes, after how many warnings?"], $matches);
                $lateArrivalWarnings = $matches[0] ?? null;
            }

            $weeklyHolidays = array_map('strtolower', array_map('trim',
                explode(',', $policies["Which days are considered weekly holidays in your organization?"] ?? '')
            ));

            $salaryPeriod = $policies['salary_period'] ?? null;
            $salaryDateDay = $policies['salary_date'] ?? null;
            
            if ($salaryPeriod && $salaryDateDay) {
                $startDay = (int) explode('To', $salaryPeriod)[0];
                if ((int)$today->format('d') === ($startDay)) {
                    foreach ($usersGroup as $webUser) {
                        $userId = $webUser->id;

                        $payroll = Payroll::where('web_user_id', $userId)->first();
                        if (!$payroll) continue;

                        [$periodStart, $periodEnd] = explode('To', $salaryPeriod);
                        $periodDays = Carbon::createFromFormat('d', trim($periodEnd))
                            ->diffInDays(Carbon::createFromFormat('d', trim($periodStart))) + 1;

                        $basic = (float) $payroll->monthy_salary ?? 0;
                        $earnings = Payroll::where('web_user_id', $userId)->where('type', 'earnings')->sum('amount');
                        $deductions = Payroll::where('web_user_id', $userId)->where('type', 'deductions')->sum('amount');
                        $gross = $basic + $earnings;
                        $totalSalary = $gross - $deductions;

                        Payslip::create([
                            'payroll_id' => $payroll->id,
                            'date' => Carbon::createFromFormat('d', $salaryDateDay)->format('Y-m-d'),
                            'time' => null,
                            'month' => $today->format('F'),
                            'basic' => $basic,
                            'overtime' => null,
                            'total_paid_days' => $periodDays,
                            'lop' => 0,
                            'gross' => $gross,
                            'total_deductions' => $deductions,
                            'total_salary' => $totalSalary,
                            'status' => 'unpaid',
                        ]);
                    }
                }
            }

            // === Attendance Verification ===
            $userIds = $usersGroup->pluck('id');
            $attendances = Attendance::whereIn('web_user_id', $userIds)->whereDate('date', $today)->get();
            $isCompanyHoliday = Holidays::whereDate('date', $today)->where('admin_user_id', $adminUserId)->exists();

            foreach ($attendances as $attendance) {
                $userId = $attendance->user_id;
                $attendanceStatus = 'Absent';

                if ($isCompanyHoliday || in_array($weekday, $weeklyHolidays)) {
                    $attendanceStatus = 'Holiday';
                } else {
                    $leave = LeaveRequest::where('web_user_id', $userId)
                        ->whereDate('from', '<=', $today)
                        ->whereDate('to', '>=', $today)
                        ->where('status', 'Approved')->first();

                    if ($leave) {
                        $attendanceStatus = $leave->type === 'Permission' ? 'Permission' : 'Leave';
                    } else {
                        $schedule = Schedule::where('web_user_id', $userId)->whereDate('date', $today)->first();
                        $actualShiftStart = $schedule ? Carbon::parse($schedule->start_time)->format('H:i') : $shiftStart;
                        $checkin = $attendance->checkin ? Carbon::parse($attendance->checkin) : null;
                        $checkout = $attendance->checkout ? Carbon::parse($attendance->checkout) : null;

                        if ($checkin && $checkout) {
                            $hoursWorked = $checkout->diffInMinutes($checkin) / 60 - $breakTimeHours;
                            $lateThreshold = Carbon::createFromFormat('H:i', $actualShiftStart)->addMinutes(15);

                            if ($hoursWorked >= $dailyWorkHours) {
                                if ($checkin->gt($lateThreshold)) {
                                    $attendance->late_warnings = ($attendance->late_warnings ?? 0) + 1;
                                    $attendanceStatus = ($lateArrivalWarnings && $attendance->late_warnings > $lateArrivalWarnings) ? 'LOP' : 'Late Present';
                                } else {
                                    $attendanceStatus = 'Present';
                                }
                            } elseif ($hoursWorked >= ($dailyWorkHours / 2)) {
                                $attendanceStatus = 'Half Day';
                            } else {
                                $attendanceStatus = 'LOP';
                            }
                        } elseif ($checkin && !$checkout) {
                            $attendanceStatus = 'Checkout Missing';
                        } elseif (!$checkin && $checkout) {
                            $attendanceStatus = 'Checkin Missing';
                        }
                    }
                }

                $attendance->status = $attendanceStatus;
                $attendance->save();

                // === Update Payslip LOP ===
                if ($attendanceStatus === 'LOP') {
                    $payroll = Payroll::where('web_user_id', $userId)->first();
                    if (!$payroll) continue;

                    $payslip = Payslip::where('payroll_id', $payroll->id)
                        ->where('month', $today->format('F'))
                        ->whereYear('date', $today->year)->first();
                    if (!$payslip) continue;

                    $payslip->lop += 1;
                    $perDay = $payslip->total_paid_days ? ($payslip->gross / $payslip->total_paid_days) : 0;
                    $payslip->total_deductions += $perDay;
                    $payslip->total_salary -= $perDay;
                    $payslip->save();
                }
            }
        }
    }
}