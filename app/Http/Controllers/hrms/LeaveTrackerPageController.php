<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use App\Models\Holidays;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\LeaveRequest;
use App\Models\TotalLeaves;
use App\Models\WebUser;

class LeaveTrackerPageController extends Controller
{
    public function getLeaveStatus($id)
    {
        // Step 1: Get admin_user_id from web_user
        $webUser = WebUser::findOrFail($id);
        $adminUserId = $webUser->admin_user_id;

        // Step 2: Leave summary (allowed, taken, pending, remaining)
        $leaveSummary = LeaveRequest::where('web_user_id', $id)
            ->whereIn('status', ['approved', 'pending'])
            ->leftJoin('total_leaves', function ($join) use ($adminUserId) {
                $join->on('total_leaves.type', '=', 'leave_requests.type')->where('total_leaves.admin_user_id', '=', $adminUserId);
            })
            ->select(
                'total_leaves.type',
                'total_leaves.total as allowed',
                DB::raw("SUM(CASE WHEN leave_requests.status = 'approved' THEN DATEDIFF(leave_requests.to, leave_requests.from) + 1 ELSE 0 END) as taken"),
                DB::raw("SUM(CASE WHEN leave_requests.status = 'pending' THEN DATEDIFF(leave_requests.to, leave_requests.from) + 1 ELSE 0 END) as pending")
            )
            ->groupBy('total_leaves.type', 'total_leaves.total')
            ->get()
            ->map(function ($row) {
                $row->remaining = max($row->allowed - $row->taken, 0);
                $row->remaining_percentage = $row->allowed > 0
                    ? intval(($row->remaining / $row->allowed) * 100)
                    : 0;
                return $row;
            });

        // Step 3: Holiday list
        $holidays = Holidays::where('admin_user_id', $adminUserId)
            ->select('holiday', 'date', 'description')
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($holiday) {
                if (!empty($holiday->date)) {
                    $holiday->formatted_date = Carbon::parse($holiday->date)->format('d-m-Y');
                } else {
                    $holiday->formatted_date = null;
                }
                return $holiday;
            });

        // Step 4: Leave report (all leaves)
        $leaveReport = LeaveRequest::where('web_user_id', $id)
            ->select('date', 'type', 'from', 'to', 'days', 'reason', 'status')
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($leave) {
                $leave->date = $leave->date ? Carbon::parse($leave->date)->format('d-m-Y') : null;
                $leave->from = $leave->from ? Carbon::parse($leave->from)->format('d-m-Y') : null;
                $leave->to = $leave->to ? Carbon::parse($leave->to)->format('d-m-Y') : null;
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
            ->pluck('total', 'type'); // ['casualleave' => 12, ...]

        $takenLeaves = LeaveRequest::where('web_user_id', $id)
            ->whereYear('from', now()->year)
            ->select('type', DB::raw('SUM(DATEDIFF(`to`, `from`) + 1) as total'))
            ->groupBy('type')
            ->pluck('total', 'type'); // ['casualleave' => 5, ...]

        $graphRow = [
            'year' => now()->year,
        ];

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

        // Step 7: Final JSON response
        return response()->json([
            'status' => 'Success',
            'message' => 'Leave status fetched successfully.',
            'data' => [
                'leave_summary' => $leaveSummary,
                'holidays' => $holidays,
                'leave_report' => $leaveReport,
                'monthly_graph' => $monthlyGraph,
                'graph' => $graph
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
            'leave_id' => 'required|integer|exists:leave_requests,id',
            'status' => 'required|in:Approved,Rejected',
            'comment' => 'nullable|string',
        ]);

        $leaveRequest = LeaveRequest::find($request->leave_id);

        if (!$leaveRequest || !$validated) {
            return response()->json([
                'message' => 'Leave request not found.',
                'status' => 'error',
            ], 404);
        }

        $leaveRequest->status = $request->status;
        $leaveRequest->comment = $request->comment ?? $leaveRequest->comment; // optional
        $leaveRequest->save();

        return response()->json([
            'message' => 'Leave status updated successfully.',
            'status' => 'Success',
        ], 200);
    }
}
