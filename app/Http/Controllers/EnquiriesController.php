<?php

namespace App\Http\Controllers;

use App\Models\Enquiries;
use Illuminate\Http\Request;

class EnquiriesController extends Controller
{
    public function addInquiry(Request $request)
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'contact_number' => 'required|string|max:20',
            'message' => 'nullable|string',
            'date' => 'nullable|date',
        ]);

        if (!$validated) {
            return response()->json([
                'message' => 'Unauthorized',
                'status' => 'error'
            ], 401);
        }

        $enquiry = Enquiries::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Enquiry submitted successfully.',
            'data' => $enquiry
        ], 201);
    }

    public function getInquiry()
    {
        $enquiries = Enquiries::latest()->get();

        return response()->json([
            'success' => true,
            'data' => $enquiries
        ]);
    }
}
