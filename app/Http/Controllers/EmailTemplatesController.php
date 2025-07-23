<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\EmailTemplates;
use App\Models\WebUser;
use Illuminate\Http\Request;

class EmailTemplatesController extends Controller
{
    public function getEmailTemplateByType(Request $request)
    {
        $validated = $request->validate([
            'web_user_id' => 'required|integer|exists:web_users,id',
            'type' => 'required|string|max:255',
        ]);

        try {
            $webUser = WebUser::findOrFail($validated['web_user_id']);
            $adminUserId = $webUser->admin_user_id;

            $types = [$validated['type']];

            // If type is 'technical', map to multiple internal template types
            if ($validated['type'] === 'technical') {
                $types = [
                    'l1_interview',
                    'l1_rejection',
                    'technical_interview',
                    'technical_rejection',
                ];
            } else if ($validated['type'] === 'hr') {
                $types = [
                    'hr_interview',
                    'hr_rejection'
                ];
            }

            $templates = EmailTemplates::where('admin_user_id', $adminUserId)
                ->whereIn('type', $types)
                ->get();

            if ($templates->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No email templates found for the given type(s).',
                ], 404);
            }

            return response()->json([
                'status' => 'Success',
                'message' => 'Email templates fetched successfully',
                'data' => $templates,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function addEmailTemplate(Request $request)
    {
        // Validate input
        $request->validate([
            'type' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'admin_user_id' => 'nullable|exists:admin_users,id',
        ]);

        $adminUser = AdminUser::find($request->admin_user_id);

        // Create the template
        EmailTemplates::create([
            'admin_user_id' => $request->admin_user_id,
            'company_name' => $adminUser->company_name,
            'type' => $request->type,
            'subject' => $request->subject,
            'body' => $request->body,
        ]);

        return response()->json([
            'status' => 'Success',
            'message' => 'Email template created successfully.',
        ]);
    }
}
