<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Attendance;
use App\Models\Audits;
use App\Models\ProjectTeam;
use App\Models\Task;
use App\Models\Feedbacks;
use App\Models\FeedbackReplies;
use App\Models\EmployeeDetails;
use App\Models\FeedbackQuestions;
use App\Models\WebUser;
use App\Models\Heirarchies;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Projects;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PerformancePageController extends Controller
{
    public function getUserTasksAndPerformance($id)
    {
        // Get all tasks assigned to this user
        $tasks = Task::with(['project', 'projectTeamBy'])
            ->where('assigned_to_id', $id)
            ->orderBy('date', 'desc')
            ->get();

        // Format tasks
        $mappedTasks = $tasks->map(function ($task) {
            return [
                'id'              => $task->id,
                'description'     => $task->description,
                'assigned_by'     => $task->assigned_by ?? null,
                'project'         => $task->project ?? null,
                'priority'        => $task->priority,
                'status'          => $task->status,
                'progress_note'   => $task->progress_note,
                'deadline'        => optional($task->deadline)->format('Y-m-d'),
                'comment'         => $task->comment,
                'date'            => optional($task->date)->format('Y-m-d'),
            ];
        });

        // Normalize and filter task statuses case-insensitively
        $completedTasks = $tasks->filter(function ($task) {
            return strtolower($task->status) === 'completed';
        });

        $priorityOrder = ['High' => 1, 'Medium' => 2, 'Low' => 3];

        $pendingTasks = $tasks
            ->filter(function ($task) {
                $status = strtolower($task->status);
                return in_array($status, ['pending', 'in progress']);
            })
            ->sort(function ($a, $b) use ($priorityOrder) {
                $priorityA = $priorityOrder[$a->priority] ?? 999;
                $priorityB = $priorityOrder[$b->priority] ?? 999;

                if ($priorityA !== $priorityB) {
                    return $priorityA <=> $priorityB;
                }

                return strtotime($a->deadline) <=> strtotime($b->deadline);
            });

        $goalProgressTasks = $tasks->filter(function ($task) {
            return strtolower($task->status) === 'completed' && strtolower($task->priority) === 'high';
        });

        $totalTasksCount = $tasks->count();
        $goalProgressCount = $goalProgressTasks->count();

        $goalProgressPercentage = $totalTasksCount > 0 ? round(($goalProgressCount / $totalTasksCount) * 100, 2) : 0;

        $onTimeCompletedTasks = $completedTasks->filter(function ($task) {
            if ($task->deadline && $task->updated_at) {
                return $task->updated_at->format('Y-m-d') <= $task->deadline->format('Y-m-d');
            }
            return false;
        });

        $completedCount = $completedTasks->count();
        $onTimeCompletedCount = $onTimeCompletedTasks->count();
        $timelyPerformance = $completedCount > 0 ? round(($onTimeCompletedCount / $completedCount) * 100, 2) : 0;
        $ratingOutOfFive = round(($timelyPerformance / 100) * 5, 2);

        // Get all project team entries for this user
        $projectTeamEntries = ProjectTeam::where('web_user_id', $id)->get();

        // All related project IDs
        $allProjectIds = $projectTeamEntries->pluck('project_id')->filter()->unique();

        // Fetch projects
        $allProjects = Projects::whereIn('id', $allProjectIds)->get();
        $upcomingProjects = Projects::whereIn('id', $allProjectIds)->where('progress', '0%')->get();
        $completedProjects = $allProjects->filter(function ($project) {
            return $project->progress === '100%' || strtolower($project->progress) === 'completed';
        })->values();

        $monthlyAttendance = DB::table('attendances')
            ->selectRaw('
                YEAR(date) as year,
                MONTH(date) as month,
                COUNT(*) as total_days,
                SUM(CASE WHEN LOWER(status) IN ("present", "late", "early", "punctual", "half day") THEN 1 ELSE 0 END) as present_days
            ')
            ->where('web_user_id', $id)
            ->groupByRaw('YEAR(date), MONTH(date)')
            ->get();

        $monthlyPercentages = $monthlyAttendance->map(function ($record) {
            return $record->total_days > 0 ? round(($record->present_days / $record->total_days) * 100, 2) : 0;
        });

        $averageMonthlyAttendance = $monthlyPercentages->count() > 0 ? round($monthlyPercentages->avg(), 2) : 0;

        return response()->json([
            'status'  => 'Success',
            'message' => 'User tasks, performance, and project data fetched successfully.',
            'data'    => [
                'tasks' => $mappedTasks,
                'total_completed' => $completedCount,
                'completed_tasks' => $completedTasks->values(),
                'total_pending' => $pendingTasks->count(),
                'pending_goals' => $pendingTasks->values(),
                'goal_progress_percentage' => $goalProgressPercentage,
                'goal_progress' => $goalProgressTasks->values(),
                'performance_score' => $timelyPerformance,
                'performance_score_tasks' => $onTimeCompletedTasks,
                'performance_rating_out_of_5' => $ratingOutOfFive,
                'total_completed_projects' => $completedProjects->count(),
                'completed_projects' => $completedProjects,
                'total_upcoming_projects' => $upcomingProjects->count(),
                'upcoming_projects' => $upcomingProjects,
                'average_monthly_attendance' => $averageMonthlyAttendance
            ],
        ], 200);
    }

    public function updateTaskStatus(Request $request)
    {
        // Step 1: Validate the request
        $validated = $request->validate([
            'task_id' => 'required|exists:tasks,id',
            'web_user_id' => 'required|exists:web_users,id',
            'status' => 'nullable|string|max:255',
            'progress' => 'nullable|string|max:255',
            'comment' => 'nullable|string|max:255'
        ]);

        // Step 2: Find the task and make sure it belongs to the given web_user_id
        $task = Task::where('id', $request->task_id)->where('assigned_to_id', $request->web_user_id)->first();

        if (!$validated || !$task) {
            return response()->json([
                'message' => 'Invalid data'
            ], 400);
        }

        // Step 3: Update only the allowed fields
        $task->status = $request->status ?? $task->status;
        $task->progress_note = $request->progress ?? $task->progress;
        $task->comment = $request->comment ?? $task->comment;
        $task->save();

        // Step 4: Return success response
        return response()->json([
            'message' => 'Task updated successfully.',
            'status' => 'Success'
        ], 200);
    }

    public function getUserFeedbackDetails($id)
    {
        $adminId = WebUser::where('id', $id)->value('admin_user_id');
        // Feedbacks requested by the user (about others)
        $requestedFeedbacks = Feedbacks::where('to_id', '!=', $id)
            ->where('from_id', $id)
            ->with(['webUser' => function ($query) {
                $query->select('id', 'name');
            }])
            ->get()
            ->map(function ($feedback) {
                $profileImage = EmployeeDetails::where('web_user_id', $feedback->to_id)->value('profile_photo');
                return [
                    'to_id' => $feedback->to_id,
                    'to_name' => $feedback->to_name,
                    'to_designation' => $feedback->to_designation,
                    'profile_image' => $profileImage,
                ];
            });

        // Feedbacks received about the user
        $receivedFeedbacks = Feedbacks::where('to_id', $id)->get();

        $feedbackDetails = $receivedFeedbacks->map(function ($feedback) {
            $replies = FeedbackReplies::where('feedback_id', $feedback->id)->get();
            return [
                'feedback_id' => $feedback->id,
                'from_name' => $feedback->from_name,
                'overall_ratings' => $feedback->overall_ratings,
                'review_ratings' => $feedback->review_ratings,
                'comments' => $feedback->comments,
                'replies' => $replies->map(function ($reply) {
                    return [
                        'from_name' => $reply->from_name,
                        'to_name' => $reply->to_name,
                        'reply' => $reply->reply,
                        'date' => $reply->date,
                    ];
                }),
            ];
        });

        return response()->json([
            'status' => 'Success',
            'message' => 'Feedback details fetched successfully.',
            'data' => [
                'requested_feedbacks' => $requestedFeedbacks,
                'received_feedbacks' => $feedbackDetails,
            ],
        ], 200);
    }

    public function getFeedbackQuestions($id)
    {
        $webUser = WebUser::with('employeeDetails')->find($id);
        $adminUser = AdminUser::find($webUser->admin_user_id);

        if (!$webUser || !$adminUser) {
            return response()->json([
                'message' => 'Invalid data or user not found'
            ], 400);
        }

        // Step 2: Get all feedback questions for that admin_user_id
        $questions = FeedbackQuestions::where('admin_user_id', $adminUser->id)->get();

        return response()->json([
            'message' => 'Feedback questions retrieved successfully',
            'status' => 'Success',
            'data' => $questions
        ], 200);
    }

    public function addFeedback(Request $request)
    {
        // Step 1: Validate the request data
        $validated = $request->validate([
            'requested_by_id' => 'required|exists:web_users,id',
            'requested_by_name' => 'required|string',
            'to_id' => 'required|exists:web_users,id',
            'to_name' => 'required|string',
            'from_id' => 'required|exists:web_users,id',
            'from_name' => 'required|string',
        ]);

        $webUser = WebUser::with('employeeDetails')->find($request->to_id);
        $adminUser = AdminUser::find($webUser->admin_user_id);

        if (!$validated || !$webUser) {
            return response()->json([
                'message' => 'Invalid data or user not found'
            ], 400);
        }

        $today = now()->toDateString();

        // Step 3: Create a new ticket
        Feedbacks::create([
            'admin_user_id' => $webUser->admin_user_id,
            'company_name' => $adminUser->company_name,
            'requested_by_id' => $request->requested_by_id,
            'requested_by_name' => $request->requested_by_name,
            'from_id' => $request->from_id,
            'from_name' => $request->from_name,
            'to_id' => $request->to_id,
            'to_name' => $request->to_name,
            'to_designation' => $webUser->employeeDetails->designation,
            'date' => $today,
        ]);

        // Step 4: Return success response
        return response()->json([
            'message' => 'Feedback created successfully',
            'status' => 'Success'
        ], 201);
    }

    public function updateFeedback(Request $request, $id)
    {
        $validated = $request->validate([
            'to_id' => 'required|exists:web_users,id',
            'to_name' => 'required|exists:web_users,name',
            'from_id' => 'required|exists:web_users,id',
            'from_name' => 'required|exists:web_users,name',
            'overall_ratings' => 'nullable|numeric|min:1|max:5',
            'review_ratings' => 'nullable|array',
            'review_ratings.*' => 'integer|min:1|max:5',
            'comments' => 'nullable|string',
        ]);

        if (!$validated) {
            return response()->json([
                'message' => 'Invalid data'
            ], 400);
        }

        $feedback = Feedbacks::where([
            ['id', $id],
            ['to_id', $request->to_id],
            ['to_name', $request->to_name],
            ['from_id', $request->from_id],
            ['from_name', $request->from_name],
        ])->first();

        if (!$feedback) {
            return response()->json(['message' => 'Feedback not found'], 404);
        }

        // Fetch number of feedback questions for this admin user
        $questionCount = FeedbackQuestions::where('admin_user_id', $feedback->admin_user_id)->count();

        // Check that number of ratings match number of questions
        if ($request->has('review_ratings') && count($request->review_ratings) !== $questionCount) {
            return response()->json([
                'message' => "Number of review ratings (" . count($request->review_ratings) . ") does not match number of feedback questions ($questionCount)."
            ], 422);
        }

        $feedback->overall_ratings = $request->overall_ratings;
        $feedback->review_ratings = isset($request->review_ratings) ? implode('%', $request->review_ratings) : null;
        $feedback->comments = $request->comments;
        $feedback->save();

        return response()->json(['message' => 'Feedback updated successfully', 'status' => 'Success'], 200);
    }

    public function addFeedbackReply(Request $request)
    {
        // Step 1: Validate the request inputs
        $validated = $request->validate([
            'feedback_id' => 'required|exists:feedbacks,id',
            'to_id' => 'required|exists:web_users,id',
            'to_name' => 'required|string',
            'from_id' => 'required|exists:web_users,id',
            'from_name' => 'required|string',
            'reply' => 'required|string',
        ]);

        if (!$validated) {
            return response()->json([
                'message' => 'Invalid data'
            ], 400);
        }

        // Step 2: Get the web user to fetch admin_user_id and company name
        $webUser = WebUser::find($request->to_id);
        $adminUser = AdminUser::find($webUser->admin_user_id);

        if (!$webUser || !$adminUser) {
            return response()->json(['message' => 'User or related admin user not found'], 404);
        }

        // Step 3: Insert the reply
        FeedbackReplies::create([
            'feedback_id' => $request->feedback_id,
            'admin_user_id' => $webUser->admin_user_id,
            'company_name' => $adminUser->company_name,
            'to_id' => $request->to_id,
            'to_name' => $request->to_name,
            'from_id' => $request->from_id,
            'from_name' => $request->from_name,
            'reply' => $request->reply,
            'date' => now()->toDateString(),
        ]);

        // Step 4: Return success
        return response()->json([
            'message' => 'Reply added successfully.',
            'status' => 'Success'
        ], 201);
    }

    public function getHeirarchies($id)
    {
        $webUser = WebUser::find($id);

        if (!$webUser || !$webUser->admin_user_id) {
            return response()->json([
                'message' => 'User not found.'
            ], 404);
        }

        $heirarchies = Heirarchies::where('admin_user_id', $webUser->admin_user_id)->get();

        return response()->json([
            'message' => 'Heirarchies fetched successfully.',
            'status' => 'Success',
            'data' => $heirarchies
        ], 200);
    }

    public function getEnployeeAudit($id)
    {
        $employee = EmployeeDetails::where('web_user_id', $id)->first();

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        // Attendance Summary for the latest month
        $lastMonth = Carbon::now()->subMonth();

        // Set date range: 25th of last-before-month to 24th of last month
        $startDate = Carbon::now()->subMonths(2)->startOfMonth()->addDays(24); // 25th of last-before-month
        $endDate = Carbon::now()->subMonth()->startOfMonth()->addDays(23); // 24th of last month

        // Get attendance records in that range
        $attendance = Attendance::where('web_user_id', $id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get();

        // Count each type
        $present = $attendance->where('status', 'Present')->count();
        $leave = $attendance->where('status', 'Absent')->count();
        $lop = $attendance->where('status', 'Lop')->count();

        // Total working days considered = total number of attendance records (assuming 1 per working day)
        $totalDays = $attendance->count();

        // Avoid division by zero
        $presentPercentage = $totalDays > 0 ? round(($present / $totalDays) * 100, 2) : 0;

        $payslips = Payslip::whereHas('payroll', function ($q) use ($id) {
                $q->where('web_user_id', $id);
            })
            ->with('payroll')
            ->whereMonth('date', $lastMonth->month)
            ->whereYear('date', $lastMonth->year)
            ->get();

        $performance = Task::where('web_user_id', $id)
            ->whereMonth('date', $lastMonth->month)
            ->whereYear('date', $lastMonth->year)
            ->get();

        return response()->json([
            'message' => 'Employee Pre Filled Audit Data retrieved successfully',
            'data' => [
                'employee_name'        => $employee->emp_name,
                'emp_id'               => $employee->emp_id,
                'department'           => $employee->department,
                'designation'          => $employee->designation,
                'reporting_manager'    => $employee->reporting_manager_name,
                'date_of_joining'      => Carbon::parse($employee->date_of_joining)->format('Y-m-d'),
                'working_mode'         => $employee->work_module,
                'attendance_summary'   => [
                    'present' => $present,
                    'leave'   => $leave,
                    'lop'     => $lop
                ],
                'attendance_percentage' => $presentPercentage,
                'payroll' => [
                    'ctc'      => optional($payslips)->ctc ?? 0,
                    'total_salary'  => optional($payslips)->total_salary ?? 0,
                    'month'    => $lastMonth->format('F Y')
                ],
                'performance' => $performance
            ]
        ]);
    }

    public function addAudit(Request $request)
    {
        $validated = $request->validate([
            'web_user_id'               => 'required|exists:web_users,id',
            'key_tasks_completed'        => 'nullable|string',
            'challenges_faced'           => 'nullable|string',
            'proud_contribution'         => 'nullable|string',
            'training_support_needed'    => 'nullable|string',
            'rating_technical_knowledge' => 'nullable|integer|min:0|max:10',
            'rating_teamwork'            => 'nullable|integer|min:0|max:10',
            'rating_communication'       => 'nullable|integer|min:0|max:10',
            'rating_punctuality'         => 'nullable|integer|min:0|max:10',
            'training'                   => 'nullable|string',
            'hike'                       => 'nullable|string',
            'growth_path'                => 'nullable|string',
        ]);

        if (!$validated) {
            return response()->json([
                'message' => 'Invalid data'
            ], 400);
        }

        $webUser = WebUser::find($request->web_user_id);

        if (!$webUser || !$webUser->admin_user_id) {
            return response()->json([
                'message' => 'User not found.'
            ], 404);
        }

        // 🔹 Fetch employee details
        $employeeDetails = EmployeeDetails::where('web_user_id', $webUser->id)->first();

        if (!$employeeDetails) {
            return response()->json([
                'message' => 'Employee details not found.'
            ], 404);
        }

        // 🔹 Create audit with formatted date_of_joining
        Audits::create([
            'web_user_id'               => $request->web_user_id,
            'emp_name'                  => $webUser->name,
            'emp_id'                    => $webUser->emp_id,
            'post'                      => $employeeDetails->post,
            'department'                => $employeeDetails->department,
            'date_of_joining'           => Carbon::parse($employeeDetails->date_of_joining)->format('Y-m-d'),
            'key_tasks_completed'        => $request->key_tasks_completed,
            'challenges_faced'           => $request->challenges_faced,
            'proud_contribution'         => $request->proud_contribution,
            'training_support_needed'    => $request->training_support_needed,
            'rating_technical_knowledge' => $request->rating_technical_knowledge,
            'rating_teamwork'            => $request->rating_teamwork,
            'rating_communication'       => $request->rating_communication,
            'rating_punctuality'         => $request->rating_punctuality,
            'training'                   => $request->training,
            'hike'                       => $request->hike,
            'growth_path'                => $request->growth_path,
        ]);

        return response()->json([
            'message' => 'Audit created successfully',
            'status' => 'Success'
        ], 201);
    }

    public function updateAudit(Request $request, $id)
    {
        $validated = $request->validate([
            // Reporting Manager (Evaluation Fields)
            'daily_call_attendance' => 'nullable|boolean',
            'leads_generated' => 'nullable|string',
            'targets_assigned' => 'nullable|string',
            'targets_achieved' => 'nullable|string',
            'conversion_ratio' => 'nullable|string',
            'revenue_contribution' => 'nullable|string',
            'deadline_consistency' => 'nullable|boolean',
            'discipline_accountability' => 'nullable|string',
            'rating_proactiveness_ownership' => 'nullable|integer',

            // Tech Development team
            'tasks_completed_on_time' => 'nullable|boolean',
            'code_quality_bugs_fixed' => 'nullable|string',
            'contribution_to_roadmap' => 'nullable|string',
            'innovation_ideas' => 'nullable|string',
            'collaboration_with_teams' => 'nullable|boolean',
            'rating_technical_competency' => 'nullable|integer',

            // Manager’s Final Inputs
            'employee_strengths' => 'nullable|string',
            'areas_of_improvement' => 'nullable|string',
            'recommendation_probation' => 'nullable|string',
            'is_eligible_for_hike' => 'nullable|boolean',
            'hike_percentage_suggestion' => 'nullable|string',
            'manager_approve' => 'nullable|boolean',

            // Higher Authority / Management (Final Review Fields)
            'manager_evaluation_validation' => 'nullable|string',
            'cross_team_comparison' => 'nullable|string',
            'pca_audit_score' => 'nullable|numeric',
            'hike_decision' => 'nullable|string',
            'final_hike_percentage' => 'nullable|numeric',
            'probation_decision' => 'nullable|string',
            'future_role_growth_plan' => 'nullable|string',
            'management_review' => 'nullable|integer',
            'final_remarks' => 'nullable|string',
            'auditor_review' => 'nullable|string',
        ]);

        $audit = Audits::find($id);

        if (!$audit) {
            return response()->json(['message' => 'Audit record not found'], 404);
        }

        $audit->update($validated);

        return response()->json([
            'message' => 'Audit updated successfully',
            'status' => 'Success'
        ], 200);
    }

    public function approveAudit(Request $request, $id)
    {
        $validated = $request->validate([
            "reporting_manager_id" => 'required|exists:web_users,id',
            'management_assign' => 'nullable|string',
            'management_remarks' => 'nullable|string',
        ]);

        $user = Auth::user();
        $reportingManager = WebUser::find($request->reporting_manager_id);
        if ($user->id !== $id || !$validated || $user->admin_user_id !== $reportingManager->admin_user_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid data or token'
            ], 400);
        }

        $managementAssign  = $request->management_assign;
        $managementRemarks = $request->management_remarks;

        $managementReview = null;

        if ($managementAssign || $managementRemarks) {
            $managementReview = trim(($managementAssign ?? '') . '%' . ($managementRemarks ?? ''), '%');
        }

        $team = EmployeeDetails::where('reporting_manager_id', $id)->pluck('web_user_id')->toArray();

        foreach ($team as $memberId) {
            $audit = Audits::where('web_user_id', $memberId)->first();
            if ($audit && $audit->final_remarks) {
                $audit->management_review = $managementReview;
                $audit->save();
            }
        }

        return response()->json([
            'message' => 'Audit updated by Management successfully',
            'status' => 'Success'
        ], 200);
    }

    public function getAuditReport($id)
    {
        $audit = Audits::where('web_user_id', $id)->first();

        if (!$audit) {
            return response()->json([
                'message' => 'Audit record not found',
                'status' => 'Error'
            ], 404);
        }

        return response()->json([
            'message' => 'Audit data retrieved successfully',
            'status' => 'Success',
            'data' => $audit
        ], 200);
    }

    public function getAllAudits()
    {
        $audits = Audits::whereNotNull('final_remarks')
            ->where('final_remarks', '!=', '')
            ->where('final_remarks', '!=', '(NULL)')
            ->get();

        if ($audits->isEmpty()) {
            return response()->json([
                'message' => 'No audit records found',
                'status' => 'Error'
            ], 404);
        }

        $data = $audits->map(function ($audit) {
            $result = [
                'employee' => [
                    'web_user_id' => $audit->web_user_id,
                    'emp_name' => $audit->emp_name,
                    'emp_id' => $audit->emp_id,
                    'department' => $audit->department,
                    'date_of_joining' => $audit->date_of_joining,
                    'key_tasks_completed' => $audit->key_tasks_completed,
                    'challenges_faced' => $audit->challenges_faced,
                    'proud_contribution' => $audit->proud_contribution,
                    'training_support_needed' => $audit->training_support_needed,
                    'rating_technical_knowledge' => $audit->rating_technical_knowledge,
                    'rating_teamwork' => $audit->rating_teamwork,
                    'rating_communication' => $audit->rating_communication,
                    'rating_punctuality' => $audit->rating_punctuality,
                    'training' => $audit->training,
                    'hike' => $audit->hike,
                    'growth_path' => $audit->growth_path,
                ],

                'manager' => [
                    'daily_call_attendance' => $audit->daily_call_attendance,
                    'leads_generated' => $audit->leads_generated,
                    'targets_assigned' => $audit->targets_assigned,
                    'targets_achieved' => $audit->targets_achieved,
                    'conversion_ratio' => $audit->conversion_ratio,
                    'revenue_contribution' => $audit->revenue_contribution,
                    'deadline_consistency' => $audit->deadline_consistency,
                    'discipline_accountability' => $audit->discipline_accountability,
                    'rating_proactiveness_ownership' => $audit->rating_proactiveness_ownership,
                    'tasks_completed_on_time' => $audit->tasks_completed_on_time,
                    'code_quality_bugs_fixed' => $audit->code_quality_bugs_fixed,
                    'contribution_to_roadmap' => $audit->contribution_to_roadmap,
                    'innovation_ideas' => $audit->innovation_ideas,
                    'collaboration_with_teams' => $audit->collaboration_with_teams,
                    'rating_technical_competency' => $audit->rating_technical_competency,
                    'employee_strengths' => $audit->employee_strengths,
                    'areas_of_improvement' => $audit->areas_of_improvement,
                    'recommendation_probation' => $audit->recommendation_probation,
                    'is_eligible_for_hike' => $audit->is_eligible_for_hike,
                    'hike_percentage_suggestion' => $audit->hike_percentage_suggestion,
                    'manager_approve' => $audit->manager_approve,
                ],
            ];

            // Only include management if management_review exists
            if (!empty($audit->management_review)) {
                $result['management'] = [
                    'manager_evaluation_validation' => $audit->manager_evaluation_validation,
                    'cross_team_comparison' => $audit->cross_team_comparison,
                    'pca_audit_score' => $audit->pca_audit_score,
                    'hike_decision' => $audit->hike_decision,
                    'final_hike_percentage' => $audit->final_hike_percentage,
                    'probation_decision' => $audit->probation_decision,
                    'future_role_growth_plan' => $audit->future_role_growth_plan,
                    'management_review' => $audit->management_review,
                    'final_remarks' => $audit->final_remarks,
                ];
            }

            return $result;
        });

        return response()->json([
            'message' => 'All audits retrieved successfully',
            'status' => 'Success',
            'data' => $data
        ], 200);
    }

    public function getAuditReportingTeam($id)
    {
        $webUser = WebUser::find($id);

        if (!$webUser || !$webUser->admin_user_id) {
            return response()->json([
                'message' => 'User not found.'
            ], 404);
        }

        $team = EmployeeDetails::where('reporting_manager_id', $id)->get();

        $teamWithAuditStatus = $team->map(function ($member) {
            $hasAudit = Audits::where('web_user_id', $member->web_user_id)->exists();

            return [
                'web_user_id' => $member->web_user_id,
                'emp_name' => $member->emp_name,
                'emp_id' => $member->emp_id,
                'department' => $member->department,
                'status' => $hasAudit ? 'Submitted' : 'Not Submitted',
            ];
        });

        return response()->json([
            'message' => 'Audit Reporting Team fetched successfully.',
            'status' => 'Success',
            'data' => $teamWithAuditStatus
        ], 200);
    }

    public function getAllAuditReport($id)
    {
        $webUser = WebUser::find($id);

        if (!$webUser) {
            return response()->json([
                'message' => 'Web user not found',
                'status' => 'Error'
            ], 404);
        }

        $adminUser = AdminUser::find($webUser->admin_user_id);

        if (!$adminUser) {
            return response()->json([
                'message' => 'Admin user not found',
                'status' => 'Error'
            ], 404);
        }

        $webuserIds = WebUser::where('admin_user_id', $adminUser->id)->pluck('id');

        $audit = Audits::whereIn('web_user_id', $webuserIds)->get();

        if ($audit->isEmpty()) {
            return response()->json([
                'message' => 'No audit records found',
                'status' => 'Error'
            ], 404);
        }

        return response()->json([
            'message' => 'Audit data retrieved successfully',
            'status' => 'Success',
            'data' => $audit
        ], 200);
    }
}
