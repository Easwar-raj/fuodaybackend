<?php
 
namespace App\Http\Controllers\ats;
 
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Candidate;
use App\Models\CandidateDetails;
use App\Models\WebUser;
use Aws\S3\S3Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CandidatePageController extends Controller
{
    public function getCandidates(Request $request)
    {
        try {
            $webUser = WebUser::find($request->web_user_id);
 
            if (!$webUser) {
                return response()->json([
                    'error' => 'Invalid web_user_id',
                    'message' => 'User not found'
                ], 404);
            }
 
            $webuserIds = WebUser::where('admin_user_id', $webUser->admin_user_id)->pluck('id');
 
            $query = Candidate::with(['details:id,candidate_id,place'])
                ->whereIn('web_user_id', $webuserIds)
                ->select('id', 'name as name', 'experience', 'role', 'ats_score');
 
            // Apply filters
            if ($request->filled('role')) {
                $query->where('role', $request->role);
            }
            if ($request->filled('l1')) {
                $query->where('L1', $request->l1);
            }
            if ($request->filled('l2')) {
                $query->where('L2', $request->l2);
            }
            if ($request->filled('l3')) {
                $query->where('L3', $request->l3);
            }
 
            if ($request->filled('experience')) {
                $query->where('experience', $request->experience);
            }
 
            if ($request->filled('name')) {
                $query->where('name', 'LIKE', "%{$request->name}%");
            }
 
            if ($request->filled('ats_score')) {
                $query->where('ats_score', $request->ats_score);
            }
 
            if ($request->filled('location')) {
                $query->whereHas('details', function ($q) use ($request) {
                    $q->where('place', 'LIKE', "%{$request->location}%");
                });
            }
 
            $candidates = $query->get()->map(function ($candidate) {
                return [
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                    'experience' => $candidate->experience,
                    'role' => $candidate->role,
                    'l1' => $candidate->L1,
                    'l2' => $candidate->L2,
                    'l3' => $candidate->L3,
                    'ats_score' => $candidate->ats_score,
                    'location' => optional($candidate->details)->place,
                ];
            });
 
            return response()->json([
                'candidates' => $candidates,
                'names' => $candidates->pluck('name')->unique()->values(),
                'roles' => $candidates->pluck('role')->unique()->values(),
                'ats_scores' => $candidates->pluck('ats_score')->unique()->values(),
                'experiences' => $candidates->pluck('experience')->unique()->values(),
                'locations' => $candidates->pluck('details.place')->filter()->unique()->values(),
                'applied' => Candidate::whereIn('web_user_id', $webuserIds)->where('hiring_status', 'Applied')->count(),
                'shortlisted' => Candidate::whereIn('web_user_id', $webuserIds)->where('hiring_status', 'Shortlisted')->count(),
                'holded' => Candidate::whereIn('web_user_id', $webuserIds)->where('hiring_status', 'Holded')->count(),
                'rejected' => Candidate::whereIn('web_user_id', $webuserIds)->where('hiring_status', 'Rejected')->count(),
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
                'web_user_id' => 'required|integer|exists:web_users,id',
                'name' => 'required|string|max:255',
                'email' => 'nullable|string|max:255',
                'contact' => 'nullable|string|max:255',
                'location' => 'nullable|string|max:255',
                'dob' => 'nullable|date_format:Y-m-d',
                'job_id' => 'nullable|string|max:255',
                'designation' => 'nullable|string|max:255',
                'department' => 'nullable|string|max:255',
                'experience' => 'required|string|max:100',
                'employment_status' => 'required|string|max:255',
                'job_title' => 'nullable|string|max:255',
                'nationality' => 'nullable|string|max:255',
                'current_job_title' => 'nullable|string|max:255',
                'current_employer' => 'nullable|string|max:255',
                'linkedin' => 'nullable|string|max:255',
                'interview_date' => 'nullable|date',
                'role' => 'required|string|max:255',
                'resume' => 'nullable|file|mimes:pdf,doc,docx|max:5048',
                'feedback' => 'nullable|string',
                'hiring_status' => 'nullable|string',
                'referred_by' => 'nullable|string|max:255',
                'job_description' => 'nullable|string'
            ]);

             if (!$validated) {
                return response()->json([
                    'message' => 'Invalid data'
                ], 400);
            }
 
            $webUser = WebUser::find($validated['web_user_id']);
            $adminUser = AdminUser::find($webUser->admin_user_id);
            $resumeFile = $request->file('resume');

            if ($resumeFile) {
                $resumeExtension = $resumeFile->getClientOriginalExtension();

                // S3 path format: CompanyName/resumes/CandidateName.extension
                $folderPath = "{$adminUser->company_name}/resumes/";
                $fileName = "{$request->name}.{$resumeExtension}";
                $key = $folderPath . $fileName;

                // Delete existing resumes with the same name but any extension
                $existingFiles = Storage::disk('s3')->files($folderPath);

                foreach ($existingFiles as $existingFile) {
                    if (basename($existingFile, '.' . pathinfo($existingFile, PATHINFO_EXTENSION)) == $request->name) {
                        Storage::disk('s3')->delete($existingFile);
                    }
                }

                // Use S3Client to upload
                $s3 = new S3Client([
                    'region'  => env('AWS_DEFAULT_REGION'),
                    'version' => 'latest',
                    'credentials' => [
                        'key'    => env('AWS_ACCESS_KEY_ID'),
                        'secret' => env('AWS_SECRET_ACCESS_KEY'),
                    ],
                ]);

                $bucket = env('AWS_BUCKET');

                $s3->putObject([
                    'Bucket' => $bucket,
                    'Key'    => $key,
                    'Body'   => $resumeFile->get(),
                    'ContentType' => $resumeFile->getClientMimeType(),
                ]);

                // Generate public URL
                $resumeUrl = $s3->getObjectUrl($bucket, $key);
            }

            $atsScore = null;

            if ($resumeFile && !empty($validated['job_description'])) {
                try {
                    // Replace with your actual ATS API endpoint
                    $atsApiUrl = 'https://ai.fuoday.com/api/ats-score'; // e.g. env('ATS_API_URL')
                    
                    // Use URL and job description to send to ATS scoring API
                    $response = Http::post($atsApiUrl, [
                        'resume' => $request->resume,
                        'job_description' => $validated['job_description'],
                    ]);

                    if ($response->successful()) {
                        $atsScore = $response->json('Score'); // or whatever key the API returns
                    }
                } catch (\Exception $e) {
                    // Optional: Log error but don’t stop the candidate creation
                    Log::error('ATS Score API failed: ' . $e->getMessage());
                }
            }
 
            $newCandidate = Candidate::create([
                'web_user_id' => $validated['web_user_id'],
                'emp_name' => $webUser->name ?? '',
                'emp_id' => $webUser->emp_id ?? '',
                'name' => $validated['name'],
                'contact' => $validated['contact'] ?? '',
                'experience' => $validated['experience'],
                'interview_date' => $validated['interview_date'] ?? null,
                'role' => $validated['role'],
                'resume' => $resumeUrl ?? '',
                'feedback' => $validated['feedback'] ?? '',
                'hiring_status' => $validated['hiring_status'] ?? '',
                'referred_by' => $validated['referred_by'] ?? '',
                'ats_score' => $atsScore ?? ''
            ]);

            if ($newCandidate) {
                $addedCandidate = Candidate::where('name', $request->name)->first();
                CandidateDetails::create([
                    'candidate_id' => $addedCandidate->id,
                    'place' => $validated['location'] ?? '',
                    'phone' => $validated['contact'] ?? '',
                    'email' => $validated['email'] ?? '',
                    'dob' => $validated['dob'] ?? '',
                    'job_id' => $validated['job_id'] ?? '',
                    'designation' => $validated['designation'] ?? '',
                    'department' => $validated['department'] ?? '',
                    'employment_status' => $validated['employement_status'] ?? '',
                    'job_title' => $validated['job_title'] ?? '',
                    'nationality' => $validated['nationality'] ?? '',
                ]);
            }
 
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

    public function updateCandidate(Request $request, $id)
    {
        try {
            $candidate = Candidate::with('details')->findOrFail($id);

            $validated = $request->validate([
                // Candidate table fields
                'name' => 'sometimes|string|max:255',
                'experience' => 'sometimes|string|max:100',
                'interview_date' => 'sometimes|nullable|date',
                'role' => 'sometimes|string|max:255',
                'L1' => 'sometimes|nullable|string|max:50',
                'L2' => 'sometimes|nullable|string|max:50',
                'L3' => 'sometimes|nullable|string|max:50',
                'ats_score' => 'sometimes|nullable|numeric',
                'overall_score' => 'sometimes|nullable|numeric',
                'technical_status' => 'sometimes|nullable|string',
                'technical_feedback' => 'sometimes|nullable|string',
                'hr_status' => 'sometimes|nullable|string',
                'hr_feedback' => 'sometimes|nullable|string',
                'contact' => 'sometimes|nullable|string|max:20',
                'resume' => 'sometimes|nullable|string',
                'feedback' => 'sometimes|nullable|string',
                'hiring_status' => 'sometimes|nullable|string',
                'referred_by' => 'sometimes|nullable|string|max:255',
                // Candidate details table fields
                'place' => 'sometimes|string|max:255',
                'phone' => 'sometimes|string|max:255',
                'email' => 'sometimes|nullable|string|max:255',
                'dob' => 'sometimes|nullable|date',
                'job_id' => 'sometimes|nullable|string|max:255',
                'designation' => 'sometimes|string|max:255',
                'department' => 'sometimes|string|max:255',
                'employment_status' => 'sometimes|string|max:255',
                'job_title' => 'sometimes|string|max:255',
                'nationality' => 'sometimes|string|max:255',
                'expected_ctc' => 'sometimes|nullable|string|max:255',
                'address' => 'sometimes|nullable|string|max:255',
                'education' => 'sometimes|nullable|string|max:255',
                'certifications' => 'sometimes|nullable|string|max:255',
                'skillset' => 'sometimes|nullable|string|max:255',
                'current_job_title' => 'sometimes|nullable|string|max:255',
                'current_employer' => 'sometimes|nullable|string|max:255',
                'linkedin' => 'sometimes|nullable|string|max:255',
                'interview_date' => 'sometimes|nullable|date',
            ]);

            // Update candidate fields
            foreach ($validated as $key => $value) {
                if (in_array($key, [
                    'name', 'experience', 'interview_date', 'role', 'L1', 'L2', 'L3',
                    'ats_score', 'overall_score', 'technical_status', 'technical_feedback',
                    'hr_status', 'hr_feedback', 'contact', 'resume', 'feedback',
                    'hiring_status', 'referred_by'
                ]) && ($request->filled($key) || $request->has($key))) {
                    $candidate->$key = $value;
                }
            }

            $candidate->save();

            // Handle candidate_details
            $detailsData = collect($validated)->only([
                'place', 'phone', 'email', 'dob', 'job_id', 'designation',
                'department', 'employment_status', 'job_title', 'nationality',
                'expected_ctc', 'address', 'education', 'certifications',
                'skillset', 'experience', 'current_job_title', 'current_employer',
                'linkedin', 'interview_date_details'
            ])->toArray();

            if (!empty($detailsData)) {
                // Rename interview_date_details to interview_date if present
                if (isset($detailsData['interview_date_details'])) {
                    $detailsData['interview_date'] = $detailsData['interview_date_details'];
                    unset($detailsData['interview_date_details']);
                }

                // Update or create candidate_details
                if ($candidate->details) {
                    $candidate->details->update($detailsData);
                } else {
                    $candidate->details()->create($detailsData);
                }
            }

            return response()->json([
                'status' => 'Success',
                'message' => 'Candidate and details updated successfully',
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Candidate not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Something went wrong',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}