<?php

namespace App\Http\Controllers\hrms;

use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use App\Models\WebUser;
use App\Models\Ticket;
use Illuminate\Http\Request;
use App\Models\EmployeeDetails;

class SupportPageController extends Controller
{
    public function getAllTicketsByStatus($id)
    {
        $webUser = WebUser::find($id);
        $type = ($webUser->role !== 'employee' && $webUser->role !== 'hr') ? 'ats' : 'hrms';
        $tickets = Ticket::where('system_type', $type)
            ->where(function($query) use ($id) {
                $query->where('web_user_id', $id)->orWhere('assigned_to_id', $id);
            })
            ->select('*')
            ->get();

        $groupedTickets = $tickets->groupBy(function ($ticket) {
            $status = strtolower($ticket->status ?? 'unassigned');
            if (in_array($status, ['unassigned', 'assigned', 'in_progress', 'completed'])) {
                return $status;
            }
            return 'unassigned';
        });
        $supportEmployees = WebUser::join('admin_users', 'web_users.admin_user_id', '=', 'admin_users.id')
            ->where('web_users.admin_user_id', $webUser->admin_user_id)
            ->where('web_users.group', 'support')
            ->select(
                'web_users.id as web_user_id',
                'web_users.name as employee_name',
                'web_users.emp_id',
                'web_users.admin_user_id',
                'web_users.group',
                'admin_users.name as admin_name',
                'admin_users.email as admin_email',
                'admin_users.support_by',
                'admin_users.company_name'
            )
            ->get();

        $allEmployees = WebUser::join('admin_users', 'web_users.admin_user_id', '=', 'admin_users.id')
            ->where('web_users.admin_user_id', $webUser->admin_user_id)
            ->select(
                'web_users.id as web_user_id',
                'web_users.name as employee_name',
                'web_users.emp_id',
                'web_users.admin_user_id',
                'web_users.group',
                'admin_users.name as admin_name',
                'admin_users.email as admin_email',
                'admin_users.support_by',
                'admin_users.company_name'
            )
            ->get();

        $allEmployeeDetails =  EmployeeDetails::join('web_users', 'employee_details.web_user_id', '=', 'web_users.id')
            ->select(
                'employee_details.id as employee_detail_id',
                'employee_details.web_user_id',
                'employee_details.emp_name',
                'employee_details.emp_id',
                'employee_details.gender',
                'employee_details.profile_photo',
                'employee_details.place',
                'employee_details.designation',
                'employee_details.department',
                'employee_details.employment_type',
                'employee_details.about',
                'employee_details.role_location',
                'employee_details.work_module',
                'employee_details.dob',
                'employee_details.address',
                'employee_details.date_of_joining',
                'employee_details.reporting_manager_id',
                'employee_details.reporting_manager_name',
                'employee_details.aadhaar_no',
                'employee_details.pan_no',
                'employee_details.blood_group',
                'employee_details.personal_contact_no',
                'employee_details.emergency_contact_no',
                'employee_details.official_contact_no',
                'employee_details.official_email',
                'employee_details.permanent_address',
                'employee_details.bank_name',
                'employee_details.account_no',
                'employee_details.ifsc',
                'employee_details.pf_account_no',
                'employee_details.uan',
                'employee_details.esi_no',
                'employee_details.quote',
                'employee_details.author',
                'employee_details.welcome_image',
                'web_users.name as employee_user_name',
                'web_users.group as employee_group',
                'web_users.email as employee_email'
            )
            ->get();

        return response()->json([
            'message' => 'Successfully fetched tickets',
            'status' => 'success',
            'data' => [
                'groupedTickets' => $groupedTickets,
                'support_assignees' => $supportEmployees,
                'all_assignees' => $allEmployees,
                'allEmployeeDetails'=>$allEmployeeDetails
            ],
        ], 200);
    }

    public function addTicket(Request $request)
    {
        // Step 1: Validate the request data
        $validated = $request->validate([
            'web_user_id' => 'nullable|exists:web_users,id',
            'ticket' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'assigned_to_id' => 'nullable|exists:web_users,id',
            'assigned_to' => 'nullable|string|max:255',
            'priority' => 'required|string|max:100',
            'date' => 'required|date',
            'assignment_type' => 'nullable|string|in:Internal,External',
            'support_by' => 'nullable|string|max:255',
            'assignee_description' => 'nullable|string'
        ]);

        // Reject request if reassignment fields are present during ticket creation
        if ($request->has(['reassigned_id', 'reason_to_reassign', 'description_to_reassign'])) {
            return response()->json([
                'message' => 'Reassignment fields are not allowed during ticket creation'
            ], 400);
        }

        $webUser = WebUser::find($request->web_user_id);

        if (!$validated || !$webUser) {
            return response()->json([
                'message' => 'Invalid data or user not found'
            ], 400);
        }

        $status = 'unassigned';

        // Step 2: If assigned_to_id is provided, check that it has the same admin_user_id
        // if ($request->assigned_to_id) {
        //     $assignedUser = WebUser::find($request->assigned_to_id);

        //     if (!$assignedUser || $assignedUser->admin_user_id !== $webUser->admin_user_id) {
        //         return response()->json([
        //             'message' => 'Assigned user is not in the same company.'
        //         ], 403);
        //     }
        //     $status = 'assigned';
        // }

        $systemType = ($webUser->role !== 'employee' && $webUser->role !== 'hr') ? 'ats' : 'hrms';

        // Generate the unique surrogate key
        $surrogateKey = 'T-' . time() . '-' . strtoupper(Str::random(6));
        $ticketNumber = $request->ticket ?? 'TCK-' . strtoupper(Str::random(8));

        // Step 3: Create a new ticket (with the new surrogate key)
        Ticket::create([
            'surrogate_key' => $surrogateKey,
            'web_user_id' => $webUser->id,
            'emp_id' => $webUser->emp_id,
            'emp_name' => $webUser->name,
           'ticket' => $ticketNumber,
            'category' => $request->category,
            'assigned_to_id' => $request->assigned_to_id,
            'assigned_to' => $request->assigned_to,
            'assigned_by' => $webUser->name,
            'priority' => $request->priority,
            'date' => $request->date,
            'status' => $status,
            'system_type' => $systemType,
            'assignment_type' => $request->assignment_type,
            'support_by' => $request->support_by,
            'assignee_description' => $request->assignee_description
        ]);

        // Step 4: Return success response
        return response()->json([
            'message' => 'Ticket created successfully',
            'status' => 'success'
        ], 201);
    }

    public function updateTicket(Request $request, $ticketId)
    {
        // Step 1: Validate input
        $validated = $request->validate([
            'web_user_id' => 'required|exists:web_users,id',
            'assigned_to_id' => 'nullable|exists:web_users,id',
            'assigned_to' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
        ]);

        // Step 2: Find the ticket
        $ticket = Ticket::find($ticketId);

        if (!$ticket) {
            return response()->json(['message' => 'Ticket not found'], 404);
        }

        // Step 3: Check if web_user_id is the assigned_to_id
        $assignedUser = WebUser::where('id', $request->web_user_id)->first();

        if (!$assignedUser || ($ticket->assigned_to_id && $request->web_user_id !== $ticket->assigned_to_id)) {
            return response()->json(['message' => 'You are not authorized to update this ticket'], 403);
        }

        $ticket->status = $request->status;

        if($request->assigned_to && $request->assigned_to_id) {
            // Check if the assigned user is in the same company
            $assignedUser = WebUser::find($request->assigned_to_id);

            if (!$assignedUser || $assignedUser->admin_user_id !== $ticket->web_user->admin_user_id) {
                return response()->json(['message' => 'Assigned user is not in the same company'], 403);
            }

            $ticket->assigned_to = $request->assigned_to;
            $ticket->assigned_to_id = $request->assigned_to_id;
            $ticket->status = 'assigned';
        }

        // Step 4: Update the status

        $ticket->save();

        return response()->json([
            'message' => 'Ticket status updated successfully',
            'status' => 'success',
        ], 200);
    }

    public function assignTicket(Request $request, $ticketId)
    {
        // Step 1: Validate the request data
        $validated = $request->validate([
            'web_user_id' => 'required|exists:web_users,id',
            'reassigned_id' => 'required|exists:web_users,id',
            'reason_to_reassign' => 'required|string|max:500',
            'description_to_reassign' => 'required|string|max:1000'
        ]);

        // Step 2: Find the ticket
        $ticket = Ticket::find($ticketId);

        if (!$ticket) {
            return response()->json(['message' => 'Ticket not found'], 404);
        }

        // Step 3: Find the assigning user and assigned user
        $assigningUser = WebUser::find($request->web_user_id);
        $assignedUser = WebUser::find($request->reassigned_id);

        if (!$assigningUser || !$assignedUser) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Step 4: Check if the assigned user is in the same company
        // if ($assignedUser->admin_user_id !== $assigningUser->admin_user_id) {
        //     return response()->json(['message' => 'Assigned user is not in the same company'], 403);
        // }

        // Step 5: Update the ticket with assignment information
        $ticket->update([
            'assigned_to_id' => $request->reassigned_id,
            'assigned_to' => $assignedUser->name,
            'assigned_by' => $assigningUser->name,
            'reassigned_id' => $request->reassigned_id,
            'reason_to_reassign' => $request->reason_to_reassign,
            'description_to_reassign' => $request->description_to_reassign,
            'status' => 'assigned'
        ]);

        return response()->json([
            'message' => 'Ticket assigned successfully',
            'status' => 'success'
        ], 200);
    }

    public function updateWorkStatus(Request $request, $ticketId)
    {
        // Change 'required' to 'sometimes|string' for work_status
        $validated = $request->validate([
            'work_status'        => 'sometimes|string|in:pending,in_progress,completed,reassigned,on_hold,cancelled',
            'work_status_reason' => 'nullable|string|max:1000',
            'task_status'        => 'nullable|string|in:pending,in_progress,completed,on_hold',
            'task_description'   => 'nullable|string|max:2000',
        ]);

        // ... the rest of the function remains the same ...
        $ticket = Ticket::find($ticketId);

        if (!$ticket) {
            return response()->json(['message' => 'Ticket not found'], 404);
        }

        $ticket->fill($validated);
        $ticket->save();

        return response()->json([
            'message' => 'Ticket updated successfully',
            'status' => 'success',
            'data' => $ticket
        ], 200);
    }
}
