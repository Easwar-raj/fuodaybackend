<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\EmployeeDetails;
use App\Models\SectionSelection;
use App\Models\Payroll;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use App\Models\WebUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Aws\S3\S3Client;

class WebpageUserController extends Controller
{
    // Create Web User
    public function saveWebUser(Request $request)
    {

        $validated = $request->validate([
            'action' => 'required|in:create,update',
            'admin_user_id' => 'required|integer|exists:admin_users,id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'role' => 'required|string|in:employee,recruiter,hr,hr_recruiter',
            'role_location' => 'required|string|max:255',
            'gender' => 'required|string|max:255',
            'designation' => 'required|string|max:255',
            'department' => 'required|string|max:255',
            'place' => 'required|string|max:255',
            'emp_id' => 'required|string',
            'password' => 'required|string|min:8',
            'group' => 'nullable|string|max:255',
            'blood_group' => 'nullable|string|max:255',
            'dob' => 'nullable|string|max:255',
            'personal_contact_no' => 'required|integer|min:10',
            'emergency_contact_no' => 'required|integer|min:10',
            'official_contact_no' => 'required|integer|min:10',
            'employment_type' => 'required|string|max:255',
            'work_module' => 'required|string|max:255',
            'date_of_joining' => 'required|date',
            'reporting_manager_id' => 'nullable|integer',
            'reporting_manager_name' => 'nullable|string|max:255',
            'basic' => 'required|string|max:255',
            'city_compensatory_allowance' => 'nullable|string|max:255',
            'special_allowance' => 'nullable|string|max:255',
            'additional_allowance' => 'nullable|string|max:255',
            'fixed_allowance' => 'nullable|string|max:255',
            'leave_encashment' => 'nullable|string|max:255',
            'pf' => 'nullable|string|max:255',
            'esi' => 'nullable|string|max:255',
            'professional_tax' => 'nullable|string|max:255',
            'gross' => 'nullable|string|max:255',
            'actual_deductions' => 'nullable|string|max:255',
            'actual_salary' => 'nullable|string|max:255',
            'ctc' => 'nullable|string|max:255',
        ]);

        if (!$validated) {
            return response()->json([
                'status' => 'error'
            ], 422);
        }

        // Common permission check
        $user = Auth::user();
        if ($user->id !== $request->admin_user_id) {
            return response()->json([
                'message' => 'Unauthorized',
                'status' => 'error'
            ], 401);
        }

        $webUserDetails = [
            'name' => $request->first_name . ' ' . $request->last_name,
            'role' => $request->role,
            'emp_id' => $request->emp_id,
            'group' => $request->group,
            'password' => Hash::make($request->password),
        ];

        $employeeData = [
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
        ];



        $payrollData = [
            'designation' => $request->designation,
            'basic' => $request->basic,
            'city_compensatory_allowance' => $request->city_compensatory_allowance,
            'special_allowance' => $request->special_allowance,
            'additional_allowance' => $request->additional_allowance,
            'fixed_allowance' => $request->fixed_allowance,
            'leave_encashment' => $request->leave_encashment,
            'pf' => $request->pf,
            'esi' => $request->esi,
            'professional_tax' => $request->professional_tax,
            'gross' => $request->gross,
            'actual_deductions' => $request->actual_deductions,
            'actual_salary' => $request->actual_salary,
            'ctc' => $request->ctc,
        ];

        // Functionality: ADD or UPDATE
        if ($request->action === 'create') {
            $adminUser = AdminUser::find($request->admin_user_id);
            $webUserList = WebUser::where('admin_user_id', $request->admin_user_id)->count();

            if ((int)$adminUser->allowed_users <= $webUserList) {
                return response()->json([
                    'message' => 'Allowed users exceeded',
                    'status' => 'error'
                ], 401);
            }

            $initials = strtoupper($request->first_name[0] ?? '') . strtoupper($request->last_name[0] ?? '');
            $profilePhotoPath = $this->generateProfileImage($initials, $request->emp_id, $request->admin_user_id);

            $webUser = WebUser::create(array_merge($webUserDetails, [
                'admin_user_id' => $request->admin_user_id,
                'email' => $request->email,
            ]));

            Log::info($request, $employeeData);

            $query = EmployeeDetails::create(array_merge($employeeData, [
                'web_user_id' => $webUser->id,
                'emp_name' => $webUser->name,
                'emp_id' => $webUser->emp_id,
                'profile_photo' => $profilePhotoPath,
            ]));

            Payroll::create(array_merge($payrollData, [
                'web_user_id' => $webUser->id,
                'emp_name' => $webUser->name,
                'emp_id' => $webUser->emp_id,
            ]));

            Log::info($request, $employeeData, $query);

            return response()->json([
                'status' => 'success',
                'message' => 'Web user created successfully.'
            ], 201);
        }

        if ($request->action === 'update') {
            $webUser = WebUser::where('admin_user_id', $request->admin_user_id)->where('email', $request->email)->first();

            if (!$webUser) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Web user not found'
                ], 404);
            }

            $webUser->update($webUserDetails);

            $webUser->employeeDetails()->update($employeeData);

            $webUser->payroll()->update($payrollData);

            return response()->json([
                'status' => 'Success',
                'message' => 'Web user updated successfully.'
            ], 200);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Invalid functionality provided.'
        ], 400);
    }

    /**
     * Generate a profile image with initials.
     */
    private function generateProfileImage($initials, $empId, $adminUserId)
    {
        try {
            // Set image dimensions
            $width = 200;
            $height = 200;

            // Create a blank image
            $image = imagecreate($width, $height);
            if (!$image) {
                throw new \Exception("Failed to create an image resource.");
            }

            // Set background color (RGB: 52, 152, 219 - Blue)
            $backgroundColor = imagecolorallocate($image, 52, 152, 219);

            // Set text color (RGB: 255, 255, 255 - White)
            $textColor = imagecolorallocate($image, 255, 255, 255);

            // Define font path
            $fontPath = public_path('fonts/arial.ttf'); // Make sure this path is correct
            if (!file_exists($fontPath)) {
                throw new \Exception("Font file not found at: " . $fontPath);
            }

            // Add text in the center
            $fontSize = 60; // Font size
            $x = (strlen($initials) === 1) ? 70 : 50; // X position
            $y = 130; // Y position
            imagettftext($image, $fontSize, 0, $x, $y, $textColor, $fontPath, $initials);

            ob_start();
            imagepng($image);
            $imageData = ob_get_clean();
            imagedestroy($image);
            $adminUser = AdminUser::find($adminUserId);

            $existingFiles = Storage::disk('s3')->files("{$adminUser->company_name}/avatar/{$empId}");

            foreach ($existingFiles as $existingFile) {
                if (pathinfo($existingFile, PATHINFO_FILENAME) == $empId) {
                    Storage::disk('s3')->delete($existingFile);
                }
            }
            $s3 = new S3Client([
                'region'  => env('AWS_DEFAULT_REGION'),
                'version' => 'latest',
                'credentials' => [
                    'key'    => env('AWS_ACCESS_KEY_ID'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY'),
                ],
            ]);

            $bucket = env('AWS_BUCKET');
            $key = "{$adminUser->company_name}/avatar/{$empId}.png";

            $s3->putObject([
                'Bucket' => $bucket,
                'Key'    => $key,
                'Body'   => $imageData,
                'ContentType' => 'image/png',
            ]);

            return $s3->getObjectUrl($bucket, $key);

        } catch (\Exception $e) {
            Log::error("Error generating image: " . $e->getMessage());
            return null;
        }
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
                'region'  => env('AWS_DEFAULT_REGION'),
                'version' => 'latest',
                'credentials' => [
                    'key'    => env('AWS_ACCESS_KEY_ID'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY'),
                ],
            ]);

            $bucket = env('AWS_BUCKET');
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
            ]);
        }


        return response()->json(['status' => 'Success', 'message' => 'Web user updated successfully.'], 200);
    }

    public function getWebUserById(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|integer|exists:admin_users,id',
            'web_user_id' => 'required|integer|exists:web_users,id',
        ]);

        if (!$validated) {
            return response()->json([
                'status' => 'error'
            ], 422);
        }

        // Optional: Check if authenticated user is the same as admin_user_id
        $user = Auth::user();
        if ($user->id !== $request->admin_user_id) {
            return response()->json([
                'message' => 'Unauthorized',
                'status' => 'error'
            ], 401);
        }

        $webUsers = WebUser::with([
            'employeeDetails:id,web_user_id,emp_name,emp_id,profile_photo,role_location,designation,department,employment_type,work_module,date_of_joining,reporting_manager_id,reporting_manager_name',
            'payroll:id,web_user_id,emp_name,emp_id,designation,basic,city_compensatory_allowance,special_allowance,additional_allowance,fixed_allowance,leave_encashment,pf,esi,professional_tax,gross,actual_deductions,actual_salary,ctc'
        ])->where('admin_user_id', $request->admin_user_id)->where('id', $request->web_user_id)->first();

        return response()->json([
            'status' => 'Success',
            'message' => 'Web user details fetched successfully.',
            'data' => $webUsers
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
                    $query->select('web_user_id', 'profile_photo', 'designation');
                },
                'adminUser:id,logo'
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
