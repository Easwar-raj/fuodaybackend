<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Attendance;
use App\Models\ProjectTeam;
use App\Models\Task;
use App\Models\Feedbacks;
use App\Models\FeedbackReplies;
use App\Models\EmployeeDetails;
use App\Models\FeedbackQuestions;
use App\Models\WebUser;
use App\Models\Heirarchies;
use Illuminate\Http\Request;

class PerformancePageController extends Controller
{
    public function getUserTasks($id)
    {
        // Get all tasks assigned to this user
        $tasks = Task::with(['project', 'projectTeamBy'])
            ->where('assigned_to_id', $id)
            ->get()
            ->map(function ($task) {
                return [
                    'description' => $task->description,
                    'assigned_by' => $task->assigned_by ?? null,
                    'project'     => $task->project ?? null,
                    'priority'    => $task->priority,
                    'status'      => $task->status,
                    'progress_note' => $task->progress_note,
                    'deadline'    => $task->deadline->format('Y-m-d'),
                    'date'        => $task->date->format('Y-m-d'),
                ];
            });

        // Status counts
        $totalCompleted = Task::where('assigned_to_id', $id)->where('status', 'Completed')->count();
        $totalPending = Task::where('assigned_to_id', $id)->where('status', 'Pending')->count();
        $totalInProgress = Task::where('assigned_to_id', $id)->where('status', 'In Progress')->count();

        return response()->json([
            'status' => 'Success',
            'message' => 'Tasks fetched successfully.',
            'data' => [
                'tasks' => $tasks,
                'total_completed' => $totalCompleted,
                'total_pending' => $totalPending,
                'total_in_progress' => $totalInProgress,
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
        $task->save();

        // Step 4: Return success response
        return response()->json([
            'message' => 'Task updated successfully.',
            'status' => 'Success'
        ], 200);
    }

    public function getTeamPerformance($id)
    {
        $today = now()->toDateString();

        // Step 1: Get all project IDs where this user is involved
        $projectIds = ProjectTeam::where('web_user_id', $id)->pluck('project_id');

        // Step 2: Get all unique team member IDs from those projects
        $teamMemberIds = ProjectTeam::whereIn('project_id', $projectIds)->pluck('web_user_id')->unique()->values();

        $totalMembers = count($teamMemberIds);
        $locationCounts = [
            'on-site' => 0,
            'leave' => 0,
            'work from home' => 0,
            'half day' => 0,
            'unknown' => 0,
        ];

        foreach ($teamMemberIds as $memberId) {
            $attendance = Attendance::where('web_user_id', $memberId)->whereDate('date', $today)->first();

            $location = strtolower(trim($attendance->location ?? 'unknown'));

            if (array_key_exists($location, $locationCounts)) {
                $locationCounts[$location]++;
            } else {
                $locationCounts['unknown']++;
            }
        }

        // Step 3: Calculate availability percentage
        $availabilityPercentage = [];
        foreach ($locationCounts as $location => $count) {
            $availabilityPercentage[$location] = $totalMembers > 0 ? round(($count / $totalMembers) * 100, 0) : 0;
        }

        // Step 4: Task performance analysis
        $tasks = Task::where('assigned_to_id', $id)->get();
        $totalTasks = $tasks->count();
        $completedTasks = $tasks->where('status', 'completed')->count();
        $pendingTasks = $tasks->where('status', 'pending')->count();
        $completedPercentage = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 2) : 0;
        $pendingPercentage = $totalTasks > 0 ? round(($pendingTasks / $totalTasks) * 100, 2) : 0;

        // Step 5: Timely performance calculation
        $onTimeTasks = 0;
        foreach ($tasks as $task) {
            if ($task->status === 'completed' && $task->deadline && $task->updated_at) {
                if (date('Y-m-d', strtotime($task->updated_at)) <= date('Y-m-d', strtotime($task->deadline))) {
                    $onTimeTasks++;
                }
            }
        }

        $timelyPerformance = $completedTasks > 0 ? round(($onTimeTasks / $completedTasks) * 100, 2) : 0;

        // Step 6: Rating out of 5 based on timely performance
        $ratingOutOfFive = round(($timelyPerformance / 100) * 5, 2);

        return response()->json([
            'status' => 'Success',
            'message' => 'Team performance data fetched successfully.',
            'data' => [
                'team_availability' => $availabilityPercentage,
                'completed_percentage' => $completedPercentage,
                'pending_percentage' => $pendingPercentage,
                'performance_score' => $timelyPerformance,
                'performance_rating_out_of_5' => $ratingOutOfFive,
            ],
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

}
