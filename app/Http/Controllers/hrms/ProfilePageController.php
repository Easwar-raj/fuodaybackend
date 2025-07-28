<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use App\Models\EmployeeDetails;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Skills;
use App\Models\Experience;
use App\Models\Education;
use App\Models\Onboarding;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfilePageController extends Controller
{
    public function getProfile($id)
    {
        $employee = DB::table('employee_details')
            ->join('web_users', 'web_users.id', '=', 'employee_details.web_user_id')
            ->where('employee_details.web_user_id', $id)
            ->select([
                'employee_details.place',
                'employee_details.designation',
                'employee_details.department',
                'employee_details.employment_type',
                'employee_details.reporting_manager_id',
                'employee_details.reporting_manager_name',
                'employee_details.about',
                'employee_details.dob',
                'employee_details.address',
                'employee_details.date_of_joining',
                'employee_details.profile_photo',
                'web_users.name',
                'web_users.email',
                'employee_details.personal_contact_no',
                'web_users.emp_id',
                DB::raw("(
                    SELECT JSON_ARRAYAGG(
                        JSON_OBJECT('company_name', company_name, 'role', role, 'duration', duration, 'no_of_yrs', no_of_yrs, 'responsibilities', responsibilities, 'achievements', achievements)
                    )
                    FROM experiences
                    WHERE experiences.web_user_id = employee_details.web_user_id
                ) AS experiences"),
                DB::raw("(
                    SELECT JSON_ARRAYAGG(
                        JSON_OBJECT('id', id, 'skill', skill, 'level', level)
                    )
                    FROM skills
                    WHERE skills.web_user_id = employee_details.web_user_id
                ) AS skills"),
                DB::raw("(
                    SELECT JSON_ARRAYAGG(
                        JSON_OBJECT('qualification', qualification, 'university', university, 'year_of_passing', year_of_passing)
                    )
                    FROM education
                    WHERE education.web_user_id = employee_details.web_user_id
                ) AS education"),
                DB::raw("(
                    SELECT JSON_ARRAYAGG(
                        JSON_OBJECT('welcome_email_sent', welcome_email_sent, 'scheduled_date', scheduled_date, 'photo', photo, 'pan', pan, 'passbook', passbook, 'payslip', payslip, 'offer_letter', offer_letter)
                    )
                    FROM onboardings
                    WHERE onboardings.web_user_id = employee_details.web_user_id
                ) AS onboardings"),
                DB::raw("(
                    SELECT JSON_OBJECT(
                        'total', total,
                        'selected', selected,
                        'onboarded', onboarded
                    )
                    FROM (
                        SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN hiring_status = 'selected' THEN 1 ELSE 0 END) AS selected,
                        SUM(CASE WHEN hiring_status = 'onboarded' THEN 1 ELSE 0 END) AS onboarded
                        FROM candidates
                        WHERE candidates.referred_by = employee_details.web_user_id
                    ) AS summary
                    ) AS referred_summary")
            ])
            ->first();

        if (!$employee) {
            return response()->json([
                'message' => 'No data found for the given employee',
                'status' => 'error',
                'data' => []
            ], 404);
            // Decode JSON columns to arrays

        }

        $employee->experiences = json_decode($employee->experiences, true) ?? [];
        $employee->skills = json_decode($employee->skills, true) ?? [];
        $employee->education = json_decode($employee->education, true) ?? [];
        $employee->onboardings = json_decode($employee->onboardings, true) ?? [];
        $employee->referred_summary = json_decode($employee->referred_summary, true) ?? [];

        return response()->json([
            'message' => 'Employee data retrieved successfully',
            'status' => 'Success',
            'data' => $employee
        ], 200);
    }

    public function updateEmployeeProfile(Request $request)
    {
        // Step 1: Validate incoming data
        $validated = $request->validate([
            'web_user_id' => 'required|exists:web_users,id',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'about' => 'nullable|string',
            'dob' => 'nullable|date',
            'address' => 'nullable|string|max:255',
        ]);

        // Step 2: Update the name in web_users table
        $webUser = WebUser::find($request->web_user_id);

        if ($request->first_name || $request->last_name) {
            $webUser->name = "{$request->first_name} {$request->last_name}" ?? $webUser->name;
            $webUser->save();
        }

        // Step 3: Update employee_details (assuming one-to-one relationship)
        $employeeDetail = EmployeeDetails::where('web_user_id', $request->web_user_id)->first();

        if (!$employeeDetail) {
            return response()->json([
                'message' => 'Employee details not found.'
            ], 404);
        }

        $employeeDetail->about = $request->about ?? $employeeDetail->about;
        $employeeDetail->dob = $request->dob ?? $employeeDetail->dob;
        $employeeDetail->address = $request->address ?? $employeeDetail->address;
        $employeeDetail->save();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'status' => 'Success'
        ], 200);
    }

    public function updateOrCreateSkill(Request $request)
    {
        // Validate input
        $validated = $request->validate([
            'web_user_id' => 'required|exists:web_users,id',
            'skill' => 'required|string',
            'level' => 'required|string',
        ]);

        $webUser = WebUser::find($request->web_user_id);

        if(!$validated || !$webUser) {
            return response()->json([
                'message' => 'Invalid data provided.'
            ], 400);
        }

        // Update the skill level if it exists, otherwise create a new record
        $skill = Skills::updateOrCreate(
            [
                'web_user_id' => $request->web_user_id,
                'skill' => $request->skill,
            ],
            [
                'level' => $request->level,
                'emp_name' => $webUser->name ?? null,
                'emp_id' => $webUser->emp_id ?? null,
            ]
        );

        return response()->json([
            'message' => 'Skill updated or created successfully.',
            'status' => 'Success',
        ], 200);
    }

    public function deleteSkill(Request $request)
    {

        $validated = $request->validate([
            'web_user_id' => 'required|exists:web_users,id',
            'id' => 'required|exists:skills,id',
        ]);

        $webUser = WebUser::find($request->web_user_id);

        if (!$webUser || !$validated) {
            return response()->json(['message' => 'Invalid details'], 400);
        }

        $skill = Skills::find($request->id);

        if (!$skill) {
            return response()->json(['message' => 'Skill not found', 'status' => 'error'], 404);
        }

        $skill->delete();

        return response()->json(['message' => 'Skill deleted successfully']);
    }

    public function updateOrCreateEducation(Request $request)
    {
        // Validate input
        $validated = $request->validate([
            'web_user_id' => 'required|exists:web_users,id',
            'qualification' => 'required|string',
            'university' => 'required|string',
            'year_of_passing' => 'required|string',
        ]);

        $webUser = WebUser::find($request->web_user_id);

        if(!$validated || !$webUser) {
            return response()->json([
                'message' => 'Invalid data provided.'
            ], 400);
        }

        // Update existing education or insert a new one
        $education = Education::updateOrCreate(
            [
                'web_user_id' => $request->web_user_id,
                'qualification' => $request->qualification,
            ],
            [
                'university' => $request->university,
                'year_of_passing' => $request->year_of_passing,
                'emp_name' => $webUser->name ?? null,
                'emp_id' => $webUser->emp_id ?? null,
            ]
        );

        return response()->json([
            'message' => 'Education record updated or created successfully.',
            'status' => 'Success',
        ], 200);
    }

    public function deleteEducation(Request $request)
    {

        $validated = $request->validate([
            'web_user_id' => 'required|exists:web_users,id',
            'id' => 'required|exists:education,id',
        ]);

        $webUser = WebUser::find($request->web_user_id);

        if (!$webUser || !$validated) {
            return response()->json(['message' => 'Invalid details'], 400);
        }

        $education = Education::find($request->id);

        if (!$education) {
            return response()->json(['message' => 'Education not found', 'status' => 'error'], 404);
        }

        $education->delete();

        return response()->json(['message' => 'Education deleted successfully']);
    }

    public function updateOrCreateExperience(Request $request)
    {
        $validated = $request->validate([
            'web_user_id'      => 'required|exists:web_users,id',
            'company_name'     => 'required|string',
            'no_of_yrs'        => 'required|string',
            'role'             => 'required|string',
            'duration'         => 'required|string',
            'responsibilities' => 'required|string',
            'achievements'     => 'nullable|date',
            'emp_name'         => 'nullable|string',
            'emp_id'           => 'nullable|string',
        ]);

        $webUser = WebUser::find($request->web_user_id);

        if(!$validated || !$webUser) {
            return response()->json([
                'message' => 'Invalid data provided.'
            ], 400);
        }

        $experience = Experience::updateOrCreate(
            [
                'web_user_id'  => $request->web_user_id,
                'company_name' => $request->company_name,
                'role'         => $request->role,
            ],
            [
                'no_of_yrs'        => $request->no_of_yrs,
                'role'             => $request->role,
                'duration'         => $request->duration,
                'responsibilities' => $request->responsibilities,
                'achievements'     => $request->achievements,
                'emp_name'         => $webUser->name,
                'emp_id'           => $webUser->emp_id,
            ]
        );

        return response()->json([
            'message' => 'Experience record updated or created successfully.',
            'status'  => 'Success',
        ], 200);
    }

    public function deleteExperience(Request $request)
    {

        $validated = $request->validate([
            'web_user_id' => 'required|exists:web_users,id',
            'id' => 'required|exists:experiences,id',
        ]);

        $webUser = WebUser::find($request->web_user_id);

        if (!$webUser || !$validated) {
            return response()->json(['message' => 'Invalid details'], 400);
        }

        $experience = Experience::find($request->id);

        if (!$experience) {
            return response()->json(['message' => 'Experience not found', 'status' => 'error'], 404);
        }

        $experience->delete();

        return response()->json(['message' => 'Experience deleted successfully']);
    }

    public function updateOrCreateOnboarding(Request $request)
    {
        $validated = $request->validate([
            'web_user_id'         => 'required|exists:web_users,id',
            'welcome_email_sent'  => 'nullable|date',
            'scheduled_date'      => 'nullable|date',
            'photo'               => 'nullable|mimes:jpeg,png,jpg,pdf|max:5048',
            'pan'                 => 'nullable|mimes:jpeg,png,jpg,pdf|max:5048',
            'passbook'            => 'nullable|mimes:jpeg,png,jpg,pdf|max:5048',
            'payslip'             => 'nullable|mimes:jpeg,png,jpg,pdf|max:5048',
            'offer_letter'        => 'nullable|mimes:jpeg,png,jpg,pdf|max:5048',
        ]);

        $webUser = WebUser::find($request->web_user_id);

        if (!$webUser || !$validated) {
            return response()->json(['message' => 'Invalid details.'], 400);
        }

        $s3 = new S3Client([
            'region' => config('filesystems.disks.s3.region'),
            'version' => 'latest',
            'credentials' => [
                'key'    => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
        ]);

        $bucket = config('filesystems.disks.s3.bucket');

        $uploadFields = ['photo', 'pan', 'passbook', 'payslip', 'offer_letter'];
        $uploadResults = [];

        $existingOnboarding = Onboarding::where('web_user_id', $request->web_user_id)->first();
        $existingData = $existingOnboarding ? $existingOnboarding->toArray() : [];

        foreach ($uploadFields as $field) {
            if ($request->hasFile($field)) {
                $file = $request->file($field);
                $extension = $file->getClientOriginalExtension();
                $fileName = "onboarding/{$webUser->id}/{$field}." . $extension;

                // Delete any existing file
                $existingFiles = Storage::disk('s3')->files("onboarding/{$webUser->id}");
                foreach ($existingFiles as $existingFile) {
                    if (str_contains($existingFile, $field)) {
                        Storage::disk('s3')->delete($existingFile);
                    }
                }

                // Upload new file
                $s3->putObject([
                    'Bucket' => $bucket,
                    'Key'    => $fileName,
                    'Body'   => $file->get(),
                    'ContentType' => $file->getMimeType(),
                ]);

                $uploadResults[$field] = $s3->getObjectUrl($bucket, $fileName);
            } else {
                // Preserve existing value or set as 'not uploaded'
                $uploadResults[$field] = $existingData[$field] ?? 'not uploaded';
            }
        }

        Onboarding::updateOrCreate(
            [
                'web_user_id' => $request->web_user_id,
                'emp_name' => $webUser->name,
                'emp_id' => $webUser->emp_id
            ],
            [
                'welcome_email_sent' => $request->welcome_email_sent ?? ($existingData['welcome_email_sent'] ?? null),
                'scheduled_date'     => $request->scheduled_date ?? ($existingData['scheduled_date'] ?? null),
                'photo'              => $uploadResults['photo'],
                'pan'                => $uploadResults['pan'],
                'passbook'           => $uploadResults['passbook'],
                'payslip'            => $uploadResults['payslip'],
                'offer_letter'       => $uploadResults['offer_letter'],
            ]
        );

        return response()->json([
            'message'        => 'Onboarding record updated or created successfully.',
            'status'         => 'Success',
            'upload_status'  => $uploadResults
        ], 200);
    }
}
