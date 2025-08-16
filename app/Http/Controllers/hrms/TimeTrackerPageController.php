<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use Carbon\Carbon;
use App\Models\WebUser;
use App\Models\Policies;
use App\Models\Attendance;
use App\Models\Projects;
use App\Models\ProjectTeam;
use App\Models\Task;
use Carbon\CarbonInterval;
use Exception;
use Illuminate\Support\Facades\DB;
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
        $startOfWeek = Carbon::now()->startOfWeek()->toDateString();
        $endOfWeek = Carbon::now()->endOfWeek()->toDateString();
        $attendances = Attendance::where('web_user_id', $id)
            ->whereBetween('date', [$startOfWeek, $endOfWeek])
            ->orderBy('date')
            ->orderBy('checkin')
            ->get()
            ->groupBy(function ($attendance) {
                return Carbon::parse($attendance->date)->toDateString(); // group by date
            })
            ->map(function ($dayAttendances, $date) {
                $first = $dayAttendances->first();
                $last = $dayAttendances->last();

                $totalSeconds = $dayAttendances->reduce(function ($carry, $record) {
                    if ($record->checkin && $record->checkout) {
                        $checkin = Carbon::parse($record->checkin);
                        $checkout = Carbon::parse($record->checkout);
                        return $carry + $checkout->diffInSeconds($checkin);
                    }
                    return $carry;
                }, 0);

                // Format total time as H:i:s
                $totalDuration = CarbonInterval::seconds($totalSeconds)->cascade()->format('%H:%I:%S');
                return (object)[
                    'date' => Carbon::parse($date)->setTimezone('Asia/Kolkata')->format('l, F d, Y'),
                    'first_login' => $last->checkin,
                    'last_logout' => $first->checkout,
                    'total_hours' => $totalDuration,
                ];
            })
            ->values(); // reset keys
        // Step 4: Get project IDs from project_teams
        $projectIds = ProjectTeam::where('web_user_id', $id)->pluck('project_id')->unique();

        // Step 5: Get projects with admin_user_id match
        $projects = Projects::with('projectTeam')
            ->where('admin_user_id', $adminUserId)
            ->get()
            ->map(function ($project) {
                $projectArray = $project->toArray();
                $projectArray['formatted_deadline'] = Carbon::parse($project->deadline)->format('F j, Y');
                return $projectArray;
            });

        $tasks = Task::where('assigned_to_id', $id)
            ->whereIn('project_id', $projectIds)
            ->get()
            ->groupBy('project_id');

        return response()->json([
            'message' => 'Time Tracker Data Retrieved Successfully',
            'status' => 'Success',
            'data' => [
                'policies' => $policy,
                'attendances_this_week' => $attendances,
                'projects' => $projects,
                'tasks' => $tasks,
            ],
        ], 200);
    }

    public function getSchedulesForWebUser($id)
    {
        try {
            $webUser = WebUser::find($id);
            if (!$webUser || !$webUser->admin_user_id) {
                return response()->json([
                    'status' => 'Error',
                    'message' => 'Web user not found or admin user not linked.'
                ], 404);
            }

            $schedules = DB::table('schedules')
                ->select(
                    'team_name',
                    'date',
                    'shift_status',
                    'shift_start',
                    'shift_end',
                    'start_date',
                    'end_date',
                    'saturday_type',
                    'saturday_dates',
                    DB::raw('GROUP_CONCAT(id) as schedule_ids'),
                    DB::raw('GROUP_CONCAT(web_user_id) as web_user_ids'),
                    DB::raw('GROUP_CONCAT(emp_name) as emp_names'),
                    DB::raw('GROUP_CONCAT(emp_id) as emp_ids'),
                    DB::raw('GROUP_CONCAT(department) as departments')
                )
                ->where('web_user_id', $id)
                ->groupBy(
                    'team_name',
                    'date',
                    'shift_status',
                    'shift_start',
                    'shift_end',
                    'start_date',
                    'end_date',
                    'saturday_type',
                    'saturday_dates'
                )
                ->get();

            $formattedSchedules = $schedules->map(function ($schedule) {
                $webUserIds = explode(',', $schedule->web_user_ids);
                $empNames = explode(',', $schedule->emp_names);
                $empIds = explode(',', $schedule->emp_ids);
                $scheduleIds = explode(',', $schedule->schedule_ids);
                $departments = explode(',', $schedule->departments);

                $employees = [];
                for ($i = 0; $i < count($webUserIds); $i++) {
                    $employees[] = [
                        'id' => (int) $webUserIds[$i],
                        'name' => $empNames[$i] ?? '',
                        'emp_id' => $empIds[$i] ?? '',
                        'schedule_id' => (int) $scheduleIds[$i]
                    ];
                }

                // Decode saturday dates
                $saturdayDates = null;
                if (!empty($schedule->saturday_dates)) {
                    $saturdayDates = json_decode($schedule->saturday_dates, true);
                }

                return [
                    'team_name' => $schedule->team_name,
                    'department' => $departments[0] ?? '',
                    'date' => $schedule->date,
                    'shift_status' => $schedule->shift_status,
                    'shift_start' => $schedule->shift_start,
                    'shift_end' => $schedule->shift_end,
                    'start_date' => $schedule->start_date,
                    'end_date' => $schedule->end_date,
                    'saturday_type' => $schedule->saturday_type,
                    'saturday_dates' => $saturdayDates,
                    'schedule_ids' => array_map('intval', explode(',', $schedule->schedule_ids)),
                    'employees' => $employees,
                ];
            });
            return response()->json([
                'status' => 'Success',
                'message' => 'Schedules fetched successfully.',
                'data' => $formattedSchedules
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'Error',
                'message' => 'Failed to fetch schedules.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
