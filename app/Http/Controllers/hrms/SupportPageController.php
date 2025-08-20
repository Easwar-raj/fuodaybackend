<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use App\Models\WebUser;
use App\Models\Ticket;
use Illuminate\Http\Request;

class SupportPageController extends Controller
{
    public function getAllTicketsByStatus($id)
    {
        $webUser = WebUser::find($id);
        $type = ($webUser->role !== 'employee' && $webUser->role !== 'hr') ? 'ats' : 'hrms';
        $tickets = Ticket::where('system_type', $type)
            ->where(function($query) use ($id) {
                $query->where('web_user_id', $id)->orWhere('assigned_to_id', $id);
            })->get();

        $groupedTickets = $tickets->groupBy(function ($ticket) {
            $status = strtolower($ticket->status ?? 'unassigned');
            if (in_array($status, ['unassigned', 'assigned', 'in_progress', 'completed'])) {
                return $status;
            }
            return 'unassigned';
        });

        $employeeNames = WebUser::where('admin_user_id', $webUser->admin_user_id)->select('id', 'name', 'emp_id')->get();

        return response()->json([
            'message' => 'Successfully fetched tickets',
            'status' => 'success',
            'data' => [
                'groupedTickets' => $groupedTickets,
                'assignees' => $employeeNames
            ],
        ], 200);
    }

    public function addTicket(Request $request)
    {
        // Step 1: Validate the request data
        $validated = $request->validate([
            'web_user_id' => 'nullable|exists:web_users,id',
            'ticket' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'assigned_to_id' => 'nullable|exists:web_users,id',
            'assigned_to' => 'nullable|string|max:255',
            'priority' => 'required|string|max:100',
            'date' => 'required|date'
        ]);

        $webUser = WebUser::find($request->web_user_id);

        if (!$validated || !$webUser) {
            return response()->json([
                'message' => 'Invalid data or user not found'
            ], 400);
        }

        $status = 'unassigned';

        // Step 2: If assigned_to_id is provided, check that it has the same admin_user_id
        if ($request->assigned_to_id) {
            $assignedUser = WebUser::find($request->assigned_to_id);

            if (!$assignedUser || $assignedUser->admin_user_id !== $webUser->admin_user_id) {
                return response()->json([
                    'message' => 'Assigned user is not in the same company.'
                ], 403);
            }
            $status = 'assigned';
        }

        $systemType = ($webUser->role !== 'employee' && $webUser->role !== 'hr') ? 'ats' : 'hrms';

        // Step 3: Create a new ticket
        Ticket::create([
            'web_user_id' => $webUser->id,
            'emp_id' => $webUser->emp_id,
            'emp_name' => $webUser->name,
            'ticket' => $request->ticket,
            'category' => $request->category,
            'assigned_to_id' => $request->assigned_to_id,
            'assigned_to' => $request->assigned_to,
            'assigned_by' => $webUser->name,
            'priority' => $request->priority,
            'date' => $request->date,
            'status' => $status,
            'system_type' => $systemType
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

}

