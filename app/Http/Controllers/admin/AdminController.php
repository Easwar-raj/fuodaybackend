<?php

namespace App\Http\Controllers\admin;

use Illuminate\Http\Request;

use App\Http\Controllers\Controller;

class AdminController extends Controller
{

    // Hardcoded admin credentials
    private $adminEmail = 'admin@example.com';
    private $adminPassword = 'Admin123';

    // Handle admin login
    public function handleLogin(Request $request)
    {
        // Validate request inputs
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Check credentials
        if ($request->email === $this->adminEmail && $request->password === $this->adminPassword) {
            return response()->json(['message' => 'Login successful', 'status' => 'Success'], 200);
        }

        return response()->json(['message' => 'Invalid credentials', 'status' => 401], 401);
    }
}
