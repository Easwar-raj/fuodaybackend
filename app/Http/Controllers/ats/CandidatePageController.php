<?php

namespace App\Http\Controllers\ats;
use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\WebUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class CandidatePageController extends Controller
{
    public function getCandidates(Request $request)
    {
        try {
            $webUser = WebUser::find($request->web_user_id);
            $webuserIds = WebUser::where('admin_user_id', $webUser->admin_user_id)->pluck('id');
            $query = Candidate::with(['details:id,candidate_id,nationality'])->whereIn('web_user_id', $webuserIds)
                ->select('id', 'emp_name as employee_name', 'experience', 'role', 'ats_score');

            // Apply filters
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

            // Filter on related model (location)
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

            // Extract separate lists
            $names = $candidates->pluck('employee_name')->unique()->values();
            $roles = $candidates->pluck('role')->unique()->values();
            $locations = $candidates->pluck('location')->filter()->unique()->values();

            // Hiring status counts
            $applied = Candidate::whereIn('web_user_id', $webuserIds)->where('hiring_status', 'Applied')->count();
            $shortlisted = Candidate::whereIn('web_user_id', $webuserIds)->where('hiring_status', 'Shortlisted')->count();
            $holded = Candidate::whereIn('web_user_id', $webuserIds)->where('hiring_status', 'Holded')->count();
            $rejected = Candidate::whereIn('web_user_id', $webuserIds)->where('hiring_status', 'Rejected')->count();

            return response()->json([
                'candidates' => $candidates,
                'names' => $names,
                'roles' => $roles,
                'locations' => $locations,
                'applied' => $applied,
                'shortlisted' => $shortlisted,
                'holded' => $holded,
                'rejected' => $rejected
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Something went wrong',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function addCandidate(Request $request)
    {
        try {
            $validated = $request->validate([
                'web_user_id' => 'nullable|integer',
                'emp_name' => 'nullable|string',
                'emp_id' => 'nullable|string',
                'name' => 'required|string|max:255',
                'experience' => 'required|string|max:100',
                'interview_date' => 'nullable|date',
                'role' => 'required|string|max:255',
                'L1' => 'nullable|string|max:50',
                'L2' => 'nullable|string|max:50',
                'L3' => 'nullable|string|max:50',
                'ats_score' => 'nullable|numeric',
                'overall_score' => 'nullable|numeric',
                'technical_status' => 'nullable|string',
                'technical_feedback' => 'nullable|string',
                'hr_status' => 'nullable|string',
                'hr_feedback' => 'nullable|string',
                'contact' => 'nullable|string|max:20',
                'resume' => 'required|string',
                'feedback' => 'nullable|string',
                'hiring_status' => 'nullable|string',
                'referred_by' => 'nullable|string|max:255',
            ]);

            if (!$validated) {
                return response()->json([
                    'message' => 'Invalid data'
                ], 400);
            }

            $webUser = WebUser::findOrFail($request->web_user_id) ?? '';
            Candidate::create([
                'web_user_id' => $request->web_user_id ?? '',
                'emp_name' => $webUser->name ?? '',
                'emp_id' => $webUser->emp_id ?? '',
                'name' => $request->name,
                'experience' => $request->experience,
                'interview_date' => $request->interview_date ?? '',
                'role' => $request->role,
                'L1' => $request->L1 ?? '',
                'L2' => $request->L2 ?? '',
                'L3' => $request->L3 ?? '',
                'ats_score' => $request->ats_score ?? 0,
                'overall_score' => $request->overall_score ?? 0,
                'technical_status' => $request->technical_status ?? '',
                'technical_feedback' => $request->technical_feedback ?? '',
                'hr_status' => $request->hr_status ?? '',
                'hr_feedback' => $request->hr_feedback ?? '',
                'contact' => $request->contact ?? '',
                'resume' => $request->resume,
                'feedback' => $request->feedback ?? '',
                'hiring_status' => $request->hiring_status ?? '',
                'referred_by' => $request->referred_by ?? '',
            ]);
            return response()->json([
                'status' => 'Success',
                'message' => 'Candidate added successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Something went wrong',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}