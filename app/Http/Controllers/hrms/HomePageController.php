<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use App\Models\About;
use App\Models\Achievement;
use App\Models\Attendance;
use App\Models\Client;
use App\Models\EmployeeDetails;
use App\Models\Event;
use App\Models\Expenses;
use App\Models\Goals;
use App\Models\Holidays;
use App\Models\Industries;
use App\Models\LeaveRequest;
use App\Models\ProjectApproval;
use App\Models\Projects;
use App\Models\ProjectTeam;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\TotalLeaves;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class HomePageController extends Controller
{
    public function getActivities($id)
    {
        $today = Carbon::today()->toDateString();

        $selectColumns = [
            'attendances.status',
            'attendances.location',
            'schedules.shift_start',
            'schedules.shift_end',
            'schedules.date'
        ];

        // LEFT JOIN: schedules → attendances
        $left = DB::table('schedules')
            ->where('schedules.web_user_id', $id)
            ->whereDate('schedules.date', $today)
            ->leftJoin('attendances', function ($join) {
                $join->on('schedules.web_user_id', '=', 'attendances.web_user_id')
                    ->on('schedules.date', '=', 'attendances.date');
            })
            ->select($selectColumns);

        // RIGHT JOIN: attendances → schedules
        $right = DB::table('attendances')
            ->where('attendances.web_user_id', $id)
            ->whereDate('attendances.date', $today)
            ->leftJoin('schedules', function ($join) {
                $join->on('attendances.web_user_id', '=', 'schedules.web_user_id')
                    ->on('attendances.date', '=', 'schedules.date');
            })
            ->select($selectColumns);

        // Union both
        $data = $left->union($right)->get();

        return response()->json([
            'message' => 'Activities data retrieved successfully',
            'status' => 'Success',
            'data' => $data
        ]);
    }

    public function getFeeds($id)
    {
        $today = Carbon::today()->toDateString();

        $selectColumns = [
            'tasks.date',
            'tasks.description',
            'tasks.assigned_by',
            'tasks.assigned_to',
            'tasks.project',
            'projects.name',
            'projects.progress',
            'projects.deadline',
            DB::raw('NULL as schedule_event')
        ];

        // Query from tasks base (if tasks exist)
        $fromTasks = DB::table('tasks')
            ->leftJoin('project_teams as pt_to', function ($join) {
                $join->on('pt_to.web_user_id', '=', 'tasks.assigned_to_id');
            })
            ->leftJoin('project_teams as pt_by', function ($join) {
                $join->on('pt_by.web_user_id', '=', 'tasks.assigned_by_id');
            })
            ->leftJoin('projects', function ($join) {
                $join->on('projects.id', '=', 'pt_to.project_id')
                    ->orOn('projects.id', '=', 'pt_by.project_id');
            })
            ->where(function ($query) use ($id) {
                $query->where('tasks.assigned_to_id', $id)
                    ->orWhere('tasks.assigned_by_id', $id);
            })
            ->whereDate('tasks.date', $today)
            ->select($selectColumns);

        // Query from projects base (in case tasks don't exist)
        $fromProjects = DB::table('project_teams')
            ->join('projects', 'projects.id', '=', 'project_teams.project_id')
            ->leftJoin('tasks', function ($join) use ($id, $today) {
                $join->on(function ($sub) {
                        $sub->on('tasks.assigned_to_id', '=', 'project_teams.web_user_id')
                            ->orOn('tasks.assigned_by_id', '=', 'project_teams.web_user_id');
                    })
                    ->whereDate('tasks.date', $today);
            })
            ->where('project_teams.web_user_id', $id)
            ->select($selectColumns);

        $fromSchedules = DB::table('schedules')
            ->where('schedules.web_user_id', $id)
            ->whereNotNull('schedules.event')
            ->whereBetween('schedules.date', [
                Carbon::now()->startOfMonth()->toDateString(),
                Carbon::now()->endOfMonth()->toDateString()
            ])
            ->select([
                'schedules.date',
                DB::raw('NULL as description'),
                DB::raw('NULL as assigned_by'),
                DB::raw('NULL as assigned_to'),
                DB::raw('NULL as project'),
                DB::raw('NULL as name'),
                DB::raw('NULL as progress'),
                DB::raw('NULL as deadline'),
                'schedules.event as schedule_event'
            ]);

        // Combine both using unionAll
        $data = $fromTasks->unionAll($fromProjects)->unionAll($fromSchedules)->get();

        return response()->json([
            'message' => 'Feeds data retrieved successfully',
            'status' => 'Success',
            'data' => $data
        ]);
    }

    public function getEmployeeData($id)
    {
        $employee = DB::table('employee_details')
            ->join('web_users', 'web_users.id', '=', 'employee_details.web_user_id')
            ->where('employee_details.web_user_id', $id)
            ->select([
                'employee_details.place',
                'employee_details.designation',
                'employee_details.department',
                'employee_details.employment_status',
                'employee_details.reporting_manager_id',
                'employee_details.about',
                'web_users.name',
                'web_users.email',
                'web_users.phone',
                'web_users.emp_id',
                DB::raw("(
                    SELECT JSON_ARRAYAGG(
                        JSON_OBJECT('company_name', company_name, 'role', role, 'duration', duration)
                    )
                    FROM experiences
                    WHERE experiences.web_user_id = employee_details.web_user_id
                ) AS experiences"),
                DB::raw("(
                    SELECT JSON_ARRAYAGG(
                        JSON_OBJECT('goal', goal, 'status', status)
                    )
                    FROM goals
                    WHERE goals.web_user_id = employee_details.web_user_id
                ) AS goals")
            ])
            ->first();

        if (!$employee) {
            return response()->json([
                'message' => 'No data found for the given employee',
                'status' => 'Failure',
                'data' => null
            ]);
            // Decode JSON columns to arrays

        }

        $employee->experiences = json_decode($employee->experiences, true) ?? [];
        $employee->goals = json_decode($employee->goals, true) ?? [];

        return response()->json([
            'message' => 'Employee data retrieved successfully',
            'status' => 'Success',
            'data' => $employee
        ]);
    }

    public function getApprovals($id)
    {
        // 1. Leave Requests
        $leaveRequests = LeaveRequest::where('web_user_id', $id)
            ->get(['type', 'from', 'to', 'reason', 'status', 'comment']);

        // 2. Project Approvals
        $approvals = ProjectApproval::with('project.projectTeam.webUser')
            ->where('web_user_id', $id)
            ->get();

        // 3. Expenses
        $expenses = Expenses::where('web_user_id', $id)
            ->get(['type', 'reason', 'amount', 'status', 'comment']);

        return response()->json([
            'leave_requests' => $leaveRequests,
            'project_approvals' => $approvals,
            'expenses' => $expenses,
        ]);
    }

    public function getLeaveSummary($id)
    {
        $webUser = WebUser::findOrFail($id);
        $adminUserId = $webUser->admin_user_id;

        // 1. Total leaves by type for the admin
        $totalLeaves = TotalLeaves::where('admin_user_id', $adminUserId)
            ->select('type', 'total')
            ->get();

        // 2. Total utilized leaves by the user (regardless of status)
        $utilizedLeaves = LeaveRequest::where('web_user_id', $id)->count();

        // 3. Approved, Pending, Rejected counts
        $statusCounts = LeaveRequest::where('web_user_id', $id)
            ->selectRaw("
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as total_approved,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as total_pending,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as total_rejected
            ")
            ->first();

        return response()->json([
            'total_leaves_by_type' => $totalLeaves,
            'total_utilized_leaves' => $utilizedLeaves,
            'status_summary' => [
                'approved' => $statusCounts->total_approved,
                'pending' => $statusCounts->total_pending,
                'rejected' => $statusCounts->total_rejected,
            ],
        ]);
    }

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
            ->get();

        if ($attendances->isEmpty()) {
            return response()->json([
                'message' => 'No attendance data found for the given employee',
                'status' => 'error',
                'data' => []
            ], 404);
        }

        $analytics = [
            'days' => [],
            'average_checkin_time' => null,
            'average_checkout_time' => null,
            'total_attandance' => $attendances->count(),
        ];

        $checkinTimes = [];
        $checkoutTimes = [];

        foreach ($attendances as $a) {
            $date = Carbon::parse($a->date);
            $checkin = Carbon::parse($a->checkin);
            $checkout = Carbon::parse($a->checkout);

            // Collect for average time
            $checkinTimes[] = $checkin;
            $checkoutTimes[] = $checkout;

            // Save individual record with details
            $analytics['days'][] = [
                'date' => $date->format('Y-m-d'),
                'checkin' => $checkin->format('h:i:s A'),
                'checkout' => $checkout->format('h:i:s A'),
                'status' => $a->status,
            ];
        }

        // Average checkin/checkout
        if (count($checkinTimes)) {
            $avgCheckin = Carbon::createFromTimestamp(
                array_sum(array_map(fn ($c) => $c->timestamp, $checkinTimes)) / count($checkinTimes)
            );
            $analytics['average_checkin_time'] = $avgCheckin->format('h:i:s A');
        }

        if (count($checkoutTimes)) {
            $avgCheckout = Carbon::createFromTimestamp(
                array_sum(array_map(fn ($c) => $c->timestamp, $checkoutTimes)) / count($checkoutTimes)
            );
            $analytics['average_checkout_time'] = $avgCheckout->format('h:i:s A');
        }

        return response()->json([
            'message' => 'Attendance data retrieved successfully',
            'status' => 'Success',
            'data' => $analytics
        ], 200);
    }

    public function getProjectReportees($id)
    {
        // Get the admin_user_id for the given web_user_id
        $adminUserId = WebUser::where('id', $id)->value('admin_user_id');

        // Get all project IDs the user is working on
        $projectIds = ProjectTeam::where('web_user_id', $id)
            ->pluck('project_id');

        // Get all team members under same admin_user_id in those projects, excluding the current user
        $teamMembers = ProjectTeam::with('webUser')
            ->whereIn('project_id', $projectIds)
            ->whereHas('webUser', function ($query) use ($adminUserId) {
                $query->where('admin_user_id', $adminUserId);
            })
            ->where('web_user_id', '!=', $id)
            ->get()
            ->map(function ($team) {
                return [
                    'web_user_id' => $team->web_user_id,
                    'name' => $team->webUser->name ?? null,
                    'role' => $team->webUser->role ?? null,
                    'email' => $team->webUser->email ?? null
                ];
            })
            ->unique('web_user_id')
            ->values();

        // Role hierarchy
        $hierarchy = [
            'Manager' => 1,
            'Team Lead' => 2,
            'Senior Developer' => 3,
            'Developer' => 4,
            'Junior Developer' => 5,
            'Intern' => 6,
        ];

        // Sort based on hierarchy
        $sortedTeam = $teamMembers->sortBy(function ($member) use ($hierarchy) {
            return $hierarchy[$member['role']] ?? 999;
        })->values();

        return response()->json([
            'my_team_members' => $sortedTeam
        ]);
    }

    public function getHandledProjects($id)
    {
        $adminUserId = WebUser::where('id', $id)->value('admin_user_id');
        // Get all project IDs where this web_user is part of the team
        $projectIds = ProjectTeam::where('web_user_id', $id)
            ->pluck('project_id');

        // Get projects based on those IDs
        $projects = Projects::with('projectTeam.webUser')
            ->where('admin_user_id', $adminUserId)
            ->whereIn('id', $projectIds)
            ->get()
            ->map(function ($project) {
                return [
                    'name' => $project->name,
                    'domain' => $project->domain,
                    'deadline' => $project->deadline,
                    'team_members' => $project->projectTeam->map(function ($team) {
                        return [
                            'name' => $team->webUser->name ?? null,
                            'role' => $team->webUser->role ?? null,
                        ];
                    }),
                ];
            });

        return response()->json([
            'handled_projects' => $projects
        ]);
    }

    public function getEmployeesGroupedByDepartment($id)
    {
        // Step 1: Find admin_user_id
        $webUser = WebUser::findOrFail($id);
        $adminUserId = $webUser->admin_user_id;

        // Step 2: Join web_users with employee_details and filter by admin_user_id
        $employees = WebUser::where('admin_user_id', $adminUserId)
            ->join('employee_details', 'web_users.id', '=', 'employee_details.web_user_id')
            ->select(
                'web_users.id as web_user_id',
                'web_users.name',
                'web_users.emp_id',
                'web_users.email',
                'web_users.role',
                'employee_details.department',
                'employee_details.designation'
            )
            ->get();

        // Step 3: Group by department
        $groupedByDepartment = $employees->groupBy('department');

        return response()->json([
            'departments' => $groupedByDepartment,
        ]);
    }

    public function getTeamByDepartment($id)
    {
        // Step 1: Get current user with department
        $employeeDetail = EmployeeDetails::where('web_user_id', $id)->firstOrFail();
        $department = $employeeDetail->department;

        // Step 2: Get all web_users in the same department and same admin_user
        $user = WebUser::findOrFail($id);
        $adminUserId = $user->admin_user_id;

        // Step 3: Join web_users + employee_details
        $teamMembers = WebUser::where('web_users.admin_user_id', $adminUserId)
            ->join('employee_details', 'web_users.id', '=', 'employee_details.web_user_id')
            ->where('employee_details.department', $department)
            ->select(
                'web_users.id as web_user_id',
                'web_users.emp_id',
                'web_users.name',
                'web_users.email',
                'employee_details.department',
                'employee_details.designation'
            )
            ->get();

        // Step 4: Append today’s location or fallback to 'On-site'
        $teamWithLocation = $teamMembers->map(function ($member) {
            $todayLocation = Attendance::where('web_user_id', $member->web_user_id)
                ->whereDate('date', today())
                ->value('location') ?? 'On-site';

            return [
                'emp_id' => $member->emp_id,
                'name' => $member->name,
                'email' => $member->email,
                'department' => $member->department,
                'designation' => $member->designation,
                'location' => $todayLocation,
            ];
        });

        return response()->json([
            'department' => $department,
            'team_members' => $teamWithLocation,
        ]);
    }

    public function getAbout($id)
    {
        // Step 1: Get admin_user_id for the given web_user_id
        $adminUserId = WebUser::where('id', $id)->value('admin_user_id');

        // Step 2: Get About info
        $description = About::where('admin_user_id', $adminUserId)->value('about');

        // Step 3: Get Achievements info
        $achievements = Achievement::where('admin_user_id', $adminUserId)
            ->get(['achievement', 'values']);

        return response()->json([
            'description' => $description,
            'about' => $achievements,
        ]);
    }

    public function getServices($id)
    {
        // Step 1: Get admin_user_id for the given web_user_id
        $adminUserId = WebUser::where('id', $id)->value('admin_user_id');

        // Step 2: Get About info
        $description = About::where('admin_user_id', $adminUserId)->value('services');

        // Step 3: Get Achievements info
        $services = Service::where('admin_user_id', $adminUserId)
            ->get(['name', 'description']);

        return response()->json([
            'description' => $description,
            'services' => $services,
        ]);
    }

    public function getIndustries($id)
    {
        // Step 1: Get admin_user_id for the given web_user_id
        $adminUserId = WebUser::where('id', $id)->value('admin_user_id');

        // Step 2: Get About info
        $description = About::where('admin_user_id', $adminUserId)->value('industries');

        // Step 3: Get Achievements info
        $industry = Industries::where('admin_user_id', $adminUserId)
            ->get(['name', 'description']);

        return response()->json([
            'description' => $description,
            'industries' => $industry,
        ]);
    }

    public function getClients($id)
    {
        // Step 1: Get admin_user_id for the given web_user_id
        $adminUserId = WebUser::where('id', $id)->value('admin_user_id');

        // Step 2: Get About info
        $description = About::where('admin_user_id', $adminUserId)->value('client');

        // Step 3: Get Achievements info
        $client = Client::where('admin_user_id', $adminUserId)
            ->get(['name', 'logo']);

        return response()->json([
            'description' => $description,
            'clients' => $client,
        ]);
    }

    public function getTeamDescription($id)
    {
        // Step 1: Get admin_user_id for the given web_user_id
        $adminUserId = WebUser::where('id', $id)->value('admin_user_id');

        // Step 2: Get About info
        $description = About::where('admin_user_id', $adminUserId)->value('team');

        // Step 3: Get Achievements info
        $employees = WebUser::where('admin_user_id', $adminUserId)
            ->join('employee_details', 'web_users.id', '=', 'employee_details.web_user_id')
            ->select(
                'web_users.id as web_user_id',
                'web_users.name',
                'web_users.emp_id',
                'web_users.email',
                'web_users.role',
                'employee_details.department',
                'employee_details.designation'
            )
            ->get();

        // Step 3: Group by department
        $groupedByDepartment = $employees->groupBy('department');

        return response()->json([
            'description' => $description,
            'team' => $groupedByDepartment,
        ]);
    }

    public function getDashboardDetails($id)
    {
        $adminUserId = WebUser::where('id', $id)->value('admin_user_id');

        // New Joinees in the last 30 days
        $newJoinees = DB::table('web_users')
        ->join('employee_details', 'employee_details.web_user_id', '=', 'web_users.id')
        ->where('web_users.admin_user_id', $adminUserId)
        ->where('employee_details.date_of_joining', '>=', now()->subDays(30))
        ->select(
            'web_users.name',
            'web_users.role',
            'web_users.emp_id',
            'employee_details.date_of_joining as joined_at' // specify source
        )
        ->get();

        // Leave Report (total vs utilized)
        $leaveTypes = TotalLeaves::where('admin_user_id', $adminUserId)->get();
        $leaveUtilized = LeaveRequest::whereHas('webUser', function ($q) use ($adminUserId) {
            $q->where('admin_user_id', $adminUserId);
        })->where('status', 'Approved')
        ->select('type', DB::raw('count(*) as utilized'))
        ->groupBy('type')
        ->pluck('utilized', 'type');

        $leaveReport = $leaveTypes->map(function ($leave) use ($leaveUtilized) {
            return [
                'type' => $leave->type,
                'total' => $leave->total,
                'utilized' => $leaveUtilized[$leave->type] ?? 0
            ];
        });

        // Upcoming Leaves (Approved only)
        $upcomingLeaves = LeaveRequest::with('webUser')
            ->whereHas('webUser', function ($q) use ($adminUserId) {
                $q->where('admin_user_id', $adminUserId);
            })
            ->where('status', 'Approved')
            ->where('from', '>=', today())
            ->orderBy('from')
            ->get(['web_user_id', 'type', 'from', 'to', 'reason']);

        // Announcements
        $announcements = Event::where('admin_user_id', $adminUserId)
            ->orderByDesc('created_at')
            ->take(5)
            ->get(['name', 'description', 'created_at']);

        // Work Anniversaries
        $today = now();
        $anniversaries = WebUser::select(
            'web_users.name',
            'web_users.emp_id',
            'web_users.created_at' // or use 'employee_details.date_of_joining' if that's what you meant
        )
        ->join('employee_details', 'employee_details.web_user_id', '=', 'web_users.id')
        ->where('web_users.admin_user_id', $adminUserId)
        ->whereMonth('employee_details.date_of_joining', $today->month)
        ->whereDay('employee_details.date_of_joining', $today->day)
        ->get();

        // Goals
        $goals = Goals::where('web_user_id', $id)->get();

        // Upcoming Birthdays
        $birthdays = WebUser::with('employeeDetails')
            ->join('employee_details', 'employee_details.web_user_id', '=', 'web_users.id')
            ->where('admin_user_id', $adminUserId)
            ->whereMonth('employee_details.dob', now()->month)
            ->whereDay('employee_details.dob', '>=', now()->day)
            ->orderByRaw('MONTH(employee_details.dob), DAY(employee_details.dob)')
            ->take(10)
            ->get(['name', 'emp_id']);

        // Today's Leaves
        $todaysLeaves = LeaveRequest::whereDate('from', today())
            ->orWhereDate('to', today())
            ->where('status', 'Approved')
            ->whereHas('webUser', function ($q) use ($adminUserId) {
                $q->where('admin_user_id', $adminUserId);
            })
            ->get(['web_user_id', 'type', 'reason']);

        // Upcoming Holidays
        $holidays = Holidays::where('admin_user_id', $adminUserId)
            ->where('date', '>=', today())
            ->orderBy('date')
            ->take(5)
            ->get(['holiday', 'date']);

        return response()->json([
            'new_joinees' => $newJoinees,
            'leave_report' => $leaveReport,
            'upcoming_leaves' => $upcomingLeaves,
            'announcements' => $announcements,
            'work_anniversaries' => $anniversaries,
            'goals' => $goals,
            'upcoming_birthdays' => $birthdays,
            'todays_leaves' => $todaysLeaves,
            'upcoming_holidays' => $holidays,
        ]);
    }

    public function getSchedules($id, $month = null)
    {
        $requiredMonth = $month ?? now()->month;
        // Step 3: Get Achievements info
        $schedules = Schedule::where('web_user_id', $id)->whereMonth('date', $requiredMonth)->get();

        return response()->json([
            'schedules' => $schedules,
        ]);
    }

}
