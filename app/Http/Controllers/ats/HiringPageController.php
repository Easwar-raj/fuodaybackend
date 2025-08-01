<?php

namespace App\Http\Controllers\ats;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\JobOpening;
use App\Models\WebUser;
use Carbon\Carbon;

class HiringPageController extends Controller
{
    public function getInterviewOverview($id)
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
        $availablePositions = JobOpening::where('admin_user_id', $webUser->admin_user_id)->whereIn('status', ['Open'])->select('id', 'title', 'position', 'no_of_openings', 'company_name', 'status')->get();
        $interviewsToday = Candidate::where('web_user_id', $id)->whereDate('interview_date', $today)->pluck('name');
        $techDoneHrNotStarted = Candidate::with('details')
            ->where('web_user_id', $id)
            ->where('technical_status', 'Selected')
            ->whereNull('hr_status')
            ->get();

        return response()->json([
            'status' => 'Success',
            'message' => 'Hiring Overview data fetched successfully',
            'data' => [
                'total_available_positions' => $availablePositions->count(),
                'available_positions_list' => $availablePositions,
                'interviews_today' => $interviewsToday,
                'candidates' => $techDoneHrNotStarted
            ],
        ]);
    }

    public function getCandidateWithDetails($id)
    {
        $candidate = Candidate::with('details')->find($id);

        if (!$candidate) {
            return response()->json([
                'status' => 'error',
                'message' => 'Candidate not found',
            ], 404);
        }

        return response()->json([
            'status' => 'Success',
            'message' => 'Candidate data fetched successfully',
            'data' => $candidate
        ]);
    }

    public function getOnboarding($id)
    {
        $webUser = WebUser::find($id);
 
            if (!$webUser) {
                return response()->json([
                    'error' => 'Invalid web_user_id',
                    'message' => 'User not found'
                ], 404);
            }
 
        $webuserIds = WebUser::where('admin_user_id', $webUser->admin_user_id)->pluck('id');
        $hrCompleted = Candidate::whereNotNull('hr_status')->where('web_user_id', $id)->get();
        $selectedCount = Candidate::where('hiring_status', 'Selected')->where('web_user_id', $id)->count();

        return response()->json([
            'status' => 'Success',
            'message' => 'Hiring Overview data fetched successfully',
            'data' => [
                'candidates' => $hrCompleted,
                'total_awaiting_employment' => $selectedCount,
                'total_employees' => $webuserIds->count()
            ],
        ]);
    }
}
