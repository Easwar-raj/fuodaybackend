<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\LeaveRequest;
use App\Models\WebUser;

class LeaveTrackerPageController extends Controller
{
    public function getLeaveStatus($id)
    {
        // Step 1: Get the admin_user_id for the given web_user
        $adminUserId = DB::table('web_users')->where('id', $id)->value('admin_user_id');

        // Step 2: Fetch leave summary for the user under the same admin_user_id
        $leaveSummary = DB::table('leave_requests as l')->where('l.web_user_id', '=', $id)->whereIn('l.status', ['approved', 'pending'])
            ->leftJoin('total_leaves as tl', function ($join) use($adminUserId) {
                $join->on('tl.type', '=', 'l.type')->where('tl.admin_user_id', '=', $adminUserId);
            })
            ->select(
                'tl.type',
                'tl.total as allowed',
                DB::raw("SUM(CASE WHEN l.status = 'approved' THEN DATEDIFF(l.to, l.from) + 1 ELSE 0 END) as taken"),
                DB::raw("SUM(CASE WHEN l.status = 'pending' THEN DATEDIFF(l.to, l.from) + 1 ELSE 0 END) as pending")
            )
            ->groupBy('tl.type', 'tl.total')
            ->get()
            ->map(function ($row) {
                $row->remaining = max($row->allowed - $row->taken, 0);
                return $row;
            });

        // Step 3: Get holidays for the same admin_user_id
        $holidays = DB::table('holidays')
            ->where('admin_user_id', $adminUserId)
            ->select('holiday', 'date', 'description')
            ->orderBy('date', 'asc')
            ->get();

        // Step 4: Return data
        return response()->json([
            'status' => 'success',
            'leave_summary' => $leaveSummary,
            'holidays' => $holidays
        ]);
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
            'department' => $webUser->department,
            'type' => $request->type,
            'from' => $request->from,
            'to' => $request->to,
            'reason' => $request->reason,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Leave request added successfully.'
        ]);
    }


}
