<?php

namespace App\Http\Controllers\ats;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Candidate;
use App\Models\WebUser;
use Carbon\Carbon;

class TrackerPageController extends Controller
{
    public function getTrackerData(Request $request, $id)
    {
        $webUser = WebUser::find($id);
 
            if (!$webUser) {
                return response()->json([
                    'error' => 'Invalid web_user_id',
                    'message' => 'User not found'
                ], 404);
            }
 
        $webuserIds = WebUser::where('admin_user_id', $webUser->admin_user_id)->pluck('id');
        $query = Candidate::with(['details:id,candidate_id,nationality'])->whereIn('web_user_id', $webuserIds)->select('id', 'emp_name as employee_name', 'experience', 'role', 'ats_score');

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('experience')) {
            $query->where('experience', $request->experience);
        }

        if ($request->filled('employee_name')) {
            $query->where('emp_name', 'LIKE', "%{$request->employee_name}%");
        }

        if ($request->filled('ats_score')) {
            $query->where('ats_score', $request->ats_score);
        }

        if ($request->filled('location')) {
            $query->whereHas('details', function ($q) use ($request) {
                $q->where('nationality', 'LIKE', "%{$request->location}%");
            });
        }

        $candidates = $query->get()->map(function ($candidate) {
            return [
                'id' => $candidate->id,
                'employee_name' => $candidate->employee_name,
                'experience' => $candidate->experience,
                'role' => $candidate->role,
                'ats_score' => $candidate->ats_score,
                'location' => optional($candidate->details)->nationality,
            ];
        });

        $resumeDownloadedList = Candidate::whereNotNull('resume')->get(['id', 'emp_name as employee_name', 'ats_score']);
        $above80List = Candidate::where('ats_score', '>=', 80)->get(['id', 'emp_name as employee_name', 'ats_score']);
        $between50_80List = Candidate::whereBetween('ats_score', [50, 79])->get(['id', 'emp_name as employee_name', 'ats_score']);
        $below40List = Candidate::where('ats_score', '<', 40)->get(['id', 'emp_name as employee_name', 'ats_score']);

        return response()->json([
            'status' => 'Success',
            'message' => 'Tracker data fetched successfully',
            'data' => [
                'total_resume_downloaded' => $resumeDownloadedList->count(),
                'resume_downloaed_list' => $resumeDownloadedList,
                'total_above_80' => $above80List->count(),
                'above_80_list' => $above80List,
                'total_between_50_80' => $between50_80List->count(),
                'between_50_80_list' => $between50_80List,
                'total_below_40' => $below40List->count(),
                'below_40_list' => $below40List,
                'candidates' => $candidates,
            ]
        ], 200);
    }

    public function getInterviewStats($id)
    {
        $webUser = WebUser::find($id);
 
            if (!$webUser) {
                return response()->json([
                    'error' => 'Invalid web_user_id',
                    'message' => 'User not found'
                ], 404);
            }
 
        $webuserIds = WebUser::where('admin_user_id', $webUser->admin_user_id)->pluck('id');
        $today = Carbon::today();
        $interviewsToday = Candidate::whereDate('interview_date', $today)->whereIn('web_user_id', $webuserIds)->get();
        $technicalCompleted = Candidate::whereNotNull('technical_status')->whereIn('web_user_id', $webuserIds)->get();
        $finalRoundCompleted = Candidate::whereNotNull('hr_status')->whereIn('web_user_id', $webuserIds)->get();
        $notScheduled = Candidate::whereNull('interview_date')->whereIn('web_user_id', $webuserIds)->get();
        $attendedRounds = Candidate::whereIn('web_user_id', $webuserIds)->where(function ($query) {
            $query->whereNotNull('L1')
                ->orWhereNotNull('L2')
                ->orWhereNotNull('L3')
                ->orWhereNotNull('technical_status')
                ->orWhereNotNull('hr_status');
        })->get();

        return response()->json([
            'status' => 'Success',
            'message' => 'Interview Status data fetched successfully',
            'data' => [
                'total_interview_today' => $interviewsToday->count(),
                'interview_today_list' => $interviewsToday,
                'total_technical_completed' => $technicalCompleted->count(),
                'technical_completed_list' => $technicalCompleted,
                'total_final_completed' => $finalRoundCompleted->count(),
                'final_completed_list' => $finalRoundCompleted,
                'total_not_scheduled' => $notScheduled->count(),
                'not_scheduled_list' => $notScheduled,
                'candidates' => $attendedRounds
            ]
        ], 200);
    }
}
