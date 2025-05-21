<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Attendance;
use App\Models\WebUser;
use App\Models\EmployeeDetails;
use Illuminate\Support\Facades\DB;

class AttendancePageController extends Controller
{
    public function getAttendance($id)
    {
        $attendances = DB::table('attendances')
            ->where('attendances.web_user_id', $id)
            ->select([
                'date',
                'checkin',
                'checkout',
                'status',
            ])
            ->orderBy('date', 'desc')
            ->get();

        if ($attendances->isEmpty()) {
            return response()->json([
                'message' => 'No attendance data found for the given employee',
                'status' => 'error',
                'data' => []
            ], 404);
        }

        $analytics = [
            'total_worked_hours' => 0,
            'days' => [],
            'monthly_counts' => [],
            'total_present' => 0,
            'total_absent' => 0,
            'total_late' => 0,
            'total_early' => 0,
            'total_permission' => 0,
            'total_half_day' => 0,
            'total_punctual' => 0,
            'total_leave' => 0,
            'total_holiday' => 0,
            'average_checkin_time' => null,
            'average_checkout_time' => null,
            'average_attendance_percent' => 0,
            'best_month' => null,
            'graph' => []
        ];

        $checkinTimes = [];
        $checkoutTimes = [];
        $monthlyGraph = [];

        foreach ($attendances as $a) {
            $date = Carbon::parse($a->date);
            $checkin = Carbon::parse($a->checkin);
            $checkout = $a->checkout ? Carbon::parse($a->checkout) : null;
            $date = Carbon::parse($a->date);
            $monthLabel = $date->format('F'); // Jan, Feb...
            $monthKey = $date->format('Y-m');

            // Weekday
            $weekday = $date->format('l'); // Monday, Tuesday...

            // Worked hours
            if ($checkin && $checkout) {
                $workedHours = $checkout->diffInMinutes($checkin) / 60;
            } else {
                $workedHours = 0; // or null, or any default fallback
            }
            $analytics['total_worked_hours'] += $workedHours;

            // Collect for average time
            $checkinTimes[] = $checkin;
            $checkoutTimes[] = $checkout;

            // Monthly attendance count
            $monthKey = $date->format('Y-m');
            if (!isset($analytics['monthly_counts'][$monthKey])) {
                $analytics['monthly_counts'][$monthKey] = 0;
            }
            if (in_array($a->status, ['present', 'late', 'early'])) {
                $analytics['monthly_counts'][$monthKey]++;
            }

            // Count by status
            match ($a->status) {
                'Present' => $analytics['total_present']++,
                'Absent' => $analytics['total_absent']++,
                'Late' => $analytics['total_late']++,
                'Early' => $analytics['total_early']++,
                'Permission' => $analytics['total_permission']++,
                'Half Day' => $analytics['total_half_day']++,
                'Leave' => $analytics['total_leave']++,
                'Holiday' => $analytics['total_holiday']++,
                default => null,
            };

            // Punctual check-in before 09:05 AM
            if ($checkin->format('H:i:s') <= '09:05:00') {
                $analytics['total_punctual']++;
            }

            // Save individual record with details
            $analytics['days'][] = [
                'date' => $date->format('Y-m-d'),
                'day' => $weekday,
                'checkin' => $checkin->format('h:i:s A'),
                'checkout' => $checkout ? $checkout->format('h:i:s A') : null,
                'status' => $a->status,
                'worked_hours' => round($workedHours, 2)
            ];

            if (!isset($monthlyGraph[$monthKey])) {
                $monthlyGraph[$monthKey] = [
                    'month' => $monthLabel,
                    'present' => 0,
                    'absent' => 0,
                    'permission' => 0,
                    'leave' => 0
                ];
            }

            switch (strtolower($a->status)) {
                case 'present':
                case 'late':
                case 'early':
                    $monthlyGraph[$monthKey]['present'] += 1;
                    break;
                case 'absent':
                    $monthlyGraph[$monthKey]['absent'] += 1;
                    break;
                case 'permission':
                    // You can customize this logic based on if it's half/fullday
                    $monthlyGraph[$monthKey]['permission'] += 1;
                    break;
                case 'half day':
                    $monthlyGraph[$monthKey]['present'] += 0.5;
                    $monthlyGraph[$monthKey]['leave'] += 0.5;
                    break;
                case 'leave':
                    $monthlyGraph[$monthKey]['leave'] += 1;
                    break;
            }
        }

        // Final formatting of graph array
        $analytics['graph'] = array_values($monthlyGraph);

        // Average checkin/checkout
        if (count($checkinTimes)) {
            $validCheckinTimes = array_filter($checkinTimes, fn($c) => !is_null($c) && isset($c->timestamp));
            if (count($validCheckinTimes) > 0) {
                $avgCheckin = \Carbon\Carbon::createFromTimestamp(
                    array_sum(array_map(fn ($c) => $c->timestamp, $validCheckinTimes)) / count($validCheckinTimes)
                );
                $analytics['average_checkin_time'] = $avgCheckin->format('h:i:s A');
            } else {
                $analytics['average_checkin_time'] = null; // or 'N/A', '00:00:00', etc.
            }
        }

        if (count($checkoutTimes)) {
            $validCheckoutTimes = array_filter($checkoutTimes, fn($c) => !is_null($c) && isset($c->timestamp));
            if (count($validCheckoutTimes) > 0) {
                $avgTimestamp = array_sum(array_map(fn ($c) => $c->timestamp, $validCheckoutTimes)) / count($validCheckoutTimes);
                $avgCheckout = \Carbon\Carbon::createFromTimestamp($avgTimestamp);
                $analytics['average_checkout_time'] = $avgCheckout->format('h:i:s A');
            } else {
                $analytics['average_checkout_time'] = null; // or 'N/A' or any fallback
            }
        }

        // Average attendance percentage (assuming ~22 workdays per month)
        $totalMonths = count($analytics['monthly_counts']);
        if ($totalMonths) {
            $percentages = array_map(fn ($c) => ($c / 28) * 100, $analytics['monthly_counts']);
            $analytics['average_attendance_percent'] = round(array_sum($percentages) / $totalMonths, 2);
            $bestMonthKey = array_keys($analytics['monthly_counts'], max($analytics['monthly_counts']))[0];
            $analytics['best_month'] = Carbon::parse($bestMonthKey . '-01')->format('F Y');
        }

        return response()->json([
            'message' => 'Attendance data retrieved successfully',
            'status' => 'Success',
            'data' => $analytics
        ], 200);
    }

    public function addAttendance(Request $request)
    {
        // Validate the input (for example: ensure required fields are provided)
        $validated = $request->validate([
            'web_user_id' => 'required|exists:web_users,id',  // Ensure the user exists
            'checkin' => 'required|string',  // Checkin time
        ]);

        $webUser = WebUser::find($request->web_user_id);

        if (!$validated || !$webUser) {
            return response()->json([
                'message' => 'Invalid data or user not found'
            ], 400);
        }

        // Get current date (today)
        $today = Carbon::today()->toDateString();
        $empDetails = EmployeeDetails::where('web_user_id', $request->web_user_id)->first();
        if (!$empDetails) {
            return response()->json(['message' => 'Employee details not found.'], 404);
        }

        $newAttendance = Attendance::create([
            'web_user_id' => $request->web_user_id,
            'emp_id' => $webUser->emp_id,
            'emp_name' => $webUser->name,
            'checkin' => $request->checkin,
            'location' => $empDetails->work_module,
            'date' => $today,
            'status' => 'Present', // Default status
        ]);

        return response()->json(['message' => 'Attendance added successfully.'], 200);
    }

    public function updateAttendance(Request $request)
    {
        // Validate input
        $request->validate([
            'web_user_id' => 'required|exists:web_users,id',
            'checkout' => 'required|string',
        ]);

        // Get today's date
        $today = Carbon::today()->toDateString();

        // Find today's attendance for the given web_user_id
        $attendance = Attendance::where('web_user_id', $request->web_user_id)->where('date', $today)->orderBy('created_at', 'desc')->first();

        // If no attendance record found for today, return error
        if (!$attendance || $attendance->checkout) {
            return response()->json(['message' => 'Attendance record for today not found.'], 404);
        }

        $checkin = Carbon::parse($attendance->checkin);
        $checkout = Carbon::parse($request->checkout);
        $diffInMinutes = $checkin->diffInMinutes($checkout);
        $hours = floor($diffInMinutes / 60);
        $minutes = $diffInMinutes % 60;
        $workedHours = sprintf('%02d:%02d hours', $hours, $minutes);


        // Update checkout time
        $attendance->checkout = $request->checkout;
        $attendance->worked_hours = $workedHours;
        $attendance->save();

        return response()->json(['message' => 'Checkout time updated successfully.'], 200);
    }
}
