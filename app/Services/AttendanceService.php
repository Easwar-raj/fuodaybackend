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
            // === Fetch Policies for this Admin user ===
            $policies = Policies::where('admin_user_id', $adminUserId)
                ->whereIn('title', [
                    "general_shift",
                    "daily_work_hours",
                    "daily_break_hours",
                    "is_late_and_count",
                    "weekoff",
                    "salary_period",
                    "salary_date"
                ])->pluck('policy', 'title');

            $salaryPeriod = $policies['salary_period'] ?? null;
            $salaryDateDay = $policies['salary_date'] ?? null;

            if ($salaryPeriod && $salaryDateDay) {
                [$periodStart, $periodEnd] = explode('To', $salaryPeriod);
                $periodStart = (int) trim($periodStart);
                $periodEndRaw = trim($periodEnd);

                if ((int)$today->format('d') === (int)$periodEndRaw || strtolower($periodEndRaw) === 'monthend' && $today->isLastOfMonth()) {
                    foreach ($usersGroup as $webUser) {
                        $userId = $webUser->id;
                        $payroll = Payroll::where('web_user_id', $userId)->first();
                        if (!$payroll) continue;

                        $now = Carbon::now();
                        $year = $now->year;
                        $month = $now->month;

                        // Calculate Start & End Dates
                        if (is_numeric($periodEndRaw)) {
                            $periodEnd = (int) $periodEndRaw;
                            if ($periodEnd < $periodStart) {
                                $startDate = Carbon::create($year, $month, 1)->subMonth()->day($periodStart);
                                $endDate = Carbon::create($year, $month, $periodEnd);
                            } else {
                                $startDate = Carbon::create($year, $month, $periodStart);
                                $endDate = Carbon::create($year, $month, $periodEnd);
                            }
                        } else {
                            // monthend
                            $startDate = Carbon::create($year, $month, $periodStart);
                            $endDate = (clone $startDate)->addMonth()->endOfMonth();
                        }

                        // Fetch Attendance
                        $attendanceRecords = Attendance::where('web_user_id', $userId)
                            ->whereBetween('date', [$startDate, $endDate])
                            ->get();

                        $paidStatuses = ['Present', 'Half', 'Half Day', 'Leave', 'Holiday', 'Weekoff'];
                        $paidDays = $attendanceRecords->filter(function ($a) use ($paidStatuses) {
                            return in_array($a->status, $paidStatuses);
                        })->count();

                        // Count LOP statuses
                        $lopCount = $attendanceRecords->filter(function ($a) {
                            return stripos($a->status, 'lop') !== false;
                        })->count();

                        $basic = (float) $payroll->monthy_salary ?? 0;
                        $earnings = Payroll::where('web_user_id', $userId)->where('type', 'earnings')->sum('amount');
                        $deductions = Payroll::where('web_user_id', $userId)->where('type', 'deductions')->sum('amount');
                        $periodDays = $endDate->diffInDays($startDate) + 1;
                        $perDaySalary = $basic / $periodDays;

                        $baseDate = now();
                        if ($periodStart > 15) {
                            $baseDate = $baseDate->copy()->addMonth();
                        }

                        $salaryDate = Carbon::createFromDate(
                            $baseDate->year,
                            $baseDate->month,
                            $salaryDateDay
                        )->format('Y-m-d');

                        $totalDeductions = $lopCount * $perDaySalary;
                        $totalSalary = $earnings - $totalDeductions;

                        Payslip::create([
                            'payroll_id' => $payroll->id,
                            'date' => $salaryDate,
                            'time' => null,
                            'month' => $today->format('F'),
                            'basic' => $basic,
                            'overtime' => null,
                            'total_paid_days' => $paidDays,
                            'lop' => $lopCount,
                            'gross' => $earnings,
                            'total_deductions' => $totalDeductions,
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
                $checkin = $attendance->checkin ? Carbon::parse($attendance->checkin) : null;
                $checkout = $attendance->checkout ? Carbon::parse($attendance->checkout) : null;

                // Auto checkout if missing
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
                    $attendance->save();
                }
            }

            // === Handle Missing Attendance ===
            $weeklyHolidays = array_map('strtolower', array_map('trim', explode(',', $policies["weekoff"] ?? '')));
            $isCompanyHoliday = Holidays::whereDate('date', $today)->where('admin_user_id', $adminUserId)->exists();
            $status = $isCompanyHoliday ? 'Holiday' : (in_array($weekday, $weeklyHolidays) ? 'Weekoff' : 'Absent Lop');

            $existingUserIds = $attendances->pluck('web_user_id')->toArray();
            $allUserIds = $userIds->toArray();
            $missingUserIds = array_diff($allUserIds, $existingUserIds);

            foreach ($missingUserIds as $userId) {
                if ($status == 'Absent Lop') {
                    $isLeave = LeaveRequest::where('web_user_id', $userId)->whereDate('from', '<=', $today)->whereDate('to', '>=', $today)->where('type', '!=', 'Permission')->first();
                    if ($isLeave) {
                        $totalAllowed = TotalLeaves::where('admin_user_id', $adminUserId)->where('type', $isLeave->type)->first();
                        if ($isLeave->status !== 'Rejected' && $totalAllowed) {
                            $startDate = $endDate = null;
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