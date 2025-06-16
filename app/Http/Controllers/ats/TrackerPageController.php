<?php

namespace App\Http\Controllers\ats;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Candidate;
use App\Models\WebUser;

class TrackerPageController extends Controller
{
    public function getTrackerData(Request $request)
    {
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

        // Card metrics
        $resumeDownloaded = Candidate::whereNotNull('resume')->count();
        $above80 = Candidate::where('ats_score', '>=', 80)->count();
        $between50_80 = Candidate::whereBetween('ats_score', [50, 79])->count();
        $below40 = Candidate::where('ats_score', '<', 40)->count();

        return response()->json([
            'status' => true,
            'message' => 'Tracker data fetched successfully',
            'cards' => [
                'resume_downloaded' => $resumeDownloaded,
                'above_80_score' => $above80,
                'between_50_80_score' => $between50_80,
                'below_40_score' => $below40,
            ],
            'candidates' => $candidates,
        ]);
    }
}
