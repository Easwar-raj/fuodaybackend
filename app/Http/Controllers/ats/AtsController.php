<?php


namespace App\Http\Controllers\ats;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AtsController extends Controller
{
    public function index()
    {
        return view('ats.layouts.home');
    }
}
