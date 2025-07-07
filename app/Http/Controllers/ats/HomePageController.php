<?php

namespace App\Http\Controllers\ats;

use App\Http\Controllers\Controller;
use App\Models\CallLogs;
use Illuminate\Http\Request;
use App\Models\JobOpening;
use App\Models\EmployeeDetails;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class HomePageController extends Controller
{
    public function getDashboardDetails($id)
    {
        $webUser = WebUser::findOrFail($id);
        $adminUserId = $webUser->admin_user_id;

        if (!$webUser || !$adminUserId) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }

        $openings = JobOpening::where('admin_user_id', $adminUserId)->get();

        $statusCounts = ['closed', 'pending', 'open', 'interviewing', 'reviewing'];

        $positionCounts = collect($statusCounts)->mapWithKeys(function ($status) use ($openings) {
            return [$status . '_positions' => $openings->where('status', $status)->sum(fn($o) => (int)$o->no_of_openings)];
        });

        $totalOpenings = $openings->sum(fn($o) => (int)$o->no_of_openings);

        // Goal tracking
        $goalTargets = ['daily' => 10, 'weekly' => 70, 'monthly' => 300];

        $goalAchieved = [
            'daily' => JobOpening::whereRaw('LOWER(status) = ?', ['closed'])
                ->whereDate('created_at', today())
                ->sum(DB::raw('CAST(no_of_openings AS UNSIGNED)')),

            'weekly' => JobOpening::whereRaw('LOWER(status) = ?', ['closed'])
                ->whereBetween('created_at', [now()->startOfWeek(), now()])
                ->sum(DB::raw('CAST(no_of_openings AS UNSIGNED)')),

            'monthly' => JobOpening::whereRaw('LOWER(status) = ?', ['closed'])
                ->whereBetween('created_at', [now()->startOfMonth(), now()])
                ->sum(DB::raw('CAST(no_of_openings AS UNSIGNED)')),
        ];

        $goalData = collect($goalTargets)->mapWithKeys(function ($target, $period) use ($goalAchieved) {
            $achieved = $goalAchieved[$period];
            return [$period . '_goal' => [
                'target' => $target,
                'achieved' => $achieved,
                'percentage' => $target > 0 ? round(($achieved / $target) * 100) : 0,
            ]];
        });

        // Employees
        $employeeDetails = EmployeeDetails::select('emp_name', 'emp_id', 'designation as role', 'profile_photo')->where('web_user_id', $webUser->id)->get();

        return response()->json([
            'status' => 'Success',
            'message' => 'ATS Dashboard details fetched successfully',
            'data' => array_merge([
                'total_requirement' => $totalOpenings,
                'employees' => $employeeDetails,
            ], $positionCounts->toArray()),
            'goals' => $goalData,
            'graph' => [
                'total' => $totalOpenings,
                'closed' => $positionCounts['closed_positions'],
                'pending' => $positionCounts['pending_positions'],
            ]
        ]);
    }

    public function getCallStats($id)
    {

        $webUser = WebUser::findOrFail($id);
        $adminUserId = $webUser->admin_user_id;

        if (!$webUser || !$adminUserId) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        $query = CallLogs::where('web_user_id', $id);

        // 1. Total calls made today
        $callsToday = $query->whereDate('created_at', $today)->get();

        // 2. Total calls made yesterday
        $callsYesterday = $query->whereDate('created_at', $yesterday)->get();

        // 3. Follow-up calls today (by 'date' column and 'status')
        $followUpCallsToday = $query->whereDate('date', $today)->where('status', 'follow-up')->get();

        // 4. Non-responsive calls today (by 'created_at' and status)
        $nonResponsiveCallsToday = $query->whereDate('created_at', $today)->where('status', 'no-response')->get();

        // Return response
        return response()->json([
            'status' => 'Success',
            'message' => 'Call logs fetched successfully',
            'data' => [
                'total_calls_today' => [
                    'count' => $callsToday->count(),
                    'list' => $callsToday
                ],
                'total_calls_yesterday' => [
                    'count' => $callsYesterday->count(),
                    'list' => $callsYesterday
                ],
                'follow_up_calls_today' => [
                    'count' => $followUpCallsToday->count(),
                    'list' => $followUpCallsToday
                ],
                'non_responsive_calls_today' => [
                    'count' => $nonResponsiveCallsToday->count(),
                    'list' => $nonResponsiveCallsToday
                ],
            ]
        ]);
    }
}
