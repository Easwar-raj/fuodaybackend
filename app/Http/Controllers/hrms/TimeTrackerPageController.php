<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\WebUser;
use App\Models\Policies;
use App\Models\Attendance;
use App\Models\Projects;
use App\Models\ProjectTeam;
use App\Models\Task;
use App\Models\Schedule;

class TimeTrackerPageController extends Controller
{
    public function getTimeTracker($id)
    {
        // Step 1: Get WebUser and AdminUser ID
        $webUser = WebUser::find($id);
        if (!$webUser || !$webUser->admin_user_id) {
            return response()->json(['message' => 'Web user not found or admin user not linked.'], 404);
        }
        $adminUserId = $webUser->admin_user_id;

        // Step 2: Get relevant policy (assuming title is 'Working Hours')
        $policy = Policies::where('admin_user_id', $adminUserId)
            ->whereIn('title', ['Weekly Working Hours', 'Work Time Per Day', 'Break Time'])
            ->get(['id', 'title', 'policy', 'description']);

        // Step 3: Get this week's attendances
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        $attendances = Attendance::where('web_user_id', $id)
            ->whereBetween('date', [$startOfWeek, $endOfWeek])
            ->get();

        // Step 4: Get project IDs from project_teams
        $projectIds = ProjectTeam::where('web_user_id', $id)->pluck('project_id')->unique();

        // Step 5: Get projects with admin_user_id match
        $projects = Projects::with('projectTeam')
            ->where('admin_user_id', $adminUserId)
            ->get()
            ->map(function ($project) {
                $project->formatted_deadline = \Carbon\Carbon::parse($project->deadline)->format('F j, Y');
                return $project;
            });

        $tasks = Task::where('assigned_to_id', $id)
            ->whereIn('project_id', $projectIds)
            ->get()
            ->groupBy('project_id');

        return response()->json([
            'policies' => $policy,
            'attendances_this_week' => $attendances,
            'projects' => $projects,
            'tasks' => $tasks,
        ]);
    }

    public function addShiftSchedule(Request $request)
    {
        $validated = $request->validate([
            'web_user_id' => 'required|exists:web_users,id',
            'date' => 'required|date',
            'shift_start' => 'required|string',
            'shift_end' => 'required|string',
        ]);

        $webUser = WebUser::find($request->web_user_id);

        if (!$validated || !$webUser) {
            return response()->json([
                'message' => 'Invalid data or user not found'
            ], 400);
        }

        $schedule = Schedule::create([
            'web_user_id' => $webUser->id,
            'emp_name' => $webUser->name,
            'emp_id' => $webUser->emp_id,
            'date' => $request->date,
            'shift_start' => $request->shift_start,
            'shift_end' => $request->shift_end,
        ]);

        return response()->json([
            'message' => 'Shift schedule added successfully.'
        ], 201);
    }

    public function getMonthlyShifts(Request $request)
    {
        $validated = $request->validate([
            'web_user_id' => 'required|exists:web_users,id',
            'month' => 'nullable|date_format:Y-m' // e.g., "2025-04"
        ]);

        if (!$validated) {
            return response()->json([
                'message' => 'Invalid data or user not found'
            ], 400);
        }

        $webUserId = $request->web_user_id;
        $month = $request->month ?? Carbon::now()->format('Y-m');

        $startDate = Carbon::parse($month . '-01')->startOfMonth();
        $endDate = Carbon::parse($month . '-01')->endOfMonth();

        $shifts = Schedule::where('web_user_id', $webUserId)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get();

        return response()->json([
            'month' => $month,
            'shifts' => $shifts
        ]);
    }

}
