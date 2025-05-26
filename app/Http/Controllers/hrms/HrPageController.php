<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\JobOpening;
use App\Models\LeaveRequest;
use App\Models\Projects;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class HrPageController extends Controller
{
    public function getHr($id)
    {
        // Step 1: Get the admin_user_id from the given web_user_id
        $webUser = WebUser::findOrFail($id);
        $adminUserId = $webUser->admin_user_id;

        // Step 2: Proceed to query everything by that admin_user_id

        // Total Employees
        $totalEmployees = WebUser::where('admin_user_id', $adminUserId)->count();
        $lastWeekCount = WebUser::where('admin_user_id', $adminUserId)
            ->whereBetween('created_at', [now()->startOfWeek()->subWeek(), now()->endOfWeek()->subWeek()])
            ->count();
        $thisWeekCount = WebUser::where('admin_user_id', $adminUserId)
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();
        $employeeChange = $lastWeekCount > 0 ? (($thisWeekCount - $lastWeekCount) / $lastWeekCount) * 100 : 100;

        $webUser = WebUser::findOrFail($id);
        $adminUserId = $webUser->admin_user_id;
        $webUserIds = WebUser::where('admin_user_id', $adminUserId)->pluck('id');

        // Total Leave Requests
        $totalLeaveRequests = LeaveRequest::whereIn('web_user_id', $webUserIds)->count();
        $lastWeekLeaves = LeaveRequest::whereIn('web_user_id', $webUserIds)
            ->whereBetween('created_at', [now()->startOfWeek()->subWeek(), now()->endOfWeek()->subWeek()])
            ->count();
        $thisWeekLeaves = LeaveRequest::whereIn('web_user_id', $webUserIds)
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();
        $leaveChange = $lastWeekLeaves > 0 ? (($thisWeekLeaves - $lastWeekLeaves) / $lastWeekLeaves) * 100 : 100;

        // Attendance (Permissions)
        $totalPermissions = Attendance::whereIn('web_user_id', $webUserIds)->count();
        $lastWeekPermissions = Attendance::whereIn('web_user_id', $webUserIds)
            ->whereBetween('date', [now()->startOfWeek()->subWeek(), now()->endOfWeek()->subWeek()])
            ->count();
        $thisWeekPermissions = Attendance::whereIn('web_user_id', $webUserIds)
            ->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();
        $permissionChange = $lastWeekPermissions > 0 ? (($thisWeekPermissions - $lastWeekPermissions) / $lastWeekPermissions) * 100 : 100;

        // Attendance Today
        $attendanceToday = Attendance::whereIn('web_user_id', $webUserIds)
            ->whereDate('date', today())
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $early = $attendanceToday['Early'] ?? 0;
        $regular = $attendanceToday['Regular'] ?? 0;
        $late = $attendanceToday['Late'] ?? 0;
        $totalAttendance = $early + $regular + $late;

        // Employee Growth Chart by Role and Year
        $employeeChart = WebUser::where('admin_user_id', $adminUserId)
            ->selectRaw('YEAR(created_at) as year, role, COUNT(*) as total')
            ->groupBy('year', 'role')
            ->orderBy('year')
            ->get()
            ->groupBy('year');

        // Projects + Team Members
        $projects = Projects::where('admin_user_id', $adminUserId)
            ->with(['projectTeam' => function ($query) use ($adminUserId) {
                $query->whereHas('webUser', function ($q) use ($adminUserId) {
                    $q->where('admin_user_id', $adminUserId);
                });
            }, 'projectTeam.webUser'])
            ->orderBy('deadline')
            ->take(4)
            ->get()
            ->map(function ($project) {
                return [
                    'name' => $project->name,
                    'domain' => $project->domain,
                    'deadline' => $project->deadline->format('Y-m-d'),
                    'team_members' => $project->projectTeam->map(function ($team) {
                        return [
                            'name' => $team->webUser->name ?? null,
                            'role' => $team->webUser->role ?? null,
                        ];
                    }),
                ];
            });

        // Recent Employees
        $recentEmployees = WebUser::where('admin_user_id', $adminUserId)
            ->with(['employeeDetails:id,web_user_id,profile_photo,date_of_joining'])
            ->get(['id', 'name', 'role', 'emp_id'])
            ->sortByDesc(fn ($user) => $user->employeeDetails->date_of_joining)
            ->take(4)
            ->values()
            ->map(function ($user) {
                if ($user->employeeDetails && $user->employeeDetails->date_of_joining) {
                    $user->employeeDetails->date_of_joining = Carbon::parse($user->employeeDetails->date_of_joining)->format('Y-m-d');
                }
                return $user;
            });

        // Open Positions
        $openPositions = JobOpening::where('admin_user_id', $adminUserId)
            ->where('status', 'Open')
            ->take(3)
            ->get(['title', 'posted_at', 'no_of_openings']);

        return response()->json([
            'message' => 'HR Dashboard Data Retrieved Successfully',
            'status' => 'Success',
            'data' => [
                'stats' => [
                    'totalEmployees' => $totalEmployees,
                    'employeeChange' => round($employeeChange, 2),
                    'totalLeaveRequests' => $totalLeaveRequests,
                    'leaveChange' => round($leaveChange, 2),
                    'totalPermissions' => $totalPermissions,
                    'permissionChange' => round($permissionChange, 2),
                ],
                'attendanceToday' => [
                    'early' => $early,
                    'regular' => $regular,
                    'late' => $late,
                    'total' => $totalAttendance,
                ],
                'employeeChart' => $employeeChart,
                'projects' => $projects,
                'recentEmployees' => $recentEmployees,
                'openPositions' => $openPositions,
            ],
        ], 200);
    }

    public function getPendingLeaveRequests($id)
    {
        // Step 1: Get the admin_user_id of this web user
        $webUser = WebUser::where('id', $id);

        if (!$webUser->admin_user_id || $webUser->role !== 'hr') {
            return response()->json(['message' => 'Invalid details'], 403);
        }

        // Step 2: Get all web user IDs under this admin
        $webUserIds = WebUser::where('admin_user_id', $webUser->admin_user_id)->pluck('id');

        // Step 3: Get pending leave requests for these web users
        $pendingLeaves = LeaveRequest::whereIn('web_user_id', $webUserIds)
            ->where('status', 'pending')
            ->get();

        return response()->json([
            'status' => 'Success',
            'message' => 'Pending leaves retrieved successfully',
            'data' =>  $pendingLeaves
        ]);
    }

    function getWebUsers($id)
    {
        // Step 1: Find the admin_user_id for the given web_user_id
        $webUser = WebUser::findOrFail($id);

        if (!$webUser->admin_user_id || $webUser->role !== 'hr') {
            return response()->json(['message' => 'Invalid details'], 403);
        }
        // Step 2: Fetch all web_users having the same admin_user_id
        $webUsers = WebUser::where('admin_user_id', $webUser->admin_user_id)->get();

        return response()->json([
            'status' => 'Success',
            'message' => 'Web users retrieved successfully',
            'data' => $webUsers
        ], 200);
    }
}
