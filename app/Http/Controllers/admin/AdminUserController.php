<?php

namespace App\Http\Controllers\admin;

use App\Models\AdminUser;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Models\About;
use App\Models\Client;
use App\Models\FeedbackQuestions;
use App\Models\Heirarchies;
use App\Models\Holidays;
use App\Models\Industries;
use App\Models\JobOpening;
use App\Models\Projects;
use App\Models\ProjectTeam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\SectionSelection;
use App\Models\Service;
use App\Models\WebUser;
use App\Models\Achievement;
use App\Models\TotalLeaves;
use App\Models\Event;
use Illuminate\Support\Facades\Storage;
use App\Models\Policies;
use App\Models\PolicyQuestions;
use Aws\S3\S3Client;

class AdminUserController extends Controller
{
    public function createAdminUser(Request $request)
    {
        // Validate the incoming data
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:admin_users,email',
            'phone' => 'required|string|max:20',
            'password' => 'required|string|min:8',
            'company_name' => 'required|string|max:255|unique:admin_users,company_name',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:2048',
            'allowed_users' => 'required|string|max:255'
        ]);

        $logoUrl = null;

        if ($request->hasFile('logo')) {
            $logoExtension = $request->file('logo')->getClientOriginalExtension();

            // Check if any previous logo exists and delete
            $existingFiles = Storage::disk('s3')->files("{$request->company_name}/logo_{$request->company_name}");

            foreach ($existingFiles as $existingFile) {
                if (pathinfo($existingFile, PATHINFO_FILENAME) == "logo_{$request->company_name}") {
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
            $key = "{$request->company_name}/logo_{$request->company_name}.{$logoExtension}";

            $s3->putObject([
                'Bucket' => $bucket,
                'Key'    => $key,
                'Body'   => $request->file('logo')->get(),
                'ContentType' => 'image/png',
            ]);

            $logoUrl = $s3->getObjectUrl($bucket, $key);
        }

        // Create the admin user
        AdminUser::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => bcrypt($request->password),
            'company_name' => $request->company_name,
            'logo' => $logoUrl,
            'allowed_users' => $request->allowed_users
        ]);

        return response()->json([
            'status' => 'Success',
            'message' => 'Admin user created successfully.'
        ], 201);
    }


    //      Method for admin login
    public function adminlogin(Request $request)
    {

        // Validate the incoming data
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8',
        ]);

        // Check if the validation fails
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Retrieve the admin user by email
        $adminUser = AdminUser::where('email', $request->input('email'))->first();

        if (!$adminUser || !Hash::check($request->input('password'), $adminUser->password)) {
            // Successful login: create a token or session
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $token = $adminUser->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'Success',
            'message' => 'Login successful.',
            'token' => $token,
            'data' => $adminUser,
        ], 200);
    }

    public function saveSelection(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|integer|exists:admin_users,id',
            'selected_sections' => 'nullable|array',
            'section_name' => 'nullable|string',
        ]);

        if(!$validated) {
            return response()->json([
                'message' => 'Invalid details',
                'status' => 'error'
            ], 403);
        }

        $adminUserId = $validated['admin_user_id'];

        $user = Auth::user();

        if($user->id !== $adminUserId) {
            return response()->json([
                'message' => 'Unauthorized Role',
                'status' => 'error'
            ], 403);
        }

        // Remove old selections
        SectionSelection::where('admin_user_id', $adminUserId)->delete();

        // Recursive function to save nested sections
        function saveSections($sections, $adminUserId, $parentId = null) {
            foreach ($sections as $key => $value) {
                if (is_array($value)) {
                    // If value is an array, it's a parent section with children
                    $section = SectionSelection::create([
                        'admin_user_id' => $adminUserId,
                        'section_name' => $key,
                        'parent_id' => $parentId
                    ]);
                    saveSections($value, $adminUserId, $section->id); // Recursively save child sections
                } else if (!empty($value)) {
                    // If value is a string, it's a standalone section
                    SectionSelection::create([
                        'admin_user_id' => $adminUserId,
                        'section_name' => $value,
                        'parent_id' => $parentId
                    ]);
                } else if (empty($value)) {
                    SectionSelection::create([
                        'admin_user_id' => $adminUserId,
                        'section_name' => $key,
                        'parent_id' => $parentId
                    ]);
                }
            }
        }

        $request->section_name !== 'all' ? saveSections($validated['selected_sections'], $adminUserId) : SectionSelection::create([
            'admin_user_id' => $adminUserId,
            'section_name' => $request->section_name,
            'parent_id' => null
        ]);

        return response()->json(['message' => 'Selections saved successfully!', 'status' => 'Success'], 200);
    }

    public function getSelectedSections(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|integer|exists:admin_users,id',
            'module' => 'nullable|string|in:hrms,ats', // Module is now optional
        ]);

        $adminUserId = $validated['admin_user_id'];
        $module = $validated['module'] ?? null; // If null, retrieve all

        // Query the selected sections for the given admin user
        $query = SectionSelection::where('admin_user_id', $adminUserId);

        // Apply module filter only if it's provided
        if ($module) {
            $query->where('section_name', $module)
                ->orWhereHas('parent', function ($query) use ($module) {
                    $query->where('section_name', $module);
                });
        }

        $sections = $query->get();

        // Function to build the hierarchical structure
        function buildTree($sections, $parentId = null) {
            $tree = [];
            foreach ($sections as $section) {
                if ($section->parent_id == $parentId) {
                    $children = buildTree($sections, $section->id);
                    $tree[$section->section_name] = !empty($children) ? $children : null;
                }
            }
            return $tree;
        }

        $nestedSections = buildTree($sections);

        return response()->json([
            'status' => 'Success',
            'selected_sections' => $nestedSections
        ], 200);
    }

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

    public function saveHeirarchy(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'level' => 'required|string',
            'title' => 'required|string',
            'experience_range' => 'required|string',
            'description' => 'nullable|string',
            'action' => 'required|in:create,update',
            'id' => 'required_if:action,update|exists:heirarchies,id'
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);
        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        if ($request->action === 'create') {
            // Create the hierarchy
            Heirarchies::create([
                'admin_user_id' => $request->admin_user_id,
                'company_name' => $adminUser->company_name,
                'level' => $request->level,
                'title' => $request->title,
                'experience_range' => $request->experience_range,
                'description' => $request->description ?? null,
            ]);

            return response()->json(['message' => 'Hierarchy created successfully.', 'status' => 'Success'], 201);
        }

        $heirarchy = Heirarchies::find($request->id);

        // Update the hierarchy
        $heirarchy->update([
            'level' => $request->level,
            'title' => $request->title,
            'experience_range' => $request->experience_range,
            'description' => $request->description ?? $heirarchy->description,
        ]);

        return response()->json(['message' => 'Hierarchy updated successfully.', 'status' => 'Success'], 200);
    }

    public function saveHoliday(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'holiday' => 'required|string',
            'date' => 'required|date',
            'description' => 'nullable|string',
            'action' => 'required|in:create,update',
            'id' => 'required_if:action,update|exists:holidays,id'
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);
        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        if ($request->action === 'create') {
            // Create the holiday
            Holidays::create([
                'admin_user_id' => $request->admin_user_id,
                'company_name' => $adminUser->company_name,
                'holiday' => $request->holiday,
                'date' => $request->date,
                'description' => $request->description ?? null,
            ]);

            return response()->json(['message' => 'Holiday created successfully.', 'status' => 'Success'], 201);
        }

        $holiday = Holidays::find($request->id);

        // Update the holiday
        $holiday->update([
            'holiday' => $request->holiday,
            'date' => $request->date,
            'description' => $request->description ?? $holiday->description,
        ]);

        return response()->json(['message' => 'Holiday updated successfully.', 'status' => 'Success'], 200);
    }

    public function saveIndustry(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'name' => 'required|string',
            'description' => 'nullable|string',
            'action' => 'required|in:create,update',
            'id' => 'required_if:action,update|exists:industries,id'
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);
        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        if ($request->action === 'create') {
            // Create the industry
            Industries::create([
                'admin_user_id' => $request->admin_user_id,
                'company_name' => $adminUser->company_name,
                'name' => $request->name,
                'description' => $request->description ?? null,
            ]);

            return response()->json(['message' => 'Industry created successfully.', 'status' => 'Success'], 201);
        }

        $industry = Industries::find($request->id);

        // Update the industry
        $industry->update([
            'name' => $request->name,
            'description' => $request->description ?? $industry->description,
        ]);

        return response()->json(['message' => 'Industry updated successfully.', 'status' => 'Success'], 200);
    }

    public function saveClient(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'name' => 'required|string',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:5048',
            'description' => 'nullable|string',
            'action' => 'required|in:create,update',
            'id' => 'required_if:action,update|exists:clients,id'
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);
        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }
        $logoExtension = $request->file('logo')->getClientOriginalExtension();

        // Check if the file exists with any extension
        $existingFiles = Storage::disk('s3')->files("{$adminUser->company_name}/client/{$request->name}");

        // If a file with the same name exists, delete it
        foreach ($existingFiles as $existingFile) {
            if (basename($existingFile, pathinfo($existingFile, PATHINFO_EXTENSION)) == $request->name) {
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
        $key = "{$adminUser->company_name}/client/{$request->name}.{$logoExtension}";

        $s3->putObject([
            'Bucket' => $bucket,
            'Key'    => $key,
            'Body'   => $request->file('logo')->get(),
            'ContentType' => 'image/png',
        ]);

        $logoUrl = $s3->getObjectUrl($bucket, $key);

        if ($request->action === 'create') {
            // Create the client
            Client::create([
                'admin_user_id' => $request->admin_user_id,
                'company_name' => $adminUser->company_name,
                'name' => $request->name,
                'description' => $request->description ?? null,
                'logo' => $logoUrl,
            ]);

            return response()->json(['message' => 'Client created successfully.', 'status' => 'Success'], 201);
        }

        $client = Client::find($request->id);

        $client->update([
            'name' => $request->name,
            'description' => $request->description ?? $client->description,
            'logo' => $logoUrl,
        ]);

        return response()->json(['message' => 'Client updated successfully.', 'status' => 'Success'], 200);
    }

    public function saveService(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'name' => 'required|string',
            'description' => 'nullable|string',
            'action' => 'required|in:create,update',
            'id' => 'required_if:action,update|exists:services,id'
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);
        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        if ($request->action === 'create') {
            Service::create([
                'admin_user_id' => $request->admin_user_id,
                'company_name' => $adminUser->company_name,
                'name' => $request->name,
                'description' => $request->description ?? null,
            ]);

            return response()->json(['message' => 'Service created successfully.', 'status' => 'Status'], 201);
        }

        $service = Service::find($request->id);
        $service->update([
            'name' => $request->name,
            'description' => $request->description ?? $service->description,
        ]);

        return response()->json(['message' => 'Service updated successfully.', 'status' => 'Success'], 200);
    }

    public function saveAbout(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'about' => 'nullable|string',
            'services' => 'nullable|string',
            'industries' => 'nullable|string',
            'client' => 'nullable|string',
            'team' => 'nullable|string',
            'action' => 'required|in:create,update',
            'id' => 'required_if:action,update|exists:abouts,id'
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);
        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        if ($request->action === 'create') {
            About::create([
                'admin_user_id' => $request->admin_user_id,
                'company_name' => $adminUser->company_name,
                'about' => $request->about ?? null,
                'services' => $request->services ?? null,
                'industries' => $request->industries ?? null,
                'client' => $request->client ?? null,
                'team' => $request->team ?? null
            ]);

            return response()->json(['message' => 'About information created successfully.', 'status' => 'Success'], 201);
        }

        $about = About::find($request->id);
        $about->update([
            'about' => $request->about ?? $about->about,
            'services' => $request->services ?? $about->services,
            'industries' => $request->industries ?? $about->industries,
            'client' => $request->client ?? $about->client,
            'team' => $request->team ?? $about->team
        ]);

        return response()->json(['message' => 'About information updated successfully.', 'status' => 'Success'], 200);
    }

    public function getAboutByWebUserId(Request $request)
    {
        $validated = $request->validate([
            'web_user_id' => 'required|integer|exists:web_users,id',
        ]);

        $webUser = WebUser::find($request->web_user_id);

        if (!$webUser || !$validated) {
            return response()->json([
                'status' => 'error',
                'message' => 'Web user not found.'
            ], 404);
        }

        $about = About::where('admin_user_id', $webUser->admin_user_id)->first();

        if (!$about) {
            return response()->json([
                'status' => 'error',
                'message' => 'About data not found for this admin user.'
            ], 404);
        }

        return response()->json([
            'status' => 'Success',
            'message' => 'About data retrieved successfully',
            'data' => $about
        ]);
    }

    public function saveEvent(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'event' => 'nullable|string',
            'title' => 'required|string',
            'date' => 'required|date',
            'description' => 'nullable|string',
            'action' => 'required|in:create,update',
            'id' => 'required_if:action,update|exists:events,id'
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);
        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        if ($request->action === 'create') {
            Event::create([
                'admin_user_id' => $request->admin_user_id,
                'company_name' => $adminUser->company_name,
                'event' => $request->event ?? null,
                'title' => $request->title,
                'date' => $request->date,
                'description' => $request->description ?? null
            ]);

            return response()->json(['message' => 'Event created successfully.', 'status' => 'Success'], 201);
        }

        $event = Event::find($request->id);
        $event->update([
            'event' => $request->event ?? $event->event,
            'title' => $request->title,
            'date' => $request->date,
            'description' => $request->description ?? $event->description
        ]);

        return response()->json(['message' => 'Event updated successfully.', 'status' => 'Success'], 200);
    }

    public function saveAchievements(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'achievement' => 'nullable|string',
            'values' => 'nullable|string',
            'action' => 'required|in:create,update',
            'id' => 'required_if:action,update|exists:achievements,id'
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);
        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        if ($request->action === 'create') {
            Achievement::create([
                'admin_user_id' => $request->admin_user_id,
                'company_name' => $adminUser->company_name,
                'achievement' => $request->achievement ?? null,
                'values' => $request->values ?? null,
            ]);

            return response()->json(['message' => 'Achievement created successfully.', 'status' => 'Success'], 201);
        }

        $achievement = Achievement::find($request->id);
        $achievement->update([
            'achievement' => $request->achievement ?? $achievement->achievement,
            'values' => $request->values ?? $achievement->values,
        ]);

        return response()->json(['message' => 'Achievement updated successfully.', 'status' => 'Success'], 200);
    }

    public function saveFeedbackQuestions(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'question' => 'nullable|string',
            'action' => 'required|in:create,update',
            'id' => 'required_if:action,update|exists:feedback_questions,id'

        ]);

        $adminUser = AdminUser::find($request->admin_user_id);
        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        if ($request->action === 'create') {
            FeedbackQuestions::create([
                'admin_user_id' => $request->admin_user_id,
                'company_name' => $adminUser->company_name,
                'question' => $request->question ?? null,
            ]);

            return response()->json(['message' => 'Feedback question created successfully.', 'status' => 'Success'], 201);
        }

        $feedbackQuestion = FeedbackQuestions::find($request->id);
        $feedbackQuestion->update([
            'question' => $request->question ?? $feedbackQuestion->question,
        ]);

        return response()->json(['message' => 'Feedback question updated successfully.', 'status' => 'Success'], 200);
    }

    public function saveJobOpenings(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'title' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'action' => 'required|in:create,update',
            'id' => 'required_if:action,update|exists:job_openings,id'
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);
        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        if ($request->action === 'create') {
            JobOpening::create([
                'admin_user_id' => $request->admin_user_id,
                'company_name' => $adminUser->company_name,
                'title' => $request->title,
                'position' => $request->position
            ]);

            return response()->json(['message' => 'Job opening created successfully.', 'status' => 'Success'], 201);
        }

        $job = JobOpening::find($request->id);
        $job->update([
            'title' => $request->title,
            'position' => $request->position
        ]);

        return response()->json(['message' => 'Job opening updated successfully.', 'status' => 'Success'], 200);
    }

    public function saveProjects(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'name' => 'nullable|string|max:255',
            'domain' => 'nullable|string|max:255',
            'project_manager_id' => 'nullable|string|max:255',
            'project_manager_name' => 'nullable|string|max:255',
            'progress' => 'nullable|string|max:255',
            'client' => 'nullable|string|max:255',
            'comment' => 'nullable|string|max:255',
            'deadline' => 'nullable|date',
            'team' => 'nullable|array',
            'team.*.member_id' => 'required_with:team|exists:web_users,emp_id',
            'team.*.member' => 'required_with:team|string',
            'team.*.role' => 'required_with:team|string',
            'action' => 'required|in:create,update',
            'id' => 'required_if:action,update|exists:projects,id'
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);
        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        if ($request->action === 'create') {
            $project = Projects::create([
                'admin_user_id' => $request->admin_user_id,
                'company_name' => $adminUser->company_name,
                'name' => $request->name,
                'domain' => $request->domain,
                'project_manager_id' => $request->project_manager_id,
                'project_manager_name' => $request->project_manager_name,
                'progress' => $request->progress,
                'client' => $request->client,
                'comment' => $request->comment,
                'deadline' => $request->deadline
            ]);

            if (!empty($request->team)) {
                foreach ($request->team as $memberData) {
                    $webUser = WebUser::where('emp_id', $memberData['member_id'])->first();

                    if ($webUser) {
                        ProjectTeam::create([
                            'project_id' => $project->id,
                            'project_name' => $project->name,
                            'web_user_id' => $webUser->id,
                            'emp_name' => $webUser->name,
                            'emp_id' => $webUser->emp_id,
                            'member' => $webUser->name,
                            'role' => $memberData['role']
                        ]);
                    }
                }
            }

            return response()->json(['message' => 'Project created successfully.', 'status' => 'Success'], 201);
        }

        $project = Projects::find($request->id);
        $project->update([
            'name' => $request->name,
            'domain' => $request->domain,
            'project_manager_id' => $request->project_manager_id,
            'project_manager_name' => $request->project_manager_name,
            'progress' => $request->progress,
            'client' => $request->client,
            'comment' => $request->comment
        ]);

        return response()->json(['message' => 'Project updated successfully.', 'status' => 'Success'], 200);
    }

    public function saveTotalLeaves(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'type' => 'nullable|string|max:255',
            'total' => 'nullable|string|max:255',
            'period' => 'nullable|string|max:255',
            'action' => 'required|in:create,update',
            'id' => 'required_if:action,update|exists:total_leaves,id'
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);
        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        if ($request->action === 'create') {
            TotalLeaves::create([
                'admin_user_id' => $request->admin_user_id,
                'company_name' => $adminUser->company_name,
                'type' => $request->type,
                'total' => $request->total,
                'period' => $request->period
            ]);

            return response()->json(['message' => 'Leave policy created successfully.', 'status' => 'Success'], 201);
        }

        $leave = TotalLeaves::find($request->id);
        $leave->update([
            'type' => $request->type,
            'total' => $request->total,
            'period' => $request->period
        ]);

        return response()->json(['message' => 'Leave policy updated successfully.', 'status' => 'Success'], 200);
    }

    public function savePolicies(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'title' => 'required|string|max:255',
            'policy' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'action' => 'required|in:create,update',
            'id' => 'required_if:action,update|exists:policies,id'
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);
        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        if ($request->action === 'create') {
            Policies::create([
                'admin_user_id' => $request->admin_user_id,
                'company_name' => $adminUser->company_name,
                'title' => $request->title,
                'policy' => $request->policy,
                'description' => $request->description
            ]);

            return response()->json(['message' => 'Policy created successfully.', 'status' => 'Success'], 201);
        }

        $policy = Policies::find($request->id);
        $policy->update([
            'title' => $request->title,
            'policy' => $request->policy,
            'description' => $request->description
        ]);

        return response()->json(['message' => 'Policy updated successfully.', 'status' => 'Success'], 200);
    }


    public function deleteHeirarchy(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'id' => 'required|exists:heirarchies,id',
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);

        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        $heirarchy = Heirarchies::find($request->id);
        if (!$heirarchy) {
            return response()->json(['message' => 'Hierarchy not found'], 404);
        }

        $heirarchy->delete();

        return response()->json(['message' => 'Hierarchy deleted successfully.', 'status' => 'Success'], 200);
    }

    public function deleteHoliday(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'id' => 'required|exists:holidays,id',
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);

        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        $holiday = Holidays::find($request->id);
        if (!$holiday) {
            return response()->json(['message' => 'Holiday not found'], 404);
        }

        $holiday->delete();

        return response()->json(['message' => 'Holiday deleted successfully.', 'status' => 'Success'], 200);
    }

    public function deleteIndustry(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'id' => 'required|exists:industries,id',
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);

        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        $industry = Industries::find($request->id);
        if (!$industry) {
            return response()->json(['message' => 'Industry not found'], 404);
        }

        $industry->delete();

        return response()->json(['message' => 'Industry deleted successfully.', 'status' => 'Success'], 200);
    }

    public function deleteClient(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'id' => 'required|exists:clients,id',
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);

        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        $client = Client::find($request->id);
        if (!$client) {
            return response()->json(['message' => 'Client not found'], 404);
        }

        // Optionally, delete the logo from storage
        Storage::delete($client->logo);

        $client->delete();

        return response()->json(['message' => 'Client deleted successfully.', 'status' => 'Success'], 200);
    }

    public function deleteService(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'id' => 'required|exists:services,id',
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);

        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        $service = Service::find($request->id);
        if (!$service) {
            return response()->json(['message' => 'Service not found'], 404);
        }

        $service->delete();

        return response()->json(['message' => 'Service deleted successfully.', 'status' => 'Success'], 200);
    }

    public function deleteAbout(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);

        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        $about = About::where('admin_user_id', $request->admin_user_id)->get();
        if (!$about) {
            return response()->json(['message' => 'About section not found'], 404);
        }

        $about->delete();

        return response()->json(['message' => 'About section deleted successfully.', 'status' => 'Success'], 200);
    }

    public function deleteEvent(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'id' => 'required|exists:events,id',
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);

        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        $event = Event::find($request->id);
        if (!$event) {
            return response()->json(['message' => 'Event not found'], 404);
        }

        $event->delete();

        return response()->json(['message' => 'Event deleted successfully.', 'status' => 'Success'], 200);
    }

    public function deleteAchievements(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'id' => 'required|exists:achievements,id',
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);

        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        $achievement = Achievement::find($request->id);
        if (!$achievement) {
            return response()->json(['message' => 'Achievement not found'], 404);
        }

        $achievement->delete();

        return response()->json(['message' => 'Achievement deleted successfully.', 'status' => 'Success'], 200);
    }

    public function deleteFeedbackQuestions(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'id' => 'required|exists:feedback_questions,id',
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);

        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        $feedbackQuestion = FeedbackQuestions::find($request->id);

        if (!$feedbackQuestion) {
            return response()->json(['message' => 'Feedback question not found'], 404);
        }

        $feedbackQuestion->delete();

        return response()->json(['message' => 'Feedback question deleted successfully.', 'status' => 'Success'], 200);
    }

    public function deleteJobOpenings(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'id' => 'required|exists:job_openings,id',
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);

        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        $job = JobOpening::find($request->id);

        if (!$job) {
            return response()->json(['message' => 'Job opening not found'], 404);
        }

        $job->delete();

        return response()->json(['message' => 'Job opening deleted successfully.', 'status' => 'Success'], 200);
    }

    public function deleteProjects(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'id' => 'required|exists:projects,id',
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);

        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        $project = Projects::find($request->id);

        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $project->delete();

        return response()->json(['message' => 'Project and related team deleted successfully.', 'status' => 'Success'], 200);
    }

    public function deleteTotalLeave(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'id' => 'required|exists:total_leaves,id',
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);

        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        $leave = TotalLeaves::find($request->id);

        if (!$leave) {
            return response()->json(['message' => 'Leave policy not found'], 404);
        }

        $leave->delete();

        return response()->json(['message' => 'Leave policy deleted successfully.', 'status' => 'Success'], 200);
    }

    public function deletePolicies(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => 'required|exists:admin_users,id',
            'id' => 'required|exists:policies,id',
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);

        if (!$adminUser) {
            return response()->json(['message' => 'Invalid admin user'], 400);
        }

        $policy = Policies::find($request->id);

        if (!$policy) {
            return response()->json(['message' => 'Policy not found'], 404);
        }

        if ($policy->description === 'compulsory') {
            return response()->json(['message' => 'Deleting this policy is not allowed'], 404);
        }

        $policy->delete();

        return response()->json(['message' => 'Policy deleted successfully.', 'status' => 'Success'], 200);
    }

    public function getAllAdminPanelData($id)
    {
        $adminUser = AdminUser::find($id);

        if(!$adminUser) {
            return response()->json([
                'message' => 'Invalid data'
            ], 400);
        }

        $data = [
            'hierarchies' => Heirarchies::where('admin_user_id', $id)->get(),
            'policies' => Policies::where('admin_user_id', $id)->get(),
            'holidays' => Holidays::where('admin_user_id', $id)->get(),
            'industries' => Industries::where('admin_user_id', $id)->get(),
            'clients' => Client::where('admin_user_id', $id)->get(),
            'services' => Service::where('admin_user_id', $id)->get(),
            'about' => About::where('admin_user_id', $id)->get(),
            'events' => Event::where('admin_user_id', $id)->get(),
            'achievements' => Achievement::where('admin_user_id', $id)->get(),
            'feedbackQuestions' => FeedbackQuestions::where('admin_user_id', $id)->get(),
            'jobs' => JobOpening::where('admin_user_id', $id)->get(),
            'totalLeaves' => TotalLeaves::where('admin_user_id', $id)->get(),
            'projects' => Projects::with('projectTeam')->where('admin_user_id', $id)->get(),
            'policyQuestions' => PolicyQuestions::where('admin_user_id', $id)->get(),
        ];

        return response()->json([
            'status' => 'Success',
            'message' => 'Admin panel data fetched successfully',
            'data' => $data
        ], 200);
    }

    public function getAllWebUsers($id)
    {
        $adminUser = AdminUser::find($id);

        if(!$adminUser) {
            return response()->json([
                'message' => 'Invalid data'
            ], 400);
        }

        // Fetch all web user
        $webUsers = WebUser::where('admin_user_id', $id)
        ->with(['employeeDetails' => function ($query) {
            $query->select('id', 'web_user_id', 'department', 'personal_contact_no');
        }])->get();

        return response()->json([
            'status' => 'Success',
            'message' => 'Web users fetched successfully',
            'data' => [
                'company_name' => $adminUser->company_name,
                'allowed_users' => $adminUser->allowed_users,
                'created' => $webUsers->count(),
                'user_data' => $webUsers
            ]
        ], 200);
    }
}
