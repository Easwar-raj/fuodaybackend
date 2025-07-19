<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\EmailTemplates;
use App\Models\WebUser;
use Illuminate\Http\Request;

class EmailTemplatesController extends Controller
{
    public function getTemplatesByType(Request $request)
    {
        $request->validate([
            'type' => 'required|string',
            'web_user_id' => 'required|exists:web_users,id'
        ]);

        $webUser = WebUser::find($request->web_user_id);
 
        if (!$webUser) {
            return response()->json([
                'error' => 'Invalid web_user_id',
                'message' => 'User not found'
            ], 404);
        }
 
        $admin_user_id = AdminUser::find($webUser->admin_user_id);

        $templates = EmailTemplates::where('admin_user_id', $admin_user_id)->where('type', $request->type)
            ->select('id', 'company_name', 'type', 'subject', 'body')
            ->get();

        if ($templates->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No email templates found for the given type.',
            ], 404);
        }

        return response()->json([
            'status' => 'Success',
            'message' => 'Email templates fetched successfully.',
            'templates' => $templates
        ]);
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
