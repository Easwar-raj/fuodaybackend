<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Holidays;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\LeaveRequest;
use App\Models\TotalLeaves;
use App\Models\WebUser;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;

class LeaveTrackerPageController extends Controller
{
    public function getLeaveStatus($id)
    {
        // Step 1: Get admin_user_id from web_user
        $webUser = WebUser::findOrFail($id);
        $adminUserId = $webUser->admin_user_id;

        // Step 2: Leave summary (allowed, taken, pending, remaining)
        $leaveSummary = TotalLeaves::where('admin_user_id', $adminUserId)
            ->with(['leaveRequests' => function ($query) use ($id) {
                $query->where('web_user_id', $id)->whereIn('status', ['Approved', 'Pending']);
            }])
            ->get()
            ->map(function ($leave) {
                $taken = $leave->leaveRequests
                    ->where('status', 'Approved')
                    ->sum(function ($req) {
                        return $req->from && $req->to ? $req->to->diffInDays($req->from) + 1 : 0;
                    });

                $pending = $leave->leaveRequests
                    ->where('status', 'Pending')
                    ->sum(function ($req) {
                        return $req->from && $req->to ? $req->to->diffInDays($req->from) + 1 : 0;
                    });

                return (object) [
                    'type' => $leave->type,
                    'allowed' => $leave->total,
                    'taken' => $taken,
                    'pending' => $pending,
                    'remaining' => max($leave->total - $taken, 0),
                    'remaining_percentage' => $leave->total > 0 ? intval((($leave->total - $taken) / $leave->total) * 100) : 0,
                ];
            });

        // Step 3: Holiday list
        $holidays = Holidays::where('admin_user_id', $adminUserId)
            ->select('holiday', 'date', 'description')
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($holiday) {
                $holiday->formatted_date = $holiday->date ? Carbon::parse($holiday->date)->format('d-m-Y') : null;
                return $holiday;
            });

        // Step 4: Leave report (all leaves)
        $leaveReport = LeaveRequest::where('web_user_id', $id)
            ->select('id', 'emp_id', 'department', 'date', 'type', 'from', 'to', 'days', 'reason', 'permission_timing', 'status', 'regulation_date', 'regulation_reason')
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($leave) {
                $leave->date = $leave->date ? Carbon::parse($leave->date)->format('d-m-Y') : null;
                $leave->from = $leave->from ? Carbon::parse($leave->from)->format('d-m-Y') : null;
                $leave->to = $leave->to ? Carbon::parse($leave->to)->format('d-m-Y') : null;
                $leave->regulation_date = $leave->regulation_date ? Carbon::parse($leave->regulation_date)->format('d-m-Y') : null;
                return $leave;
            });

        // Step 5: Monthly graph (leave taken per month)
        $monthlyGraph = LeaveRequest::select(
            DB::raw('MONTH(`from`) as month'),
            DB::raw('SUM(DATEDIFF(`to`, `from`) + 1) as leave_days')
        )
        ->whereYear('from', now()->year)
        ->where('web_user_id', $id)
        ->groupBy(DB::raw('MONTH(`from`)'))
        ->get()
        ->map(function ($item) {
            $monthNames = [1=>'Jan', 2=>'Feb', 3=>'March', 4=>'April', 5=>'May', 6=>'June', 7=>'July', 8=>'Aug', 9=>'Sep', 10=>'Oct', 11=>'Nov', 12=>'Dec'];
            return [
                'month' => $monthNames[$item->month] ?? $item->month,
                'leave' => (int)$item->leave_days,
                'days' => 15 // or calculate dynamically if needed
            ];
        });

        // Step 6: Dynamic yearly leave type graph (based on total_leaves)
        $leaveTypes = TotalLeaves::where('admin_user_id', $adminUserId)
            ->pluck('total', 'type');

        $takenLeaves = LeaveRequest::where('web_user_id', $id)
            ->whereYear('from', now()->year)
            ->select('type', DB::raw('SUM(DATEDIFF(`to`, `from`) + 1) as total'))
            ->groupBy('type')
            ->pluck('total', 'type');

        $graphRow = [ 'year' => now()->year ];

        $totalAllowed = 0;
        $totalTaken = 0;

        foreach ($leaveTypes as $type => $allowed) {
            $taken = $takenLeaves[$type] ?? 0;
            $graphRow[$type] = (int)$taken;
            $totalAllowed += (int) $allowed;
            $totalTaken += (int) $taken;
        }

        $graphRow['percentage'] = $totalAllowed > 0 ? intval(($totalTaken / $totalAllowed) * 100) . '%' : '0%';
        $graph = [$graphRow];

        $leaveTypesList = TotalLeaves::where('admin_user_id', $adminUserId)->pluck('type')->unique()->values();

        // Step 7: Final JSON response
        return response()->json([
            'status' => 'Success',
            'message' => 'Leave status fetched successfully.',
            'data' => [
                'id' => $id,
                'leave_summary' => $leaveSummary,
                'holidays' => $holidays,
                'leave_report' => $leaveReport,
                'monthly_graph' => $monthlyGraph,
                'graph' => $graph,
                'leaveT_types' => $leaveTypesList
            ],
        ], 200);
    }

    public function addLeave(Request $request)
    {
        $validated = $request->validate([
            'web_user_id' => 'required|exists:web_users,id',
            'type' => 'required|string',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from_date',
            'reason' => 'required|string',
            'permission_timing' => 'nullable|string',
        ]);

        $webUser = WebUser::with('employeeDetails')->find($request->web_user_id);

        if (!$validated || !$webUser) {
            return response()->json([
                'message' => 'Invalid data or user not found'
            ], 400);
        }

        LeaveRequest::create([
            'web_user_id' => $request->web_user_id,
            'emp_id' => $webUser->emp_id,
            'emp_name' => $webUser->name,
            'date' => Carbon::today()->toDateString(),
            'department' => $webUser->department,
            'type' => $request->type,
            'from' => $request->from,
            'to' => $request->to,
            'days' => Carbon::parse($request->from)->diffInDays(Carbon::parse($request->to)) + 1,
            'reason' => $request->reason,
            'permission_timing' => $request->permission_timing,
            'status' => 'Pending',
        ]);

        return response()->json([
            'message' => 'Leave request added successfully.',
            'status' => 'Success'
        ], 201);
    }

    public function updateLeaveStatus(Request $request)
    {
        $validated = $request->validate([
            'leave_request_id' => 'required|integer|exists:leave_requests,id',
            'status' => 'required|in:Approved,Rejected,Cancelled',
            'comment' => 'nullable|string',
        ]);

        $leaveRequest = LeaveRequest::find($request->leave_request_id);
        $user = Auth::user();

        if (!$leaveRequest || !$validated) {
            return response()->json([
                'message' => 'Leave request not found.',
                'status' => 'error',
            ], 404);
        }

        if ($request->status === 'Cancelled' && $leaveRequest->web_user_id === $user->id) {
            $now = now()->startOfDay();

            $fromDate = Carbon::parse($leaveRequest->from_date)->startOfDay();
            $toDate = Carbon::parse($leaveRequest->to_date)->startOfDay();

            if ($fromDate <= $now || $toDate <= $now) {
                return response()->json([
                    'message' => 'Leave can only be cancelled if both start and end dates are in the future.',
                    'status' => 'error',
                ], 400);
            }
        }

        $leaveRequest->status = $request->status;
        $leaveRequest->comment = $request->comment ?? $leaveRequest->comment;
        $leaveRequest->save();

        if ($request->status === 'Approved') {
            $period = CarbonPeriod::create($leaveRequest->from, $leaveRequest->to);

            foreach ($period as $date) {
                Attendance::updateOrCreate(
                    [
                        'web_user_id' => $leaveRequest->web_user_id,
                        'date' => $date->format('Y-m-d'),
                    ],
                    [
                        'emp_name' => $leaveRequest->emp_name,
                        'emp_id' => $leaveRequest->emp_id,
                        'status' => 'On Leave',
                        'checkin' => null,
                        'checkout' => null,
                        'worked_hours' => null,
                        'location' => null,
                    ]
                );
            }
        }

        return response()->json([
            'message' => 'Leave status updated successfully.',
            'status' => 'Success',
        ], 200);
    }

    public function regulateLeave(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:leave_requests,id',
            'type' => 'nullable|string',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'status' => 'nullable|string|max:255',
            'regulation_date' => 'required|date',
            'regulation_reason' => 'nullable|string',
            'regulation_status' => 'nullable|string|max:255',
            'regulation_comment' => 'nullable|string'
        ]);

        $webUser = auth()->user();
        $leave = LeaveRequest::find($request->id);

        if (!$webUser || !$leave) {
            return response()->json(['message' => 'User or Leave not found.'], 404);
        }

        $from = $request->from ?? $leave->from;
        $to = $request->to ?? $leave->to;

        $leave->type = $request->type ?? $leave->type;
        $leave->from = $from;
        $leave->to = $to;
        $leave->days = Carbon::parse($from)->diffInDays($to) + 1;
        $leave->status = $request->status ?? $leave->status;
        $leave->regulation_date = $request->regulation_date;
        $leave->regulation_reason = $request->regulation_reason ?? $leave->regulation_reason;
        if ($request->has('regulation_status') && !empty(trim($request->regulation_status))) {
            $leave->regulation_status = $request->regulation_status;
        } else {
            $leave->regulation_status = 'Pending';
        }

        $leave->regulation_comment = $request->regulation_comment ?? $leave->regulation_comment;

        $leave->save();

        return response()->json([
            'message' => 'Leave regulated successfully.',
            'status' => 'Success',
            'data' => [
                'regulation_status' => $leave->regulation_status
            ]
        ]);
    }
}