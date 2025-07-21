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
        $interviewsToday = Candidate::whereIn('web_user_id', $webuserIds)->whereDate('interview_date', $today)->pluck('name');
        $techDoneHrNotStarted = Candidate::with(['details:id,candidate_id,phone'])
            ->whereIn('web_user_id', $webuserIds)
            ->whereNotNull('technical_status')
            ->whereNull('hr_status')
            ->select(
                'id',
                'name',
                'role',
                'contact',
                'technical_status',
                'ats_score',
                'overall_score',
                'technical_feedback'
            )
            ->get()
            ->map(function ($candidate) {
                return [
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                    'role' => $candidate->role,
                    'contact' => $candidate->contact,
                    'ats_score' => $candidate->ats_score,
                    'overall_score' => $candidate->overall_score,
                    'technical_status' => $candidate->technical_status,
                    'technical_feedback' => $candidate->technical_feedback,
                    'phone' => optional($candidate->details)->phone,
                ];
            });

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
        $hrCompleted = Candidate::whereNotNull('hr_status')->whereIn('web_user_id', $webuserIds)->select('id', 'name', 'role', 'hr_status', 'hr_feedback', 'hiring_status')->get();
        $selectedCount = Candidate::where('hiring_status', 'Selected')->whereIn('web_user_id', $webuserIds)->count();

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
