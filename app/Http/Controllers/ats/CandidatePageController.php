<?php

namespace App\Http\Controllers\ats;
use App\Http\Controllers\Controller;
use App\Models\Candidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class CandidatePageController extends Controller
{
    public function getCandidates(Request $request)
    {
        try {
            $query = Candidate::with(['details:id,candidate_id,nationality'])
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
            $applied = Candidate::where('hiring_status', 'Applied')->count();
            $shortlisted = Candidate::where('hiring_status', 'Shortlisted')->count();
            $holded = Candidate::where('hiring_status', 'Holded')->count();
            $rejected = Candidate::where('hiring_status', 'Rejected')->count();

            return response()->json([
                'candidates' => $candidates,
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
            Candidate::create($validated);
            return response()->json([
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