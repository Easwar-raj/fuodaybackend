<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\fuoday\FuodayController;
use App\Http\Controllers\ats\AtsController;
use App\Http\Controllers\hrms\HrmsController;
use App\Http\Controllers\ErrorController;
use App\Http\Controllers\WebpageUserController;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/


Route::domain(config('app.domains.fuoday'))->group(function () {
    Route::get('/', [FuodayController::class, 'index'])->name('fuoday.layouts.home');
});


Route::domain(config('app.domains.ats'))->group(function () {
    Route::get('/', [AtsController::class, 'index'])->name('ats.layouts.home');
});

Route::domain(config('app.domains.hrms'))->group(function () {
    Route::get('/', [HrmsController::class, 'index'])->name('hrms.layouts.home');
});

Route::get('/not-found', [ErrorController::class, 'forbidden'])->name('error.page');
