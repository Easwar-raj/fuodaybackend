<?php

namespace App\Http\Controllers\ats;

use App\Http\Controllers\Controller;
use App\Models\CallLogs;
use Illuminate\Http\Request;
use App\Models\JobOpening;
use App\Models\EmployeeDetails;
use App\Models\WebUser;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        $callsToday = $query->whereDate('date', $today)->get();

        // 2. Total calls made yesterday
        $callsYesterday = $query->whereDate('date', $yesterday)->get();

        // 3. Follow-up calls today (by 'date' column and 'status')
        $followUpCallsToday = $query->whereDate('date', $today)->where('status', 'follow-up')->get();

        // 4. Non-responsive calls today (by 'created_at' and status)
        $nonResponsiveCallsToday = $query->whereDate('date', $today)->where('status', 'no-response')->get();

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

    public function saveCallLog(Request $request)
    {
        try {
            $validated = $request->validate([
                'action' => 'required|in:create,update',
                'web_user_id' => 'nullable|integer|exists:web_users,id',
                'name' => 'required|string|max:255',
                'contact' => 'nullable|string|max:255',
                'email' => 'nullable|email|max:255',
                'attempts' => 'nullable|integer',
                'status' => 'nullable|string|max:255',
                'date' => 'nullable|date_format:Y-m-d',
                'id' => 'required_if:action,update|integer|exists:call_logs,id',
            ]);

            $webUser = WebUser::findOrFail($request->web_user_id);

            if (!$webUser) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            if ($request->action === 'create') {
                CallLogs::create([
                    'web_user_id' => $validated['web_user_id'] ?? null,
                    'emp_name' => $webUser->name ?? null,
                    'emp_id' => $webUser->emp_id ?? null,
                    'name' => $request->name,
                    'contact' => $request->Contact ?? null,
                    'email' => $request->email ?? null,
                    'attempts' => 1,
                    'status' => $request->status ?? null,
                    'date' => now()->toDateString(),
                ]);

                return response()->json([
                    'status' => 'Success',
                    'message' => 'Call log created successfully.'
                ], 201);
            }

            if ($request->action === 'update') {
                $callLog = CallLogs::findOrFail($request->id);
                $callLog->update([
                    'name' => $request->name ?? $callLog->name,
                    'contact' => $request->contact ?? $callLog->contact,
                    'email' => $request->email ?? $callLog->email,
                    'attempts' => $request->attempts ?? $callLog->attempts,
                    'status' => $request->status ?? $callLog->status,
                ]);

                return response()->json([
                    'status' => 'Success',
                    'message' => 'Call log updated successfully.'
                ], 200);
            }
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Call log not found',
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
