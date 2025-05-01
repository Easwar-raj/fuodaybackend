<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HrmsController extends Controller
{
    public function index()
    {
        return view('hrms.layouts.home');
    }
}
