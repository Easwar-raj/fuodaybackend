<?php

namespace App\Http\Controllers\ats;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\JobOpening;
use App\Models\EmployeeDetails;
use App\Models\WebUser;
use Illuminate\Support\Facades\DB;

class HomePageController extends Controller
{
    public function getDashboardDetails($id)
    {
        $webUser = WebUser::findOrFail($id);
        $adminUserId = $webUser->admin_user_id;

        if (!$webUser) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }

        $query = JobOpening::query();

        if ($adminUserId) {
            $query->where('admin_user_id', $adminUserId);
        }

        $openings = $query->get();
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
        $employeeQuery = EmployeeDetails::select('emp_name', 'emp_id', 'designation as role', 'profile_photo');

        if ($webUser->filled('emp_id')) {
            $employeeQuery->where('emp_id', $webUser->emp_id);
        }

        if ($webUser->filled('web_user_id')) {
            $employeeQuery->where('web_user_id', $webUser->id);
        }

        $employees = $employeeQuery->get();

        return response()->json([
            'status' => true,
            'message' => 'ATS Dashboard details fetched successfully',
            'data' => array_merge([
                'total_requirement' => $totalOpenings,
                'employees' => $employees,
            ], $positionCounts->toArray()),
            'goals' => $goalData,
            'graph' => [
                'total' => $totalOpenings,
                'closed' => $positionCounts['closed_positions'],
                'pending' => $positionCounts['pending_positions'],
            ]
        ]);
    }
}
