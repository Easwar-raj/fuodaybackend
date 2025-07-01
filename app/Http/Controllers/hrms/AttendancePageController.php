<?php

namespace App\Http\Controllers\hrms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Attendance;
use App\Models\WebUser;
use App\Models\EmployeeDetails;
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
            $processedRecords = 0;
            $skippedRecords = 0;

            foreach ($attendances as $a) {
                try {
                    // Validate required fields
                    if (!$a->date || !$a->checkin) {
                        $skippedRecords++;
                        continue;
                    }
                    $date = Carbon::parse($a->date);
                    $checkin = Carbon::parse($a->checkin);
                    $checkout = $a->checkout ? Carbon::parse($a->checkout) : null;
                    
                    $monthLabel = $date->format('F');
                    $monthKey = $date->format('Y-m');
                    $weekday = $date->format('l');

                    // Calculate worked hours
                    $workedHours = 0;
                    if ($checkin && $checkout) {
                        $workedHours = $checkout->diffInMinutes($checkin) / 60;
                    }
                    $analytics['total_worked_hours'] += $workedHours;

                    // Collect times for averaging
                    if ($checkin) {
                        $checkinTimes[] = $checkin;
                    }
                    if ($checkout) {
                        $checkoutTimes[] = $checkout;
                    }

                    // Monthly attendance count
                    if (!isset($analytics['monthly_counts'][$monthKey])) {
                        $analytics['monthly_counts'][$monthKey] = 0;
                    }
                    
                    $status = strtolower(trim($a->status));
                    if (in_array($status, ['present', 'late', 'early'])) {
                        $analytics['monthly_counts'][$monthKey]++;
                    }

                    // Count by status
                    match ($status) {
                        'present' => $analytics['total_present']++,
                        'absent' => $analytics['total_absent']++,
                        'late' => $analytics['total_late']++,
                        'early' => $analytics['total_early']++,
                        'permission' => $analytics['total_permission']++,
                        'half day' => $analytics['total_half_day']++,
                        'leave' => $analytics['total_leave']++,
                        'holiday' => $analytics['total_holiday']++,
                        default => null,
                    };

                    // Punctual check
                    if ($checkin && $checkin->format('H:i:s') <= '09:05:00') {
                        $analytics['total_punctual']++;
                    }

                    // Save individual record
                    $analytics['days'][] = [
                        'date' => $date->format('Y-m-d'),
                        'day' => $weekday,
                        'checkin' => $checkin->format('h:i:s A'),
                        'checkout' => $checkout ? $checkout->format('h:i:s A') : null,
                        'status' => $a->status,
                        'worked_hours' => $a->worked_hours ?? '00:00 hours'
                    ];

                    // Initialize monthly graph
                    if (!isset($monthlyGraph[$monthKey])) {
                        $monthlyGraph[$monthKey] = [
                            'month' => $monthLabel,
                            'present' => 0,
                            'absent' => 0,
                            'permission' => 0,
                            'leave' => 0
                        ];
                    }

                    // Update monthly graph
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

            // Calculate attendance percentage
            $totalMonths = count($analytics['monthly_counts']);
            if ($totalMonths > 0) {
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

        } catch (\Exception $e) {
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
        $checkout = Carbon::now();

        $diffInSeconds = $checkin->diffInSeconds($checkout);
        $hours = floor($diffInSeconds / 3600);
        $minutes = floor(($diffInSeconds % 3600) / 60);

        $workedHours = sprintf('%02d:%02d hours', $hours, $minutes);

        $attendance->checkout = $checkout->toTimeString();
        $attendance->worked_hours = $workedHours;
        if ($request->has('timeout') && $request->timeout) {
            $attendance->status = 'Timeout';
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
            ->select(['id', 'date', 'checkin', 'status', 'emp_name'])
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
                    ->where('role', 'employee')
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
                        ->select(['date', 'checkin', 'emp_name'])
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
        $employees = WebUser::where('admin_user_id', $webUser->admin_user_id)->where('role', 'employee')->get();
        $summaryStats['total_employees'] = $employees->count();

        foreach ($employees as $employee) {
            $attendances = DB::table('attendances')
                ->where('web_user_id', $employee->id)
                ->whereNotNull('checkin')
                ->select(['id', 'date', 'checkin', 'status'])
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
                    'employee_name' => $employeeName, // 👈 THIS LINE ENSURES NAME IS INCLUDED
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
        ->where('role', 'employee')
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
        Log::info('calculateEarlyArrivals method called', ['user_id' => $id]);

        // Check if user exists
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
            ->select(['id', 'date', 'checkin', 'status', 'emp_name'])
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
        $employeeName = $attendances->first()->emp_name ?? $webUser->name;

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

    } catch (\Exception $e) {
        Log::error('Error in calculateEarlyArrivals', [
            'user_id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

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
        $standardStartTime = '09:00:00';

        $allEarlyData = [];
        $summaryStats = [
            'total_employees' => 0,
            'employees_with_early_arrivals' => 0,
            'total_early_instances' => 0,
            'total_early_minutes' => 0
        ];

        $employees = WebUser::where('role', 'employee')->get();
        $summaryStats['total_employees'] = $employees->count();

        foreach ($employees as $employee) {
            $attendances = DB::table('attendances')
                ->where('web_user_id', $employee->id)
                ->whereNotNull('checkin')
                ->select(['id', 'date', 'checkin', 'status'])
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

    } catch (\Exception $e) {
        Log::error('Error in getAllEarlyArrivals', [
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

public function calculatePunctualArrivals($id)
{
    try {
        Log::info('calculatePunctualArrivals method called', ['user_id' => $id]);

        // Check if user exists
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
            ->select(['id', 'date', 'checkin', 'status', 'emp_name'])
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

    } catch (\Exception $e) {
        Log::error('Error in calculatePunctualArrivals', [
            'user_id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

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
        $standardStartTime = '09:00:00';

        $allPunctualData = [];
        $summaryStats = [
            'total_employees' => 0,
            'employees_with_punctual_arrivals' => 0,
            'total_punctual_instances' => 0,
            'overall_punctuality_rate' => 0
        ];

        $employees = WebUser::where('role', 'employee')->get();
        $summaryStats['total_employees'] = $employees->count();
        $totalAttendanceRecords = 0;

        foreach ($employees as $employee) {
            $attendances = DB::table('attendances')
                ->where('web_user_id', $employee->id)
                ->whereNotNull('checkin')
                ->select(['id', 'date', 'checkin', 'status'])
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

    } catch (\Exception $e) {
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
        Log::info('calculateAbsentDays method called', ['user_id' => $id]);

        // Check if user exists
        $webUser = WebUser::find($id);
        if (!$webUser) {
            return response()->json([
                'message' => 'User not found',
                'status' => 'error'
            ], 404);
        }

        // Fetch all attendance records for the user
        $attendances = DB::table('attendances')
            ->where('web_user_id', $id)
            ->select(['id', 'date', 'checkin', 'status', 'emp_name'])
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
        $employeeName = $attendances->first()->emp_name ?? $webUser->name ?? '-';

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

    } catch (\Exception $e) {
        Log::error('Error in calculateAbsentDays', [
            'user_id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'message' => 'Error calculating absent days',
            'status' => 'error',
            'error' => $e->getMessage()
        ], 500);
    }
}



}