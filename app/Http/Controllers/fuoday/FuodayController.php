<?php

namespace App\Http\Controllers\fuoday;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;

class FuodayController extends Controller
{
    public function index()
    {
        return view('fuoday.layouts.home');
    }
}
