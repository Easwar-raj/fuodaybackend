<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use App\Models\EmployeeDetails;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                        JSON_OBJECT('skill', skill, 'level', level)
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
            'personal_email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
        ]);

        // Step 2: Update the name in web_users table
        $webUser = WebUser::find($request->web_user_id);
        $webUser->name = "{$request->first_name} {$request->last_name}" ?? $webUser->name;
        $webUser->save();

        // Step 3: Update employee_details (assuming one-to-one relationship)
        $employeeDetail = EmployeeDetails::where('web_user_id', $request->web_user_id)->first();

        if (!$employeeDetail) {
            return response()->json([
                'message' => 'Employee details not found.'
            ], 404);
        }

        $employeeDetail->about = $request->about ?? $employeeDetail->about;
        $employeeDetail->dob = $request->dob ?? $employeeDetail->dob;
        $employeeDetail->personal_email = $request->personal_email ?? $employeeDetail->personal_email;
        $employeeDetail->address = $request->address ?? $employeeDetail->address;
        $employeeDetail->save();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $webUser,
            'employee_details' => $employeeDetail
        ], 200);
    }

}
