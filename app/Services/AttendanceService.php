<?php

namespace App\Services;

use App\Models\Attendance;
use Carbon\Carbon;

class AttendanceService
{
    public static function verifyAttendanceStatuses()
    {
        $today = Carbon::today();
        $weekday = strtolower($today->format('l')); // e.g., 'saturday'
        $attendances = Attendance::whereDate('date', $today)->get();

        // === Fetch Policies ===
        $policies = Policy::whereIn('title', [
            "What is your organization's general shift timing?",
            "What are the total weekly working hours in your organization?",
            "What is the standard work time per day in your organization?",
            "How many hours of break time are provided per day in your organization?",
            "Do employees receive LOP for late arrivals? If yes, after how many warnings?",
            "Is LOP applied for unauthorized leaves?",
            "Is LOP applied when employees exhaust their leave quota?",
            "Which days are considered weekly holidays in your organization?",
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

        $dailyWorkHours = 8;
        if (isset($policies["What is the standard work time per day in your organization?"])) {
            $dailyWorkHours = (int) filter_var($policies["What is the standard work time per day in your organization?"], FILTER_SANITIZE_NUMBER_INT);
        }

        $breakTimeHours = 1;
        if (isset($policies["How many hours of break time are provided per day in your organization?"])) {
            $breakTimeHours = (int) filter_var($policies["How many hours of break time are provided per day in your organization?"], FILTER_SANITIZE_NUMBER_INT);
        }

        $lateArrivalWarnings = null;
        if (isset($policies["Do employees receive LOP for late arrivals? If yes, after how many warnings?"])) {
            preg_match('/\d+/', $policies["Do employees receive LOP for late arrivals? If yes, after how many warnings?"], $matches);
            if ($matches) {
                $lateArrivalWarnings = (int) $matches[0];
            }
        }

        $weeklyHolidays = [];
        if (isset($policies["Which days are considered weekly holidays in your organization?"])) {
            $weeklyHolidays = array_map('strtolower', explode(',', $policies["Which days are considered weekly holidays in your organization?"]));
            $weeklyHolidays = array_map('trim', $weeklyHolidays);
        }

        // === Check if today is a company holiday ===
        $isCompanyHoliday = Holiday::whereDate('date', $today)->exists();

        foreach ($attendances as $attendance) {
            $userId = $attendance->user_id;

            // === Check company holiday first
            if ($isCompanyHoliday) {
                $attendance->status = 'Holiday';
                $attendance->save();
                continue;
            }

            // === Check weekly holiday
            if (in_array($weekday, $weeklyHolidays)) {
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

            // === Fetch shift schedule
            $schedule = Schedule::where('user_id', $userId)->whereDate('date', $today)->first();
            $shiftStart = $schedule ? Carbon::parse($schedule->start_time)->format('H:i') : $defaultShiftStart;
            $shiftEnd = $schedule ? Carbon::parse($schedule->end_time)->format('H:i') : $defaultShiftEnd;

            $expectedWorkHours = $dailyWorkHours;

            $checkin = $attendance->checkin ? Carbon::parse($attendance->checkin) : null;
            $checkout = $attendance->checkout ? Carbon::parse($attendance->checkout) : null;

            if (!$checkin && !$checkout) {
                $attendance->status = 'Absent';
            } else {
                if ($checkin && $checkout) {
                    $hoursWorked = $checkout->diffInMinutes($checkin) / 60 - $breakTimeHours;
                    $shiftStartCarbon = Carbon::createFromFormat('H:i', $shiftStart);
                    $lateThreshold = $shiftStartCarbon->copy()->addMinutes(15);

                    if ($hoursWorked >= $expectedWorkHours) {
                        if ($checkin->gt($lateThreshold)) {
                            $attendance->late_warnings = ($attendance->late_warnings ?? 0) + 1;
                            if ($lateArrivalWarnings && $attendance->late_warnings > $lateArrivalWarnings) {
                                $attendance->status = 'LOP';
                            } else {
                                $attendance->status = 'Late Present';
                            }
                        } else {
                            $attendance->status = 'Present';
                        }
                    } elseif ($hoursWorked >= ($expectedWorkHours / 2)) {
                        $attendance->status = 'Half Day';
                    } else {
                        $attendance->status = 'LOP';
                    }
                } elseif ($checkin && !$checkout) {
                    $attendance->status = 'Checkout Missing';
                } elseif (!$checkin && $checkout) {
                    $attendance->status = 'Checkin Missing';
                }
            }

            $attendance->save();
        }
    }
}
