<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Holidays;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Policies;
use App\Models\Schedule;
use App\Models\TotalLeaves;
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
                    "general_shift", // 09:00 - 06:00
                    // "What are the total weekly working hours in your organization?",
                    "daily_work_hours", // 8
                    "daily_break_hours", // 1
                    "is_late_and_count",
                    // "Is LOP applied for unauthorized leaves?",
                    // "Is LOP applied when employees exhaust their leave quota?",
                    "weekoff", // Saturday, Sunday
                    "salary_period", // 26To25
                    "salary_date" // 05
                ])->pluck('policy', 'title');

            // === Parse Policies
            // $shiftStart = '09:00';
            // $shiftEnd = '18:00';
            // if (isset($policies["general_shift"])) {
            //     $parts = explode(' - ', $policies["general_shift"]);
            //     if (count($parts) === 2) {
            //         $shiftStart = Carbon::parse($parts[0])->format('H:i');
            //         $shiftEnd = Carbon::parse($parts[1])->format('H:i');
            //     }
            // }

            // $dailyWorkHours = (int) filter_var($policies["daily_work_hours"] ?? '8', FILTER_SANITIZE_NUMBER_INT);
            // $breakTimeHours = (int) filter_var($policies["daily_break_hours"] ?? '1', FILTER_SANITIZE_NUMBER_INT);

            // $lateArrivalWarnings = null;
            // if (!empty($policies["is_late_and_count"])) {
            //     preg_match('/\d+/', $policies["is_late_and_count"], $matches);
            //     $lateArrivalWarnings = $matches[0] ?? null;
            // }

            $salaryPeriod = $policies['salary_period'] ?? null;
            $salaryDateDay = $policies['salary_date'] ?? null;

            if ($salaryPeriod && $salaryDateDay) {
                [$periodStart, $periodEnd] = explode('To', $salaryPeriod);
                if ((int)$today->format('d') === ($periodEnd)) {
                    foreach ($usersGroup as $webUser) {
                        $userId = $webUser->id;
                        $payroll = Payroll::where('web_user_id', $userId)->first();
                        if (!$payroll) continue;
                        $periodStart = trim($periodStart);
                        $periodEnd = trim($periodEnd);
                        $now = Carbon::now();
                        $year = $now->year;
                        $month = $now->month;
                        $startDate = Carbon::create($year, $month, (int) $periodStart);
                        if (strtolower($periodEnd) === 'monthend') {
                            $endDate = (clone $startDate)->addMonth()->endOfMonth();
                        } else {
                            $endDay = (int) $periodEnd;
                            if ($endDay < (int) $periodStart) {
                                $endDate = Carbon::create($year, $month, 1)->addMonth()->day($endDay);
                            } else {
                                $endDate = Carbon::create($year, $month, $endDay);
                            }
                        }
                        $periodDays = $endDate->diffInDays($startDate) + 1;

                        $basic = (float) $payroll->monthy_salary ?? 0;
                        $earnings = Payroll::where('web_user_id', $userId)->where('type', 'earnings')->sum('amount');
                        $deductions = Payroll::where('web_user_id', $userId)->where('type', 'deductions')->sum('amount');
                        $totalSalary = $earnings - $deductions;

                        Payslip::create([
                            'payroll_id' => $payroll->id,
                            'date' => Carbon::createFromFormat('d', $salaryDateDay)->format('Y-m-d'),
                            'time' => null,
                            'month' => $today->format('F'),
                            'basic' => $basic,
                            'overtime' => null,
                            'total_paid_days' => $periodDays,
                            'lop' => 0,
                            'gross' => $earnings,
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

            foreach ($attendances as $attendance) {
                $userId = $attendance->user_id;
                // $schedule = Schedule::where('web_user_id', $userId)->whereDate('date', $today)->first();
                // $actualShiftStart = $schedule ? Carbon::parse($schedule->start_time)->format('H:i') : $shiftStart;
                $checkin = $attendance->checkin ? Carbon::parse($attendance->checkin) : null;
                $checkout = $attendance->checkout ? Carbon::parse($attendance->checkout) : null;

                if ($checkin && !$checkout) {
                    $dateOnly = Carbon::parse($attendance->date)->format('Y-m-d');
                    $checkoutTime = Carbon::parse($dateOnly . ' 23:59:00');
                    $checkin = Carbon::parse($dateOnly . ' ' . $attendance->checkin);
                    $diffInSeconds = $checkin->diffInSeconds($checkoutTime);
                    $hours = floor($diffInSeconds / 3600);
                    $minutes = floor(($diffInSeconds % 3600) / 60);
                    $workedHours = sprintf('%02d:%02d hours', $hours, $minutes);
                    $attendance->checkout = '23:59:00';
                    $attendance->worked_hours = $workedHours;
                    $attendance->status = "{$attendance->status}(Auto Logged Off)";
                    $attendance->save();
                }

                // if ($checkin && $checkout) {
                //     $hoursWorked = $checkout->diffInMinutes($checkin) / 60 - $breakTimeHours;
                //     $lateThreshold = Carbon::createFromFormat('H:i', $actualShiftStart)->addMinutes(15);

                //     if ($hoursWorked >= $dailyWorkHours) {
                //         if ($checkin->gt($lateThreshold)) {
                //             $attendance->late_warnings = ($attendance->late_warnings ?? 0) + 1;
                //             $attendanceStatus = ($lateArrivalWarnings && $attendance->late_warnings > $lateArrivalWarnings) ? 'LOP' : 'Late Present';
                //         } else {
                //             $attendanceStatus = 'Present';
                //         }
                //     } elseif ($hoursWorked >= ($dailyWorkHours / 2)) {
                //         $attendanceStatus = 'Half Day';
                //     } else {
                //         $attendanceStatus = 'LOP';
                //     }
                // }

                // $attendance->status = $attendanceStatus;
                // $attendance->save();

                // // === Update Payslip LOP ===
                // if ($attendanceStatus === 'LOP') {
                //     $payroll = Payroll::where('web_user_id', $userId)->first();
                //     if (!$payroll) continue;

                //     $payslip = Payslip::where('payroll_id', $payroll->id)->where('month', $today->format('F'))->whereYear('date', $today->year)->first();
                //     if (!$payslip) continue;

                //     $payslip->lop += 1;
                //     $perDay = $payslip->total_paid_days ? ($payslip->gross / $payslip->total_paid_days) : 0;
                //     $payslip->total_deductions += $perDay;
                //     $payslip->total_salary -= $perDay;
                //     $payslip->save();
                // }
            }

            $weeklyHolidays = array_map('strtolower', array_map('trim', explode(',', $policies["weekoff"] ?? '')));
            $isCompanyHoliday = Holidays::whereDate('date', $today)->where('admin_user_id', $adminUserId)->exists();
            $status = $isCompanyHoliday ? 'Holiday'  : ( in_array($weekday, $weeklyHolidays) ? 'Weekoff' : 'Absent Lop' );
            $existingUserIds = $attendances->pluck('web_user_id')->toArray();
            $allUserIds = $userIds->toArray();
            $missingUserIds = array_diff($allUserIds, $existingUserIds);
            foreach ($missingUserIds as $userId) {
                if ($status == 'Absent Lop') {
                    $isLeave = LeaveRequest::where('web_user_id', $userId)->whereDate('from', '<=', $today)->whereDate('to', '>=', $today)->where('type', '!=', 'Permission')->first();
                    if ($isLeave) {
                        $totalAllowed = TotalLeaves::where('admin_user_id', $adminUserId)->where('type', $isLeave->type)->first();
                        if ($isLeave->status !== 'Rejected' && $totalAllowed) {
                            $startDate = null;
                            $endDate = null;
                            switch ($totalAllowed->period) {
                                case 'yearly':
                                    $startDate = Carbon::now()->startOfYear();
                                    $endDate = Carbon::now()->endOfYear();
                                    break;
                                case 'half-yearly':
                                    $month = Carbon::now()->month;
                                    if ($month <= 6) {
                                        $startDate = Carbon::now()->startOfYear();
                                        $endDate = Carbon::now()->startOfYear()->addMonths(5)->endOfMonth();
                                    } else {
                                        $startDate = Carbon::now()->startOfYear()->addMonths(6);
                                        $endDate = Carbon::now()->endOfYear();
                                    }
                                    break;
                                case 'quarterly':
                                    $startDate = Carbon::now()->firstOfQuarter();
                                    $endDate = Carbon::now()->lastOfQuarter();
                                    break;
                                case 'monthly':
                                    $startDate = Carbon::now()->startOfMonth();
                                    $endDate = Carbon::now()->endOfMonth();
                                    break;
                            }

                            $leavesTaken = LeaveRequest::where('web_user_id', $userId)
                                ->where('status', '!=', 'Rejected')
                                ->where(function ($query) use ($startDate, $endDate) {
                                    $query->whereBetween('from', [$startDate, $endDate])->orWhereBetween('to', [$startDate, $endDate]);
                                })
                                ->get()
                                ->reduce(function ($count, $leave) {
                                    $from = Carbon::parse($leave->from);
                                    $to = Carbon::parse($leave->to);
                                    return $count + $from->diffInDays($to) + 1;
                                }, 0);
                            if ($leavesTaken <= $totalAllowed->total) {
                                $status = 'Leave';
                            } else {
                                $status = 'Leave Lop';
                            }
                        } else {
                            $status = 'Leave Lop';
                        }
                    }
                }
                $parts = explode(' ', $status);
                if (isset($parts[1]) && $parts[1] === 'Lop') {
                    $payroll = Payroll::where('web_user_id', $userId)->first();
                    $payslip = $payroll ? Payslip::where('payroll_id', $payroll->id)->where('month', $today->format('F'))->whereYear('date', $today->year)->first() : null;
                    if ($payslip) {
                        $payslip->lop += 1;
                        $perDay = $payslip->total_paid_days ? ($payslip->gross / $payslip->total_paid_days) : 0;
                        $payslip->total_deductions += $perDay;
                        $payslip->total_salary -= $perDay;
                        $payslip->save();
                    }
                }
                Attendance::create([
                    'web_user_id' => $userId,
                    'emp_id' => WebUser::find($userId)?->emp_id,
                    'emp_name' => WebUser::find($userId)?->name,
                    'date' => $today,
                    'checkin' => null,
                    'checkout' => null,
                    'location' => null,
                    'status' => $status
                ]);
            }
        }
    }
}