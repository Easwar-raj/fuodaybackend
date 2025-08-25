<?php

namespace App\Http\Controllers\hrms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Attendance;
use App\Models\WebUser;
use App\Models\EmployeeDetails;
use App\Models\LeaveRequest;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // Add this import
use Illuminate\Support\Facades\Auth;


class AttendancePageController extends Controller
{
    public function getAttendance($id)
    {
        try {
            $attendances = DB::table('attendances')
                ->where('attendances.web_user_id', $id)
                ->select(
                    'date',
                    DB::raw('MIN(checkin) as checkin'),
                    DB::raw('MAX(checkout) as checkout'),
                    DB::raw('GROUP_CONCAT(status) as status_concat'),
                    DB::raw('GROUP_CONCAT(worked_hours) as worked_hours_concat'),
                    DB::raw('MAX(regulation_status) as regulation_status'),
                    DB::raw('MAX(regulation_checkin) as regulation_checkin'),
                    DB::raw('MAX(regulation_checkout) as regulation_checkout')
                )
                ->groupBy('date')
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
                'total_attendance_days' => 0,
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
                'permission_days' => [],
                'graph' => []
            ];

            $checkinTimes = [];
            $checkoutTimes = [];
            $monthlyGraph = [];
            $processedRecords = 0;
            $skippedRecords = 0;

            function parseWorkedHours($string) {
                $totalMinutes = 0;
                $entries = explode(',', $string);

                foreach ($entries as $entry) {
                    $entry = trim(str_replace(' hours', '', strtolower($entry)));

                    if (strpos($entry, ':') !== false) {
                        [$hours, $minutes] = explode(':', $entry);
                        $totalMinutes += ((int)$hours * 60) + (int)$minutes;
                    } elseif (is_numeric($entry)) {
                        $totalMinutes += ((float)$entry * 60);
                    }
                }

                return $totalMinutes;
            }

            $totalWorkedHours = 0;

            foreach ($attendances as $a) {
                try {
                    if (!$a->date) {
                        $skippedRecords++;
                        continue;
                    }

                    $date = Carbon::parse($a->date);

                    // Use regulation checkin/checkout if approved
                    $checkin = null;
                    $checkout = null;

                    if ($a->regulation_status === 'Approved') {
                        if ($a->regulation_checkin) {
                            $checkin = Carbon::parse($a->regulation_checkin);
                        }
                        if ($a->regulation_checkout) {
                            $checkout = Carbon::parse($a->regulation_checkout);
                        }
                    }

                    // Fallback if regulation is not approved or not available
                    if (!$checkin && $a->checkin) {
                        $checkin = Carbon::parse($a->checkin);
                    }
                    if (!$checkout && $a->checkout) {
                        $checkout = Carbon::parse($a->checkout);
                    }

                    $monthLabel = $date->format('F');
                    $monthKey = $date->format('Y-m');

                    $workedMinutes = parseWorkedHours($a->worked_hours_concat);
                    $totalWorkedHours += $workedMinutes;

                    // Collect times for averaging
                    if ($checkin) {
                        $checkinTimes[] = $checkin;
                    }
                    if ($checkout) {
                        $checkoutTimes[] = $checkout;
                    }

                    if (!isset($analytics['monthly_counts'][$monthKey])) {
                        $analytics['monthly_counts'][$monthKey] = 0;
                    }
                    
                    $status = strtolower(trim(explode(',', $a->status_concat)[0]));
                    if (in_array($status, ['present', 'late', 'early', 'on leave', 'leave'])) {
                        $analytics['monthly_counts'][$monthKey]++;
                    }

                    if (in_array($status, ['present', 'late', 'early', 'half day'])) {
                        $analytics['total_attendance_days']++;
                    }

                    match ($status) {
                        'present' => $analytics['total_present']++,
                        'absent' => $analytics['total_absent']++,
                        'late' => $analytics['total_late']++,
                        'early' => $analytics['total_early']++,
                        'half day' => $analytics['total_half_day']++,
                        'leave' => $analytics['total_leave']++,
                        'holiday' => $analytics['total_holiday']++,
                        'on leave' => $analytics['total_leave']++,
                        default => null,
                    };

                    // Count punctuality: checkin on or before 9:00
                    if ($checkin && $checkin->format('H:i:s') <= '09:00:00') {
                        $analytics['total_punctual']++;
                    }

                    $hours1 = floor($workedMinutes / 60);
                    $minutes1 = $workedMinutes % 60;

                    // Save individual record
                    $analytics['days'][] = [
                        'date' => $date->format('Y-m-d'),
                        'day' => $date->format('l'),
                        'checkin' => $checkin ? $checkin->format('h:i:s A') : null,
                        'checkout' => $checkout ? $checkout->format('h:i:s A') : null,
                        'status' => explode(',', $a->status_concat)[0] ?? 'Unknown',
                        'regulation_status' => $a->regulation_status,
                        'worked_hours' => sprintf('%02d:%02d hours', $hours1, $minutes1) ?? '00:00 hours'
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

                    switch ($status) {
                        case 'present':
                        case 'late':
                        case 'early':
                            $monthlyGraph[$monthKey]['present'] += 1;
                            break;
                        case 'absent':
                            $monthlyGraph[$monthKey]['absent'] += 1;
                            break;
                        case 'permission':
                            $monthlyGraph[$monthKey]['permission'] += 1;
                            break;
                        case 'half day':
                            $monthlyGraph[$monthKey]['present'] += 0.5;
                            $monthlyGraph[$monthKey]['leave'] += 0.5;
                            break;
                        case 'leave':
                        case 'on leave':
                            $monthlyGraph[$monthKey]['leave'] += 1;
                            break;
                    }

                    $processedRecords++;

                } catch (\Exception $e) {
                    $skippedRecords++;
                    continue;
                }
            }

            // Format graph array
            $analytics['graph'] = array_values($monthlyGraph);
            $hours = floor($totalWorkedHours / 60);
            $minutes = $totalWorkedHours % 60;
            $analytics['total_worked_hours'] = sprintf('%02d:%02d hours', $hours, $minutes);
            // Calculate average times
            if (!empty($checkinTimes)) {
                $totalMinutes = 0;
                foreach ($checkinTimes as $time) {
                    $totalMinutes += $time->hour * 60 + $time->minute;
                }
                $avgMinutes = $totalMinutes / count($checkinTimes);
                $avgHour = floor($avgMinutes / 60);
                $avgMin = floor($avgMinutes % 60);
                $analytics['average_checkin_time'] = sprintf('%02d:%02d:00', $avgHour, $avgMin);
            }

            if (!empty($checkoutTimes)) {
                $totalMinutes = 0;
                foreach ($checkoutTimes as $time) {
                    $totalMinutes += $time->hour * 60 + $time->minute;
                }
                $avgMinutes = $totalMinutes / count($checkoutTimes);
                $avgHour = floor($avgMinutes / 60);
                $avgMin = floor($avgMinutes % 60);
                $analytics['average_checkout_time'] = sprintf('%02d:%02d:00', $avgHour, $avgMin);
            }

            $totalMonths = count($analytics['monthly_counts']);
            if ($totalMonths > 0) {
                $percentages = array_map(fn($c) => ($c / 28) * 100, $analytics['monthly_counts']);
                $analytics['average_attendance_percent'] = round(array_sum($percentages) / $totalMonths, 2);
                $bestMonthKey = array_keys($analytics['monthly_counts'], max($analytics['monthly_counts']))[0];
                $analytics['best_month'] = Carbon::parse($bestMonthKey . '-01')->format('F Y');
            }

            $permissionDays = LeaveRequest::where('web_user_id', $id)->where('type', 'Permission')->where('status', '!=', 'Rejected')->get();
            $analytics['permission_days'] = $permissionDays;
            $analytics['total_permission'] = $permissionDays->count();
            return response()->json([
                'message' => 'Attendance data retrieved successfully',
                'status' => 'Success',
                'data' => $analytics
            ], 200);

        } catch (Exception $e) {
            Log::error('Fatal error in getAttendance', [
                'user_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error retrieving attendance data',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function addAttendance(Request $request)
    {
        $validated = $request->validate([
            'web_user_id' => 'required|exists:web_users,id'
        ]);

        $webUser = WebUser::find($request->web_user_id);

        if (!$webUser) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $today = Carbon::today()->toDateString();
        $empDetails = EmployeeDetails::where('web_user_id', $request->web_user_id)->first();

        if (!$empDetails) {
            return response()->json(['message' => 'Employee details not found.'], 404);
        }

        $serverTime = Carbon::now();

        $newAttendance = Attendance::create([
            'web_user_id' => $request->web_user_id,
            'emp_id' => $webUser->emp_id,
            'emp_name' => $webUser->name,
            'checkin' => $serverTime->toTimeString(),
            'location' => $empDetails->work_module,
            'date' => $today,
            'status' => 'Present'
        ]);

        return response()->json([
            'message' => 'Attendance added successfully.',
            'status' => 'Success',
            'data' => $serverTime->format('h:i A')
        ], 200);
    }
    
    public function processExpiredSessions()
    {
        try {
            $yesterday = Carbon::yesterday()->toDateString();
            
            // Find all attendance records from yesterday that don't have checkout time
            $expiredSessions = Attendance::where('date', $yesterday)->whereNull('checkout')->whereNull('checkin')->get();
            $processedCount = 0;
            foreach ($expiredSessions as $attendance) {
                if (!$attendance->checkin) {
                    continue; // Skip if no check-in
                }

                $dateOnly = Carbon::parse($attendance->date)->format('Y-m-d');

                // Correctly formatted checkout time
                $checkoutTime = Carbon::parse($dateOnly . ' 23:59:00');

                // Parse checkin time safely
                $checkin = Carbon::parse($dateOnly . ' ' . $attendance->checkin);

                $diffInSeconds = $checkin->diffInSeconds($checkoutTime);
                $hours = floor($diffInSeconds / 3600);
                $minutes = floor(($diffInSeconds % 3600) / 60);
                $workedHours = sprintf('%02d:%02d hours', $hours, $minutes);

                $attendance->checkout = '23:59:00';
                $attendance->worked_hours = $workedHours;
                $attendance->status = 'Auto Logout';
                $attendance->save();

                $processedCount++;
            }
            return response()->json([
                'message' => "Processed {$processedCount} expired sessions",
                'status' => 'Success',
                'processed_count' => $processedCount
            ], 200);

        } catch (Exception $e) {
            Log::error('Error processing expired sessions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error processing expired sessions',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateAttendance(Request $request)
    {
        $request->validate([
            'web_user_id' => 'required|exists:web_users,id'
        ]);

        $today = Carbon::today()->toDateString();

        $attendance = Attendance::where('web_user_id', $request->web_user_id)
            ->where('date', $today)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$attendance || $attendance->checkout) {
            return response()->json(['message' => 'Attendance record for today not found or already checked out.'], 404);
        }

        $checkin = Carbon::parse($attendance->checkin);
        
        if ($request->has('checkout')) {
            $checkoutTimeString = $request->checkout;
            try {
                if (strpos($checkoutTimeString, 'T') !== false) {
                    $checkout = Carbon::parse($checkoutTimeString);
                } elseif (strpos($checkoutTimeString, 'AM') !== false || strpos($checkoutTimeString, 'PM') !== false) {
                    $checkout = Carbon::createFromFormat('g:i A', $checkoutTimeString);
                    $checkout->setDate($checkin->year, $checkin->month, $checkin->day);
                } else {
                    $checkout = Carbon::createFromFormat('H:i', $checkoutTimeString);
                    $checkout->setDate($checkin->year, $checkin->month, $checkin->day);
                }
            } catch (Exception $e) {
                $checkout = Carbon::now();
            }
        } else {
            $checkout = Carbon::now();
        }

        $diffInSeconds = $checkin->diffInSeconds($checkout);
        $hours = floor($diffInSeconds / 3600);
        $minutes = floor(($diffInSeconds % 3600) / 60);

        $workedHours = sprintf('%02d:%02d hours', $hours, $minutes);

        $attendance->checkout = $checkout->toTimeString();
        $attendance->worked_hours = $workedHours;
        
        // Set status based on request parameters
        if ($request->has('timeout') && $request->timeout) {
            $attendance->status = $request->has('reason') && $request->reason === '11:59 PM auto-logout' 
                ? 'Auto Logout' 
                : 'Timeout';
        }
        
        $attendance->save();

        return response()->json([
            'message' => 'Checkout time updated successfully.',
            'status' => 'Success',
            'data' => $checkout->format('h:i A')
        ], 200);
    }

    public function getAttendanceByRole($id)
    {
        $webUser = WebUser::find($id);

        if (!$webUser) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $today = Carbon::today()->toDateString();

        if ($webUser->role === 'employee') {
            // Return attendance for this employee
            $attendances = Attendance::where('web_user_id', $id)->get();
        } elseif ($webUser->role === 'hr') {
            // Get all employees under same admin_user_id
            $webuserIds = WebUser::where('admin_user_id', $webUser->admin_user_id)->pluck('id');
            $attendances = Attendance::whereIn('web_user_id', $webuserIds)->get();
        } else {
            return response()->json(['message' => 'Invalid role'], 400);
        }

        return response()->json([
            'message' => 'Attendance data retrieved successfully',
            'status' => 'Success',
            'data' => $attendances,
        ], 200);
    }

    public function getTodayAttendance($id)
    {
        $webUser = WebUser::find($id);

        if (!$webUser) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $today = Carbon::today()->toDateString();

        $firstattendance = Attendance::where('web_user_id', $id)
            ->whereDate('date', $today)
            ->orderBy('created_at', 'asc')
            ->first();
    
        $lastattendance = Attendance::where('web_user_id', $id)
            ->whereDate('date', $today)
            ->latest()
            ->first();
    
        if ($lastattendance) {
            return response()->json([
                'checkin' => $firstattendance->checkin,
                'checkout' => $lastattendance->checkout,
                'created_at' => $firstattendance->created_at->toDateTimeString()
            ]);
        } else {
            return response()->json([
                'message' => 'No attendance record found for today.'
            ], 404);
        }
    }

    public function getAllAttendanceWithWorkedHours($id)
    {
        $webUser = WebUser::find($id);

        if (!$webUser) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Group all attendance records by date
        $attendancesByDate = Attendance::where('web_user_id', $id)
            ->orderBy('date')
            ->get()
            ->groupBy(function ($item) {
                return Carbon::parse($item->date)->toDateString();
            });

        $data = [];

        foreach ($attendancesByDate as $date => $records) {
            $first = $records->sortBy('created_at')->first();
            $last = $records->sortByDesc('created_at')->first();

            $checkin = $first->checkin;
            $checkout = $last->checkout;

            $workedHours = null;

            if ($checkin && $checkout) {
                $in = Carbon::parse($checkin);
                $out = Carbon::parse($checkout);
                $diff = $in->diff($out);
                $workedHours = $diff->format('%H:%I:%S'); // or $diff->totalMinutes if you prefer
            }

            $data[] = [
                'date' => $date,
                'checkin' => $checkin,
                'checkout' => $checkout ?? '',
                'worked_hours' => $workedHours ?? '',
                'emp_name' => $webUser->name,
                'emp_id' => $webUser->emp_id,
                'location' => $first->location,
                'status' => $first->status,
                'web_user_id' => $webUser->id,
                'created_at' => $first->created_at->toDateTimeString()
            ];
        }

        return response()->json([
            'message' => 'Attendance data retrieved successfully',
            'status' => 'Success',
            'data' => $data
        ]);
    }

    public function calculateLateArrivals($id)
    {
        try {
            // Check if user exists
            $webUser = WebUser::find($id);
            if (!$webUser) {
                return response()->json([
                    'message' => 'User not found',
                    'status' => 'error'
                ], 404);
            }

            // Define standard work start time (9:00 AM)
            $standardStartTime = '09:00:00';
            
            // Get all attendance records for the employee (including emp_name)
            $attendances = DB::table('attendances')
                ->where('web_user_id', $id)
                ->whereNotNull('checkin')
                ->select([
                    DB::raw('MIN(id) as id'),
                    'date',
                    DB::raw('MIN(status) as status'),
                    DB::raw('MIN(emp_name) as emp_name'),
                    DB::raw('MIN(checkin) as checkin'),
                ])
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->get();

            if ($attendances->isEmpty()) {
                return response()->json([
                    'message' => 'No attendance records found',
                    'status' => 'error',
                    'data' => []
                ], 404);
            }

            $lateArrivals = [];
            $totalLateCount = 0;
            $totalLateMinutes = 0;
            $updatedRecords = 0;
            $employeeName = $attendances->first()->emp_name ?? $webUser->name;

            foreach ($attendances as $attendance) {
                // Parse the checkin time
                $checkinTime = Carbon::parse($attendance->checkin);
                $checkinTimeOnly = $checkinTime->format('H:i:s');
                
                // Check if employee arrived late (after 9:00 AM)
                if ($checkinTimeOnly > $standardStartTime) {
                    // Calculate how many minutes late
                    $standardTime = Carbon::parse($attendance->date . ' ' . $standardStartTime);
                    $actualCheckin = Carbon::parse($attendance->date . ' ' . $checkinTimeOnly);
                    $lateMinutes = $standardTime->diffInMinutes($actualCheckin);
                    
                    $totalLateCount++;
                    $totalLateMinutes += $lateMinutes;
                    
                    // Add to late arrivals array
                    $lateArrivals[] = [
                        'date' => $attendance->date,
                        'emp_name' => $attendance->emp_name,
                        'checkin_time' => $checkinTime->format('h:i:s A'),
                        'minutes_late' => $lateMinutes,
                        'hours_minutes_late' => floor($lateMinutes / 60) . 'h ' . ($lateMinutes % 60) . 'm',
                        'current_status' => $attendance->status
                    ];

                    // Update status to 'Late' if not already marked
                    if (strtolower($attendance->status) !== 'late') {
                        DB::table('attendances')
                            ->where('id', $attendance->id)
                            ->update(['status' => 'Late']);
                        $updatedRecords++;
                    }
                }
            }

            // Calculate statistics
            $analytics = [
                'employee_name' => $employeeName,
                'total_late_arrivals' => $totalLateCount,
                'total_late_minutes' => $totalLateMinutes,
                'average_late_minutes' => $totalLateCount > 0 ? round($totalLateMinutes / $totalLateCount, 2) : 0,
                'total_late_hours' => round($totalLateMinutes / 60, 2),
                'records_updated' => $updatedRecords,
                'late_arrival_percentage' => round(($totalLateCount / $attendances->count()) * 100, 2),
                'late_arrivals_details' => $lateArrivals
            ];

            return response()->json([
                'message' => 'Late arrivals calculated successfully',
                'status' => 'Success',
                'data' => $analytics
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error in calculateLateArrivals', [
                'user_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Error calculating late arrivals',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getLateArrivalsByRole($id)
    {
        try {
            $webUser = WebUser::find($id);
            
            if (!$webUser) {
                return response()->json(['message' => 'User not found'], 404);
            }

            $standardStartTime = '09:00:00';
            
            if ($webUser->role === 'employee') {
                // Return late arrivals for this employee only
                return $this->calculateLateArrivals($id);
                
            } elseif ($webUser->role === 'hr') {
                // Get all employees under same admin_user_id
                $employeeIds = WebUser::where('admin_user_id', $webUser->admin_user_id)
                    ->where(function ($query) {
                        $query->where('role', 'employee')->orWhere('role', 'hr');
                    })
                    ->pluck('id');
                
                $allLateArrivals = [];
                $totalStats = [
                    'total_employees' => $employeeIds->count(),
                    'employees_with_late_arrivals' => 0,
                    'total_late_instances' => 0,
                    'total_late_minutes' => 0
                ];
                
                foreach ($employeeIds as $empId) {
                    $employee = WebUser::find($empId);
                    
                    // Get late records with emp_name
                    $lateRecords = DB::table('attendances')
                        ->where('web_user_id', $empId)
                        ->whereNotNull('checkin')
                        ->whereRaw("TIME(checkin) > ?", [$standardStartTime])
                        ->select([
                            'date',
                            DB::raw('MIN(checkin) as checkin'),
                            DB::raw('MIN(emp_name) as emp_name')
                        ])
                        ->groupBy('date')
                        ->orderBy('date', 'desc')
                        ->get();
                    
                    if ($lateRecords->count() > 0) {
                        $totalStats['employees_with_late_arrivals']++;
                        $empLateMinutes = 0;
                        $employeeName = $lateRecords->first()->emp_name ?? $employee->name;
                        
                        foreach ($lateRecords as $record) {
                            $checkinTime = Carbon::parse($record->checkin);
                            $standardTime = Carbon::parse($record->date . ' ' . $standardStartTime);
                            $lateMinutes = $standardTime->diffInMinutes($checkinTime);
                            $empLateMinutes += $lateMinutes;
                        }
                        
                        $allLateArrivals[] = [
                            'employee_id' => $empId,
                            'employee_name' => $employeeName,
                            'late_count' => $lateRecords->count(),
                            'total_late_minutes' => $empLateMinutes,
                            'average_late_minutes' => round($empLateMinutes / $lateRecords->count(), 2)
                        ];
                        
                        $totalStats['total_late_instances'] += $lateRecords->count();
                        $totalStats['total_late_minutes'] += $empLateMinutes;
                    }
                }
                
                return response()->json([
                    'message' => 'Late arrivals data retrieved successfully',
                    'status' => 'Success',
                    'data' => [
                        'statistics' => $totalStats,
                        'employee_details' => $allLateArrivals
                    ]
                ], 200);
                
            } else {
                return response()->json(['message' => 'Invalid role'], 400);
            }
            
        } catch (\Exception $e) {
            Log::error('Error in getLateArrivalsByRole', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Error retrieving late arrivals data',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAllLateArrivals()
    {
        try {
            $user = Auth::user();
            $webUser = WebUser::find($user->id);
            $standardStartTime = '09:00:00';

            $allLateData = [];
            $summaryStats = [
                'total_employees' => 0,
                'employees_with_late_arrivals' => 0,
                'total_late_instances' => 0,
                'total_late_minutes' => 0
            ];

            // Get all employees
            $employees = WebUser::where('admin_user_id', $webUser->admin_user_id)->where(function ($query) {
                $query->where('role', 'employee')->orWhere('role', 'hr');
            })->get();
            $summaryStats['total_employees'] = $employees->count();
            foreach ($employees as $employee) {
                $attendances = DB::table('attendances')
                    ->where('web_user_id', $employee->id)
                    ->whereNotNull('checkin')
                    ->select([
                        DB::raw('MIN(id) as id'),
                        'date',
                        DB::raw('MIN(status) as status'),
                        DB::raw('MIN(emp_name) as emp_name'),
                        DB::raw('MIN(checkin) as checkin'),
                    ])
                    ->groupBy('date')
                    ->orderBy('date', 'desc')
                    ->get();

                if ($attendances->isEmpty()) {
                    continue;
                }

                $lateArrivals = [];
                $totalLateMinutes = 0;
                $lateCount = 0;
                $updatedRecords = 0;
                $employeeName = $employee->name;

                foreach ($attendances as $attendance) {
                    $checkinTime = Carbon::parse($attendance->checkin)->format('H:i:s');

                    if ($checkinTime > $standardStartTime) {
                        $standard = Carbon::parse($attendance->date . ' ' . $standardStartTime);
                        $actual = Carbon::parse($attendance->date . ' ' . $checkinTime);
                        $lateMinutes = $standard->diffInMinutes($actual);

                        $lateArrivals[] = [
                            'date' => $attendance->date,
                            'checkin_time' => Carbon::parse($attendance->checkin)->format('h:i:s A'),
                            'minutes_late' => $lateMinutes,
                            'hours_minutes_late' => floor($lateMinutes / 60) . 'h ' . ($lateMinutes % 60) . 'm',
                            'current_status' => $attendance->status
                        ];

                        $lateCount++;
                        $totalLateMinutes += $lateMinutes;

                        // Update status to 'Late' if not already
                        if (strtolower($attendance->status) !== 'late') {
                            DB::table('attendances')
                                ->where('id', $attendance->id)
                                ->update(['status' => 'Late']);
                            $updatedRecords++;
                        }
                    }
                }

                if ($lateCount > 0) {
                    $summaryStats['employees_with_late_arrivals']++;
                    $summaryStats['total_late_instances'] += $lateCount;
                    $summaryStats['total_late_minutes'] += $totalLateMinutes;

                    $allLateData[] = [
                        'employee_id' => $employee->id,
                        'employee_name' => $employeeName,
                        'late_count' => $lateCount,
                        'total_late_minutes' => $totalLateMinutes,
                        'average_late_minutes' => round($totalLateMinutes / $lateCount, 2),
                        'late_arrival_percentage' => round(($lateCount / $attendances->count()) * 100, 2),
                        'records_updated' => $updatedRecords,
                        'late_arrivals' => $lateArrivals
                    ];
                }
            }

            return response()->json([
                'message' => 'Late arrivals data for all employees retrieved successfully',
                'status' => 'Success',
                'data' => [
                    'summary_stats' => $summaryStats,
                    'employees' => $allLateData
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error in getAllLateArrivals', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Internal server error',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }  

    public function getAllEmployeeAttendance(Request $request)
    {
        $user = Auth::user();
        $webUser = WebUser::find($user->id);
        $employeeIds = WebUser::where('admin_user_id', $webUser->admin_user_id)
            ->where(function ($query) { $query->where('role', 'employee')->orWhere('role', 'hr'); })
            ->pluck('id');
        $query = Attendance::with('employee')->whereIn('web_user_id', $employeeIds);

        if ($request->has('name')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->where('name', 'like', "%" . $request->name . "%");
            });
        }

        if ($request->has('month')) {
            $query->whereMonth('date', $request->month);
        }

        if ($request->has('year')) {
            $query->whereYear('date', $request->year);
        }

        $records = $query->orderBy('date', 'desc')->get();

        $data = $records->map(function ($att) {
            return [
                'name' => $att->employee?->name ?? '',
                'date' => $att->date,
                'checkin' => $att->checkin,
                'checkout' => $att->checkout,
                'status' => $att->status,
                'worked_hours' => $att->worked_hours,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $data
        ], 200);
    }

    public function calculateEarlyArrivals($id)
    {
        try {
            $webUser = WebUser::find($id);
            if (!$webUser) {
                return response()->json([
                    'message' => 'User not found',
                    'status' => 'error'
                ], 404);
            }

            $standardStartTime = '09:00:00';

            $attendances = DB::table('attendances')
                ->where('web_user_id', $id)
                ->whereNotNull('checkin')
                ->select([
                    DB::raw('MIN(id) as id'),
                    'date',
                    DB::raw('MIN(status) as status'),
                    DB::raw('MIN(emp_name) as emp_name'),
                    DB::raw('MIN(checkin) as checkin'),
                ])
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->get();

            if ($attendances->isEmpty()) {
                return response()->json([
                    'message' => 'No attendance records found',
                    'status' => 'error',
                    'data' => []
                ], 404);
            }

            $earlyArrivals = [];
            $totalEarlyCount = 0;
            $totalEarlyMinutes = 0;
            $updatedRecords = 0;
            $employeeName = $webUser->name;

            foreach ($attendances as $attendance) {
                $checkinTime = Carbon::parse($attendance->checkin);
                $checkinTimeOnly = $checkinTime->format('H:i:s');

                // Early if before standard time
                if ($checkinTimeOnly < $standardStartTime) {
                    $standardTime = Carbon::parse($attendance->date . ' ' . $standardStartTime);
                    $actualCheckin = Carbon::parse($attendance->date . ' ' . $checkinTimeOnly);
                    $earlyMinutes = $standardTime->diffInMinutes($actualCheckin);

                    $totalEarlyCount++;
                    $totalEarlyMinutes += $earlyMinutes;

                    $earlyArrivals[] = [
                        'date' => $attendance->date,
                        'emp_name' => $attendance->emp_name,
                        'checkin_time' => $checkinTime->format('h:i:s A'),
                        'minutes_early' => $earlyMinutes,
                        'hours_minutes_early' => floor($earlyMinutes / 60) . 'h ' . ($earlyMinutes % 60) . 'm',
                        'current_status' => $attendance->status
                    ];

                    // Optionally update status to 'Early'
                    if (strtolower($attendance->status) !== 'early') {
                        DB::table('attendances')
                            ->where('id', $attendance->id)
                            ->update(['status' => 'Early']);
                        $updatedRecords++;
                    }
                }
            }

            $analytics = [
                'employee_name' => $employeeName,
                'total_early_arrivals' => $totalEarlyCount,
                'total_early_minutes' => $totalEarlyMinutes,
                'average_early_minutes' => $totalEarlyCount > 0 ? round($totalEarlyMinutes / $totalEarlyCount, 2) : 0,
                'total_early_hours' => round($totalEarlyMinutes / 60, 2),
                'records_updated' => $updatedRecords,
                'early_arrival_percentage' => round(($totalEarlyCount / $attendances->count()) * 100, 2),
                'early_arrivals_details' => $earlyArrivals
            ];

            return response()->json([
                'message' => 'Early arrivals calculated successfully',
                'status' => 'Success',
                'data' => $analytics
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error calculating early arrivals',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAllEarlyArrivals()
    {
        try {
            $user = Auth::user();
            $webUser = WebUser::find($user->id);
            $standardStartTime = '09:00:00';

            $allEarlyData = [];
            $summaryStats = [
                'total_employees' => 0,
                'employees_with_early_arrivals' => 0,
                'total_early_instances' => 0,
                'total_early_minutes' => 0
            ];

            $employees = WebUser::where('admin_user_id', $webUser->admin_user_id)->where(function ($query) {
                $query->where('role', 'employee')->orWhere('role', 'hr');
            })->get();
            $summaryStats['total_employees'] = $employees->count();

            foreach ($employees as $employee) {
                $attendances = DB::table('attendances')
                    ->where('web_user_id', $employee->id)
                    ->whereNotNull('checkin')
                    ->select([
                        DB::raw('MIN(id) as id'),
                        'date',
                        DB::raw('MIN(status) as status'),
                        DB::raw('MIN(emp_name) as emp_name'),
                        DB::raw('MIN(checkin) as checkin'),
                    ])
                    ->groupBy('date')
                    ->orderBy('date', 'desc')
                    ->get();

                if ($attendances->isEmpty()) {
                    continue;
                }

                $earlyArrivals = [];
                $totalEarlyMinutes = 0;
                $earlyCount = 0;
                $updatedRecords = 0;
                $employeeName = $employee->name;

                foreach ($attendances as $attendance) {
                    $checkinTime = Carbon::parse($attendance->checkin)->format('H:i:s');

                    if ($checkinTime < $standardStartTime) {
                        $standard = Carbon::parse($attendance->date . ' ' . $standardStartTime);
                        $actual = Carbon::parse($attendance->date . ' ' . $checkinTime);
                        $earlyMinutes = $standard->diffInMinutes($actual);

                        $earlyArrivals[] = [
                            'date' => $attendance->date,
                            'checkin_time' => Carbon::parse($attendance->checkin)->format('h:i:s A'),
                            'minutes_early' => $earlyMinutes,
                            'hours_minutes_early' => floor($earlyMinutes / 60) . 'h ' . ($earlyMinutes % 60) . 'm',
                            'current_status' => $attendance->status
                        ];

                        $earlyCount++;
                        $totalEarlyMinutes += $earlyMinutes;

                        // Optionally update status to 'Early'
                        if (strtolower($attendance->status) !== 'early') {
                            DB::table('attendances')
                                ->where('id', $attendance->id)
                                ->update(['status' => 'Early']);
                            $updatedRecords++;
                        }
                    }
                }

                if ($earlyCount > 0) {
                    $summaryStats['employees_with_early_arrivals']++;
                    $summaryStats['total_early_instances'] += $earlyCount;
                    $summaryStats['total_early_minutes'] += $totalEarlyMinutes;

                    $allEarlyData[] = [
                        'employee_id' => $employee->id,
                        'employee_name' => $employeeName,
                        'early_count' => $earlyCount,
                        'total_early_minutes' => $totalEarlyMinutes,
                        'average_early_minutes' => round($totalEarlyMinutes / $earlyCount, 2),
                        'early_arrival_percentage' => round(($earlyCount / $attendances->count()) * 100, 2),
                        'records_updated' => $updatedRecords,
                        'early_arrivals' => $earlyArrivals
                    ];
                }
            }

            return response()->json([
                'message' => 'Early arrivals data for all employees retrieved successfully',
                'status' => 'Success',
                'data' => [
                    'summary_stats' => $summaryStats,
                    'employees' => $allEarlyData
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Internal server error',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function calculatePunctualArrivals($id)
    {
        try {
            $webUser = WebUser::find($id);
            if (!$webUser) {
                return response()->json([
                    'message' => 'User not found',
                    'status' => 'error'
                ], 404);
            }

            $standardStartTime = '09:00:00';

            // Get all attendance records with checkin
            $attendances = DB::table('attendances')
                ->where('web_user_id', $id)
                ->whereNotNull('checkin')
                ->select([
                    DB::raw('MIN(id) as id'),
                    'date',
                    DB::raw('MIN(status) as status'),
                    DB::raw('MIN(emp_name) as emp_name'),
                    DB::raw('MIN(checkin) as checkin'),
                ])
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->get();

            if ($attendances->isEmpty()) {
                return response()->json([
                    'message' => 'No attendance records found',
                    'status' => 'error',
                    'data' => []
                ], 404);
            }

            $punctualArrivals = [];
            $totalPunctualCount = 0;
            $updatedRecords = 0;
            $employeeName = $attendances->first()->emp_name ?? $webUser->name;

            foreach ($attendances as $attendance) {
                $checkinTime = Carbon::parse($attendance->checkin);
                $checkinTimeOnly = $checkinTime->format('H:i:s');

                // Punctual if exactly at standard time (09:00:00)
                if ($checkinTimeOnly === $standardStartTime) {
                    $totalPunctualCount++;

                    $punctualArrivals[] = [
                        'date' => $attendance->date,
                        'emp_name' => $attendance->emp_name,
                        'checkin_time' => $checkinTime->format('h:i:s A'),
                        'punctual_time' => '09:00:00 AM',
                        'current_status' => $attendance->status
                    ];

                    // Optionally update status to 'Punctual'
                    if (strtolower($attendance->status) !== 'punctual') {
                        DB::table('attendances')
                            ->where('id', $attendance->id)
                            ->update(['status' => 'Punctual']);
                        $updatedRecords++;
                    }
                }
            }

            $analytics = [
                'employee_name' => $employeeName,
                'total_punctual_arrivals' => $totalPunctualCount,
                'records_updated' => $updatedRecords,
                'punctual_arrival_percentage' => round(($totalPunctualCount / $attendances->count()) * 100, 2),
                'punctual_arrivals_details' => $punctualArrivals
            ];

            return response()->json([
                'message' => 'Punctual arrivals calculated successfully',
                'status' => 'Success',
                'data' => $analytics
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error calculating punctual arrivals',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAllPunctualArrivals()
    {
        try {
            $user = Auth::user();
            $webUser = WebUser::find($user->id);
            $standardStartTime = '09:00:00';

            $allPunctualData = [];
            $summaryStats = [
                'total_employees' => 0,
                'employees_with_punctual_arrivals' => 0,
                'total_punctual_instances' => 0,
                'overall_punctuality_rate' => 0
            ];

            $employees = WebUser::where('admin_user_id', $webUser->admin_user_id)->where(function ($query) {
                $query->where('role', 'employee')->orWhere('role', 'hr');
            })->get();
            $summaryStats['total_employees'] = $employees->count();
            $totalAttendanceRecords = 0;

            foreach ($employees as $employee) {
                $attendances = DB::table('attendances')
                    ->where('web_user_id', $employee->id)
                    ->whereNotNull('checkin')
                    ->select([
                        DB::raw('MIN(id) as id'),
                        'date',
                        DB::raw('MIN(status) as status'),
                        DB::raw('MIN(emp_name) as emp_name'),
                        DB::raw('MIN(checkin) as checkin'),
                    ])
                    ->groupBy('date')
                    ->orderBy('date', 'desc')
                    ->get();

                if ($attendances->isEmpty()) {
                    continue;
                }

                $totalAttendanceRecords += $attendances->count();
                $punctualArrivals = [];
                $punctualCount = 0;
                $updatedRecords = 0;
                $employeeName = $employee->name;

                foreach ($attendances as $attendance) {
                    $checkinTime = Carbon::parse($attendance->checkin)->format('H:i:s');

                    // Check if exactly punctual (09:00:00)
                    if ($checkinTime === $standardStartTime) {
                        $punctualArrivals[] = [
                            'date' => $attendance->date,
                            'checkin_time' => Carbon::parse($attendance->checkin)->format('h:i:s A'),
                            'punctual_time' => '09:00:00 AM',
                            'current_status' => $attendance->status
                        ];

                        $punctualCount++;

                        // Optionally update status to 'Punctual'
                        if (strtolower($attendance->status) !== 'punctual') {
                            DB::table('attendances')
                                ->where('id', $attendance->id)
                                ->update(['status' => 'Punctual']);
                            $updatedRecords++;
                        }
                    }
                }

                if ($punctualCount > 0) {
                    $summaryStats['employees_with_punctual_arrivals']++;
                    $summaryStats['total_punctual_instances'] += $punctualCount;

                    $allPunctualData[] = [
                        'employee_id' => $employee->id,
                        'employee_name' => $employeeName,
                        'punctual_count' => $punctualCount,
                        'punctual_arrival_percentage' => round(($punctualCount / $attendances->count()) * 100, 2),
                        'records_updated' => $updatedRecords,
                        'punctual_arrivals' => $punctualArrivals
                    ];
                }
            }

            // Calculate overall punctuality rate
            if ($totalAttendanceRecords > 0) {
                $summaryStats['overall_punctuality_rate'] = round(($summaryStats['total_punctual_instances'] / $totalAttendanceRecords) * 100, 2);
            }

            return response()->json([
                'message' => 'Punctual arrivals data for all employees retrieved successfully',
                'status' => 'Success',
                'data' => [
                    'summary_stats' => $summaryStats,
                    'employees' => $allPunctualData
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in getAllPunctualArrivals', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Internal server error',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function calculateAbsentDays($id)
    {
        try {
            $webUser = WebUser::find($id);
            if (!$webUser) {
                return response()->json([
                    'message' => 'User not found',
                    'status' => 'error'
                ], 404);
            }

            $attendances = DB::table('attendances')
                ->where('web_user_id', $id)
                ->whereNotNull('checkin')
                ->select([
                    DB::raw('MIN(id) as id'),
                    'date',
                    DB::raw('MIN(status) as status'),
                    DB::raw('MIN(emp_name) as emp_name'),
                    DB::raw('MIN(checkin) as checkin'),
                ])
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->get();

            if ($attendances->isEmpty()) {
                return response()->json([
                    'message' => 'No attendance records found',
                    'status' => 'error',
                    'data' => []
                ], 404);
            }

            $absentRecords = [];
            $totalAbsentCount = 0;
            $employeeName = $webUser->name;

            foreach ($attendances as $attendance) {
                if (strtolower($attendance->status) === 'absent') {
                    $totalAbsentCount++;

                    $absentRecords[] = [
                        'date' => $attendance->date ?? '-',
                        'emp_name' => $attendance->emp_name ?? '-',
                        'checkin' => $attendance->checkin ?? '-',
                        'status' => $attendance->status ?? '-'
                    ];
                }
            }

            $analytics = [
                'employee_name' => $employeeName ?? '-',
                'total_absent_days' => $totalAbsentCount,
                'absent_percentage' => round(($totalAbsentCount / $attendances->count()) * 100, 2),
                'absent_records' => $absentRecords
            ];

            return response()->json([
                'message' => 'Absent days calculated successfully',
                'status' => 'Success',
                'data' => $analytics
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error calculating absent days',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function submitRegulationRequest(Request $request)
    {
        $request->validate([
            'web_user_id' => 'required|exists:attendances,web_user_id',
            'date' => 'required|date',
            'reason' => 'required|string|max:255',
            'regulation_date' => 'required|date',
            'checkin' => 'nullable|date_format:H:i:s',
            'checkout' => 'nullable|date_format:H:i:s',
            'regulation_checkin' => 'nullable|date_format:H:i:s',
            'regulation_checkout' => 'nullable|date_format:H:i:s',
        ]);

        $attendance = Attendance::where('web_user_id', $request->web_user_id)->where('date', $request->date)->first();

        if (!$attendance) {
            return response()->json([
                'status' => 'error',
                'message' => 'Attendance record not found for the given user and date.',
            ], 404);
        }
        $attendance->reason = $request->reason;
        $attendance->regulation_date = $request->regulation_date;
        $attendance->regulation_status = 'Pending';


        if ($request->has('regulation_checkin') && $request->regulation_checkin !== null) {
            $attendance->regulation_checkin = $request->regulation_checkin;
        } else {
            $attendance->regulation_checkin = null;
        }
        if ($request->has('regulation_checkout') && $request->regulation_checkout !== null) {
            $attendance->regulation_checkout = $request->regulation_checkout;
        } else {
            $attendance->regulation_checkout = null;
        }

        $attendance->save();
        return response()->json([
            'status' => 'success',
            'message' => 'Regulation request submitted successfully.',
            'data' => [
                'id' => $attendance->id,
                'web_user_id' => $attendance->web_user_id,
                'emp_id' => $attendance->emp_id,
                'emp_name' => $attendance->emp_name,
                'checkin' => $attendance->checkin,
                'checkout' => $attendance->checkout,
                'regulation_checkin' => $attendance->regulation_checkin,
                'regulation_checkout' => $attendance->regulation_checkout,
                'reason' => $attendance->reason,
                'regulation_date' => $attendance->regulation_date,
                'regulation_status' => $attendance->regulation_status,
                'status' => $attendance->status,
                'date' => $attendance->date
            ]
        ]);
    }
}
