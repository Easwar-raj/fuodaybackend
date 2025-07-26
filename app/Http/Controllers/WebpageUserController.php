<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Attendance;
use App\Models\Audits;
use App\Models\EmployeeDetails;
use App\Models\SectionSelection;
use App\Models\Payroll;
use App\Models\Payslip;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use App\Models\WebUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Aws\S3\S3Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WebpageUserController extends Controller
{
    // Create Web User
    public function saveWebUser(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|in:create,update',
            'admin_user_id' => 'required|integer|exists:admin_users,id',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'role' => 'nullable|string|in:employee,recruiter,hr,hr_recruiter',
            'role_location' => 'nullable|string|max:255',
            'gender' => 'nullable|string|max:255',
            'designation' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'place' => 'nullable|string|max:255',
            'emp_id' => 'nullable|string',
            'password' => 'nullable|string|min:8',
            'group' => 'nullable|string|max:255',
            'blood_group' => 'nullable|string|max:255',
            'dob' => 'nullable|string|max:255',
            'personal_contact_no' => 'nullable|digits_between:10,15',
            'emergency_contact_no' => 'nullable|digits_between:10,15',
            'official_contact_no' => 'nullable|digits_between:10,15',
            'employment_type' => 'nullable|string|max:255',
            'work_module' => 'nullable|string|max:255',
            'date_of_joining' => 'nullable|date',
            'reporting_manager_id' => 'nullable|integer',
            'reporting_manager_name' => 'nullable|string|max:255',
            'ctc' => 'nullable|string|max:255',
            'actual_salary' => 'nullable|string|max:255',

            'earnings' => 'required|array',
            'earnings.*.salary_component' => 'required|string|max:255',
            'earnings.*.amount' => 'required|string',

            'deductions' => 'nullable|array',
            'deductions.*.salary_component' => 'required|string|max:255',
            'deductions.*.amount' => 'required|string',
        ]);

        $user = Auth::user();
        if ($user->id !== (int) $request->admin_user_id) {
            return response()->json([
                'message' => 'Unauthorized',
                'status' => 'error'
            ], 401);
        }

        DB::beginTransaction();

        try {
            if ($request->action === 'create') {
                $adminUser = AdminUser::find($request->admin_user_id);
                $webUserCount = WebUser::where('admin_user_id', $request->admin_user_id)->count();

                if ((int)$adminUser->allowed_users <= $webUserCount) {
                    return response()->json([
                        'message' => 'Allowed users exceeded',
                        'status' => 'error'
                    ], 401);
                }

                $exists = EmployeeDetails::where('emp_id', $request->emp_id)
                    ->whereHas('webUser', function ($query) use ($request) {
                        $query->where('admin_user_id', $request->admin_user_id);
                    })
                    ->exists();

                if ($exists) {
                    return response()->json([
                        'message' => 'The provided Employee ID already exists',
                        'status' => 'error'
                    ], 409);
                }

                $webUser = WebUser::create([
                    'admin_user_id' => $request->admin_user_id,
                    'name' => $request->first_name . ' ' . $request->last_name,
                    'role' => $request->role,
                    'emp_id' => $request->emp_id,
                    'group' => $request->group,
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                ]);

                $initials = strtoupper($request->first_name[0] ?? '') . strtoupper($request->last_name[0] ?? '');
                // $profilePhotoPath = $this->generateProfileImage($initials, $request->emp_id, $request->admin_user_id);

                EmployeeDetails::create([
                    'web_user_id' => $webUser->id,
                    'emp_name' => $webUser->name,
                    'emp_id' => $webUser->emp_id,
                    'role_location' => $request->role_location,
                    'gender' => $request->gender,
                    'personal_contact_no' => $request->personal_contact_no,
                    'emergency_contact_no' => $request->emergency_contact_no,
                    'official_contact_no' => $request->official_contact_no,
                    'designation' => $request->designation,
                    'department' => $request->department,
                    'employment_type' => $request->employment_type,
                    'blood_group' => $request->blood_group,
                    'dob' => $request->dob,
                    'work_module' => $request->work_module,
                    'date_of_joining' => $request->date_of_joining,
                    'reporting_manager_id' => $request->reporting_manager_id,
                    'reporting_manager_name' => $request->reporting_manager_name,
                    'place' => $request->place,
                    // 'profile_photo' => $profilePhotoPath,
                ]);

                // Save payroll components
                $allComponents = collect($request->earnings)->map(function ($comp) {
                    return array_merge($comp, ['type' => 'Earnings']);
                })->merge(
                    collect($request->deductions)->map(function ($comp) {
                        return array_merge($comp, ['type' => 'Deductions']);
                    })
                );

                foreach ($allComponents as $component) {
                    Payroll::create([
                        'web_user_id' => $webUser->id,
                        'emp_name' => $webUser->name,
                        'emp_id' => $webUser->emp_id,
                        'designation' => $request->designation,
                        'salary_component' => $component['salary_component'],
                        'type' => $component['type'],
                        'amount' => $component['amount'],
                        'ctc' => $request->ctc,
                        'monthly_salary' => $request->actual_salary,
                    ]);
                }

                $basic = collect($request->earnings)->firstWhere('salary_component', 'Basic')['amount'] ?? null;
                $gross = collect($request->earnings)->sum('amount');
                $total_deductions = collect($request->deductions ?? [])->sum('amount');
                $total_salary = $request->actual_salary ?? null;
                $status = 'Generated';
                $lastPayroll = Payroll::where('web_user_id', $webUser->id)->first();

                $policy = DB::table('policies')->where('admin_user_id', $request->admin_user_id)->where('title', 'salary_period')->first();
                $salaryPeriod = $policy->policy ?? '26To25';

                preg_match('/To(\d{1,2})/', $salaryPeriod, $matches);
                $endDay = isset($matches[1]) ? (int)$matches[1] : 25;

                $today = Carbon::today();
                $year = $today->year;
                $month = $today->month;
                $day = $today->day;

                if ($day <= $endDay) {
                    $endDate = Carbon::create($year, $month, $endDay);
                } else {
                    $nextMonth = $month == 12 ? 1 : $month + 1;
                    $nextYear = $month == 12 ? $year + 1 : $year;
                    $daysInNextMonth = Carbon::create($nextYear, $nextMonth, 1)->daysInMonth;
                    $validEndDay = min($endDay, $daysInNextMonth);
                    $endDate = Carbon::create($nextYear, $nextMonth, $validEndDay);
                }

                $totalPaidDays = $today->diffInDays($endDate) + 1;

                Payslip::create([
                    'payroll_id' => $lastPayroll ? $lastPayroll->id : null,
                    'date' => now()->toDateString(),
                    'time' => now()->format('H:i:s'),
                    'month' => now()->format('F'),
                    'basic' => $basic,
                    'total_paid_days' => $totalPaidDays,
                    'gross' => $gross,
                    'total_deductions' => $total_deductions,
                    'total_salary' => $total_salary,
                    'status' => $status,
                ]);

                DB::commit();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Web user created successfully.'
                ], 201);
            }

            if ($request->action === 'update') {
                $webUser = WebUser::where('admin_user_id', $request->admin_user_id)
                    ->where('email', $request->email)
                    ->first();

                if (!$webUser) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Web user not found'
                    ], 404);
                }

                $webUser->update([
                    'name' => $request->name ?? $webUser->name,
                    'role' => $request->role ?? $webUser->role,
                    'emp_id' => $request->emp_id ?? $webUser->emp_id,
                    'group' => $request->group ?? $webUser->group,
                    // 'password' => Hash::make($request->password) ?? $webUser->password,
                ]);

                $empdetails = EmployeeDetails::where('web_user_id', $webUser->id)->first();

                $webUser->employeeDetails()->update([
                    'role_location' => $request->role_location ?? $empdetails->role_location,
                    'gender' => $request->gender ?? $empdetails->gender,
                    'personal_contact_no' => $request->personal_contact_no ?? $empdetails->personal_contact_no,
                    'emergency_contact_no' => $request->emergency_contact_no ?? $empdetails->emergency_contact_no,
                    'official_contact_no' => $request->official_contact_no ?? $empdetails->official_contact_no,
                    'designation' => $request->designation ?? $empdetails->designation,
                    'department' => $request->department ?? $empdetails->department,
                    'employment_type' => $request->employment_type ?? $empdetails->employment_type,
                    'blood_group' => $request->blood_group ?? $empdetails->blood_group,
                    'dob' => $request->dob ?? $empdetails->dob,
                    'work_module' => $request->work_module ?? $empdetails->work_module,
                    'date_of_joining' => $request->date_of_joining ?? $empdetails->date_of_joining,
                    'reporting_manager_id' => $request->reporting_manager_id ?? $empdetails->reporting_manager_id,
                    'reporting_manager_name' => $request->reporting_manager_name ?? $empdetails->reporting_manager_name,
                    'place' => $request->place ?? $empdetails->place,
                ]);

                // Delete old payroll and insert updated
                Payroll::where('web_user_id', $webUser->id)->delete();

                $allComponents = collect($request->earnings)->map(function ($comp) {
                    return array_merge($comp, ['type' => 'Earnings']);
                })->merge(
                    collect($request->deductions)->map(function ($comp) {
                        return array_merge($comp, ['type' => 'Deductions']);
                    })
                );

                foreach ($allComponents as $component) {
                    Payroll::create([
                        'web_user_id' => $webUser->id,
                        'emp_name' => $webUser->name,
                        'emp_id' => $webUser->emp_id,
                        'designation' => $request->designation,
                        'salary_component' => $component['salary_component'],
                        'type' => $component['type'],
                        'amount' => $component['amount'],
                        'ctc' => $request->ctc,
                        'monthly_salary' => $request->actual_salary,
                    ]);
                }

                DB::commit();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Web user updated successfully.'
                ], 200);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Invalid action provided.'
            ], 400);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updatePayrollDetails(Request $request, $id)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|integer|exists:admin_users,id',
            'emp_name' => 'required|string|max:255',
            'emp_id' => 'required|string|max:255',
            'earnings' => 'required|array',
            'earnings.*.salary_component' => 'required|string|max:255',
            'earnings.*.amount' => 'required|numeric',
            'deductions' => 'required|array',
            'deductions.*.salary_component' => 'required|string|max:255',
            'deductions.*.amount' => 'required|numeric',
        ]);

        // Authorization check
        $user = Auth::user();
        if ($user->id !== (int) $request->admin_user_id) {
            return response()->json([
                'message' => 'Unauthorized',
                'status' => 'error'
            ], 401);
        }

        // Check web user exists
        $webUser = WebUser::where('id', $id)
            ->where('admin_user_id', $request->admin_user_id)
            ->first();

        if (!$webUser) {
            return response()->json([
                'status' => 'error',
                'message' => 'Web user not found',
            ], 404);
        }

        // Delete existing payroll components for this user
        Payroll::where('web_user_id', $id)->delete();

        // Insert earnings
        foreach ($request->earnings as $earning) {
            Payroll::create([
                'web_user_id' => $id,
                'emp_name' => $request->emp_name,
                'emp_id' => $request->emp_id,
                'designation' => $request->designation ?? null,
                'salary_component' => $earning['salary_component'],
                'type' => 'Earnings',
                'amount' => $earning['amount'],
            ]);
        }

        // Insert deductions
        foreach ($request->deductions as $deduction) {
            Payroll::create([
                'web_user_id' => $id,
                'emp_name' => $request->emp_name,
                'emp_id' => $request->emp_id,
                'designation' => $request->designation ?? null,
                'salary_component' => $deduction['salary_component'],
                'type' => 'Deductions',
                'amount' => $deduction['amount'],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Payroll components updated successfully.',
        ], 200);
    }

    // Update a web user
    public function update(Request $request, $id)
    {
        // $user = Auth::user();
        // if($user->id !== (int) $id) {
        //     return response()->json([
        //         'message' => 'Unauthorized',
        //         'status' => 'error'
        //     ], 401);
        // }

        $webUser = WebUser::find($id);

        if (!$webUser) {
            return response()->json(['status' => 'error', 'message' => 'Web user not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'string|max:20',
            'profile' => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        if (!$validated) {
            return response()->json(['status' => 'error'], 422);
        }

        $profileUrl = null;

        if ($request->hasFile('profile')) {
            $file = $request->file('profile');
            $extension = $file->getClientOriginalExtension();
            $adminUser = AdminUser::find($webUser->admin_user_id);

            $existingFiles = Storage::disk('s3')->files("{$adminUser->company_name}/profile/{$webUser->emp_id}");

            foreach ($existingFiles as $existingFile) {
                if (pathinfo($existingFile, PATHINFO_FILENAME) == $webUser->emp_id) {
                    Storage::disk('s3')->delete($existingFile);
                }
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
            $key = "{$adminUser->company_name}/profile/{$webUser->emp_id}.{$extension}";

            $s3->putObject([
                'Bucket' => $bucket,
                'Key'    => $key,
                'Body'   => $request->file('profile')->get(),
                'ContentType' => 'image/png',
            ]);

            $profileUrl = $s3->getObjectUrl($bucket, $key);
        }


        $webUser->update([
            'name' => $request->input('name', $webUser->name),
            'phone' => $request->input('phone', $webUser->phone),
        ]);

        $employeeData = EmployeeDetails::where('web_user_id', $id)->first();
        if ($employeeData) {
            $employeeData->update([
                'profile_photo' => $profileUrl ?? $employeeData->profile_photo, // fallback if no upload
                'personal_contact_no' => $request->phone ?? $employeeData->personal_contact_no
            ]);
        }

        return response()->json(['status' => 'Success', 'message' => 'Web user updated successfully.'], 200);
    }

    public function getWebUserById($id)
    {
        $webUser = WebUser::find($id);

        if (!$webUser) {
            return response()->json([
                'message' => 'User not found',
                'status' => 'error'
            ], 404);
        }

        // $user = Auth::user();
        // if ($user->id !== $request->admin_user_id) {
        //     return response()->json([
        //         'message' => 'Unauthorized',
        //         'status' => 'error'
        //     ], 401);
        // }

        $webUser = WebUser::with([
            'employeeDetails:id,web_user_id,emp_name,emp_id,profile_photo,role_location,designation,department,employment_type,work_module,date_of_joining,reporting_manager_id,reporting_manager_name',
            'payroll:id,web_user_id,emp_name,emp_id,designation,ctc,monthly_salary,salary_component,type,amount'
        ])
        ->where('id', $id)
        ->first();

        return response()->json([
            'status' => 'Success',
            'message' => 'Web user details fetched successfully.',
            'data' => $webUser
        ], 200);
    }

    // Delete a web user
    public function destroy($id)
    {
        $webUser = WebUser::with('employeeDetails')->where('id', $id)->first();

        if (!$webUser) {
            return response()->json(['status' => 'error', 'message' => 'Web user not found.'], 404);
        }

        $user = Auth::user();
        if($user->id !== $webUser->admin_user_id) {
            return response()->json([
                'message' => 'Unauthorized',
                'status' => 'error'
            ], 401);
        }

        // Optionally delete profile photo file if it's a local path
        if ($webUser->employeeDetails && $webUser->employeeDetails->profile_photo !== null && file_exists(public_path($webUser->employeeDetails->profile_photo))) {
            unlink(public_path($webUser->employeeDetails->profile_photo));
        }

        $webUser->delete();

        return response()->json(['status' => 'Success', 'message' => 'Web user deleted successfully.'], 200);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|integer|exists:admin_users,id',
            'role' => 'nullable|string|in:employee,recruiter,both'
        ]);

        if(!$validated) {
            return response()->json([
                'message', 'Invalid details',
                'status' => 'error'
            ], 401);
        }

        $user = Auth::user();
        if($user->id !== $request->admin_user_id) {
            return response()->json([
                'message' => 'Unauthorized',
                'status' => 'error'
            ], 401);
        }

        if ($request->role) {
            $webUsers = WebUser::where('admin_user_id', $request->admin_user_id)->whereIn('role', [$request->role, 'both'])->get();
        } else {
            // Retrieve all users if no role is specified
            $webUsers = WebUser::where('admin_user_id', $request->admin_user_id)->get();
        }

        return response()->json([
            'status' => 'Success',
            'message' => 'List of users based on role.',
            'data' => $webUsers,
        ], 200);
    }

    // User Login with Role Validation and Token Generation
    public function userlogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string|min:6',
            'role' => 'required|string|in:employee,recruiter,hr',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Check if the user exists
        $webUser = WebUser::where('email', $request->input('email'))
            ->with([
                'employeeDetails' => function ($query) {
                    $query->select('web_user_id', 'profile_photo', 'designation', 'department');
                },
                'adminUser:id,logo,brand_logo,company_name'
            ])
            ->first();

        if(($webUser->role === 'hr' && $request->role === 'recruiter') || ($webUser->role !== 'hr_recruiter' && $webUser->role !== 'hr'  && $request->role !== $webUser->role)) {
            return response()->json([
                'message' => 'Unauthorized',
                'status' => 'error',
            ], 403);
        }

        if (!$webUser || !Hash::check($request->input('password'), $webUser->password)) {
            return response()->json([
                'message' => 'Invalid email or password',
                'status' => 'error',
            ], 401);
        }

        $token = $webUser->createToken('UserAccessToken')->plainTextToken; // use `plainTextToken` instead of `accessToken`
        $selections = SectionSelection::where('admin_user_id', $webUser->admin_user_id)->get();
        function findFirstEmptySection($sections, $parentId = null) {
            foreach ($sections as $section) {
                if ($section->parent_id == $parentId) {
                    $hasChildren = false;

                    foreach ($sections as $child) {
                        if ($child->parent_id == $section->id) {
                            $hasChildren = true;
                            break;
                        }
                    }

                    if (!$hasChildren) {
                        return $section->section_name; // Return the first section with no children
                    }

                    $result = findFirstEmptySection($sections, $section->id);
                    if ($result !== null) {
                        return $result;
                    }
                }
            }

            return null;
        }
        $neededSection = (!empty($selections) && isset($selections[0]) && $selections[0]->section_name !== 'all') ? findFirstEmptySection($selections) : 'my_zone';

        $companyWord = null;
        if ($webUser->adminUser && $webUser->adminUser->company_name) {
            $companyWord = strtolower(strtok($webUser->adminUser->company_name, ' '));
        }
        $today = Carbon::today()->toDateString();
        $checkin = Attendance::where('web_user_id', $webUser->id)->where('date', $today)->orderBy('created_at', 'asc')->first();
        if ($webUser && $webUser->adminUser) {
            $chatLogo = "https://fuoday-s3-bucket.s3.ap-south-1.amazonaws.com/Fuoday_logo_F.png";
            $webUser->adminUser->chat_logo = $chatLogo;
            $webUser->adminUser->company_word = $companyWord;
            $webUser->checkin = $checkin ? $checkin->checkin : null;
            if ($companyWord !== 'ar') {
                $webUser->adminUser->logo = 'https://fuoday-s3-bucket.s3.ap-south-1.amazonaws.com/Fuoday_logo.png'; // new logo for admin ID 1
            }
        }

        return response()->json([
            'status' => 'Success',
            'message' => 'Login successful',
            'data' => $webUser,
            'token' => $token,
        ], 200);
    }
    // Logout with Sanctum
    public function logout(Request $request)
    {
        // Ensure the user is authenticated
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid token or unauthorized access.',
            ], 401);
        }
        // Revoke all tokens for the user
        $user->tokens()->delete();

        return response()->json([
            'status' => 'Success',
            'message' => 'Logout successful. All tokens have been revoked.',
        ], 200);
    }

    // New function to get all users
    public function getAllUsers()
    {
        // Retrieve all users from the database
        $webUsers = WebUser::all();

        return response()->json([
            'status' => 'Success',
            'message' => 'List of all created users.',
            'data' => $webUsers,
        ], 200);
    }

    public function getEmployeesGroupedByManager($id)
    {
        // Step 1: Get the admin_user_id for the given web_user_id
        $webUser = WebUser::findOrFail($id);
        $adminUserId = $webUser->admin_user_id;

        // Step 2: Get all employees under the same admin_user_id
        $employees = EmployeeDetails::whereHas('webUser', function ($query) use ($adminUserId) {
                $query->where('admin_user_id', $adminUserId);
            })
            ->with(['webUser', 'reportingManager'])
            ->get()
            ->groupBy('reporting_manager_id');

        foreach ($employees as $managerId => $group) {
            $manager = WebUser::find($managerId);
            $result[] = [
                'manager_id'   => $managerId,
                'manager_name' => $manager ? $manager->name : 'Unassigned',
                'employees'    => $group->map(function ($emp) {
                    $hasAudit = Audits::where('web_user_id', $emp->id)->exists();
                    return [
                        'id'            => $emp->id,
                        'emp_name'      => $emp->emp_name,
                        'emp_id'        => $emp->emp_id,
                        'designation'   => $emp->designation,
                        'department'    => $emp->department,
                        'doj'           => $emp->date_of_joining?->format('Y-m-d'),
                        'profile_photo' => $emp->profile_photo,
                        'status' => $hasAudit ? 'Submitted' : 'Not Submitted',
                    ];
                })->values(),
            ];
        }

        return response()->json([
            'status' => 'Success',
            'message' => 'List of all team members with audit status',
            'data' => $result,
        ], 200);
    }

    public function getEmployeesByAdminUser($id)
    {
        // Step 1: Get the admin_user_id of the provided web_user_id
        $webUser = WebUser::findOrFail($id);
        $adminUserId = $webUser->admin_user_id;

        // Step 2: Fetch all employees under the same admin_user_id
        $employees = EmployeeDetails::whereHas('webUser', function ($query) use ($adminUserId) {
            $query->where('admin_user_id', $adminUserId);
        })
        ->select('web_user_id', 'emp_name', 'emp_id')
        ->get();

        return response()->json([
            'message' => 'Employees fetched successfully.',
            'status'  => 'Success',
            'data'    => $employees
        ], 200);
    }

    // Send the reset password link to the user's email
    public function sendResetLinkEmail(Request $request)
    {
        // Validate the email input
        $validator = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Send the reset password email
        $response = Password::sendResetLink(
            $request->only('email')
        );

        if ($response == Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'Password reset link sent successfully.', 'status' => 'Success'], 200);
        }

        return response()->json(['message' => 'Failed to send reset link, please check your email.'], 400);
    }

// Reset the password using the provided token
public function reset(Request $request)
{
    // Validate the reset password request
    $validator = Validator::make($request->all(), [
        'token' => 'required',
        'email' => 'required|email',
        'password' => 'required|confirmed|min:8',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    // Attempt to reset the password using the token and email
    $response = Password::reset(
        $request->only('email', 'password', 'password_confirmation', 'token'),
        function ($user, $password) {
            // Update the user's password
            $user->forceFill(['password' => bcrypt($password)])->save();
        }
    );

    // Check the response status
    if ($response == Password::PASSWORD_RESET) {
        return response()->json(['message' => 'Password reset successfully.', 'status' => 'Success'], 200);
    }

    return response()->json(['message' => 'Failed to reset password, invalid token or email.'], 400);
}

}
