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
        $query = Candidate::with('details')->where('web_user_id', $id);

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
            $atsScore = strtolower($request->ats_score);

            if (str_contains($atsScore, 'above')) {
                // e.g. "above 80"
                preg_match('/\d+/', $atsScore, $matches);
                if ($matches) {
                    $query->where('ats_score', '>', (int) $matches[0]);
                }

            } elseif (str_contains($atsScore, 'below')) {
                // e.g. "below 50"
                preg_match('/\d+/', $atsScore, $matches);
                if ($matches) {
                    $query->where('ats_score', '<', (int) $matches[0]);
                }

            } elseif (str_contains($atsScore, '-')) {
                // e.g. "60-80"
                $range = explode('-', $atsScore);
                if (count($range) === 2) {
                    $min = (int) trim($range[0]);
                    $max = (int) trim($range[1]);
                    $query->whereBetween('ats_score', [$min, $max]);
                }
            } else {
                // Fallback for exact match (e.g. just "75")
                if (is_numeric($atsScore)) {
                    $query->where('ats_score', '=', (int) $atsScore);
                }
            }
        } else {
            $query->where('ats_score', '>=', 50);
        }

        if ($request->filled('location')) {
            $query->whereHas('details', function ($q) use ($request) { $q->where('nationality', 'LIKE', "%{$request->location}%");});
        }

        $candidates = $query->orderBy('ats_score', 'desc')->get();

        $resumeDownloadedList = Candidate::where('web_user_id', $id)->whereNotNull('resume')->orderBy('ats_score', 'desc')->get();
        $above80List = Candidate::where('web_user_id', $id)->where('ats_score', '>=', 80)->orderBy('ats_score', 'desc')->get();
        $between50_80List = Candidate::where('web_user_id', $id)->whereBetween('ats_score', [50, 79])->orderBy('ats_score', 'desc')->get();
        $below40List = Candidate::where('web_user_id', $id)->where('ats_score', '<', 40)->orderBy('ats_score', 'desc')->get();

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
        $today = Carbon::today();
        $interviewsToday = Candidate::whereDate('interview_date', $today)->where('web_user_id', $id)->get();
        $technicalCompleted = Candidate::whereNotNull('technical_status')->where('web_user_id', $id)->get();
        $finalRoundCompleted = Candidate::whereNotNull('hr_status')->where('web_user_id', $id)->get();
        $notScheduled = Candidate::whereNull('interview_date')->where('web_user_id', $id)->get();
        $attendedRounds = Candidate::where('web_user_id', $id)->whereNotNull('L1')->whereNull('hr_status')->orderBy('interview_date', 'desc')->get();

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
