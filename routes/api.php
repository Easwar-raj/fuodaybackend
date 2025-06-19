<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\admin\AdminUserController;
use App\Http\Controllers\admin\AdminController;
use App\Http\Controllers\ats\CandidatePageController;
use App\Http\Controllers\ats\HomePageController as AtsHomePageController;
use App\Http\Controllers\ats\TrackerPageController;
use App\Http\Controllers\EnquiriesController;
use App\Http\Controllers\hrms\AttendancePageController;
use App\Http\Controllers\hrms\HomePageController;
use App\Http\Controllers\hrms\HrPageController;
use App\Http\Controllers\hrms\LeaveTrackerPageController;
use App\Http\Controllers\hrms\PayrollPageController;
use App\Http\Controllers\hrms\PerformancePageController;
use App\Http\Controllers\hrms\ProfilePageController;
use App\Http\Controllers\WebpageUserController;
use App\Http\Controllers\hrms\SupportPageController;
use App\Http\Controllers\hrms\TimeTrackerPageController;
use App\Http\Controllers\hrms\EnqeriesController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
Route::post('/admin/login', [AdminController::class, 'handleLogin']);

Route::prefix('admin-users')->group(function () {
    Route::post('/create', [AdminUserController::class, 'createAdminUser']);
    Route::post('/login', [AdminUserController::class, 'adminlogin']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/save-selection', [AdminUserController::class, 'saveSelection']);
        Route::post('/get-selections', [AdminUserController::class, 'getSelectedSections']);
        Route::post('/logout', [AdminUserController::class, 'logout']);
        Route::get('/getadmindata/{id}', [AdminUserController::class, 'getAllAdminPanelData']);
        Route::get('/getallwebusers/{id}', [AdminUserController::class, 'getAllWebUsers']);
        Route::get('/get/abouts', [AdminUserController::class, 'getAboutByWebUserId']);
        Route::prefix('save')->group(function () {
            Route::post('/heirarchy', [AdminUserController::class, 'saveHeirarchy']);
            Route::post('/holiday', [AdminUserController::class, 'saveHoliday']);
            Route::post('/industry', [AdminUserController::class, 'saveIndustry']);
            Route::post('/service', [AdminUserController::class, 'saveService']);
            Route::post('/client', [AdminUserController::class, 'saveClient']);
            Route::post('/about', [AdminUserController::class, 'saveAbout']);
            Route::post('/event', [AdminUserController::class, 'saveEvent']);
            Route::post('/achievement', [AdminUserController::class, 'saveAchievements']);
            Route::post('/feedbackquestions', [AdminUserController::class, 'saveFeedbackQuestions']);
            Route::post('/jobopenings', [AdminUserController::class, 'saveJobOpenings']);
            Route::post('/projects', [AdminUserController::class, 'saveProjects']);
            Route::post('/totalleaves', [AdminUserController::class, 'saveTotalLeaves']);
            Route::post('/policies', [AdminUserController::class, 'savePolicies']);
        });
        Route::prefix('delete')->group(function () {
            Route::delete('/heirarchy', [AdminUserController::class, 'deleteHeirarchy']);
            Route::delete('/holiday', [AdminUserController::class, 'deleteHoliday']);
            Route::delete('/industry', [AdminUserController::class, 'deleteIndustry']);
            Route::delete('/service', [AdminUserController::class, 'deleteService']);
            Route::delete('/about', [AdminUserController::class, 'deleteAbout']);
            Route::delete('/client', [AdminUserController::class, 'deleteclient']);
            Route::delete('/event', [AdminUserController::class, 'deleteEvent']);
            Route::delete('/achievement', [AdminUserController::class, 'deleteAchievements']);
            Route::delete('/feedbackquestions', [AdminUserController::class, 'deleteFeedbackQuestions']);
            Route::delete('/jobopenings', [AdminUserController::class, 'deleteJobOpenings']);
            Route::delete('/projects', [AdminUserController::class, 'deleteProjects']);
            Route::delete('/totalleaves', [AdminUserController::class, 'deleteTotalLeaves']);
            Route::delete('/policies', [AdminUserController::class, 'deletePolicies']);
        });
    });
});

Route::prefix('web-users')->group(function () {
    Route::post('/login', [WebpageUserController::class, 'userlogin']);   // Login
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/', [WebpageUserController::class, 'getAllUsers']); // Get all users
        Route::post('/save', [WebpageUserController::class, 'saveWebUser']); // Create a new web user
        Route::post('/all', [WebpageUserController::class, 'index']); // Get all users with optional filtering by role
        Route::post('/update/{id}', [WebpageUserController::class, 'update']); // Update user
        Route::get('/getwebuserbyid', [WebpageUserController::class, 'getWebUserById']);
        Route::delete('/{id}', [WebpageUserController::class, 'destroy']); // Delete user
        Route::post('/logout', [WebpageUserController::class, 'logout']);  // Logout
    });
});

Route::prefix('hrms')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::prefix('home')->group(function () {
            Route::get('/getactivities/{id}', [HomePageController::class, 'getActivities']);

            // Route::get('/getprofile/{id}', [HomePageController::class, 'getEmployeeData']);
            // Route::get('/getleaves/{id}', [HomePageController::class, 'getLeaveSummary']);
            // Route::get('/getattendance/{id}', [HomePageController::class, 'getAttendance']);
            // Route::get('/getapprovals/{id}', [HomePageController::class, 'getApprovals']);

            Route::get('/getdashboard/{id}', [HomePageController::class, 'getDashboardDetails']);
            Route::get('/getschedules/{id}', [HomePageController::class, 'getSchedules']);

            Route::get('/getreportees/{id}', [HomePageController::class, 'getAllReportees']);
            Route::get('/getprojects/{id}', [HomePageController::class, 'getHandledProjects']);
            Route::get('/getdepartments/{id}', [HomePageController::class, 'getEmployeesGroupedByDepartment']);
            Route::get('/getteam/{id}', [HomePageController::class, 'getTeamByDepartment']);

            Route::get('/getabout/{id}', [HomePageController::class, 'getAbout']);
            Route::get('/getservice/{id}', [HomePageController::class, 'getServices']);
            Route::get('/getindustries/{id}', [HomePageController::class, 'getIndustries']);
            Route::get('/getclients/{id}', [HomePageController::class, 'getClients']);
            Route::get('/getteamdescription/{id}', [HomePageController::class, 'getTeamDescription']);

            Route::get('/getfeeds/{id}', [HomePageController::class, 'getFeeds']);
            Route::post('/addtask', [HomePageController::class, 'createTask']);
        });
        Route::prefix('profile')->group(function () {
            Route::get('/getprofile/{id}', [ProfilePageController::class, 'getProfile']);
            Route::post('/updateemployeeprofile', [ProfilePageController::class, 'updateEmployeeProfile']);
            Route::post('/deleteskill', [ProfilePageController::class, 'deleteSkill']);
            Route::post('/deleteeducation', [ProfilePageController::class, 'deleteEducation']);
            Route::post('/deleteexperience', [ProfilePageController::class, 'deleteExperience']);
            Route::post('/updateskills', [ProfilePageController::class, 'updateOrCreateSkill']);
            Route::post('/updateeducation', [ProfilePageController::class, 'updateOrCreateEducation']);
            Route::post('/updateexperience', [ProfilePageController::class, 'updateOrCreateExperience']);
            Route::post('/updateonboarding', [ProfilePageController::class, 'updateOnboardingDocuments']);
        });
        Route::prefix('attendance')->group(function () {
            Route::get('/getattendances/{id}', [AttendancePageController::class, 'getAttendance']);
            Route::get('/getattendancebyrole/{id}', [AttendancePageController::class, 'getAttendanceByRole']);
            Route::post('/addattendance', [AttendancePageController::class, 'addAttendance']);
            Route::post('/updateattendance', [AttendancePageController::class, 'updateAttendance']);
            Route::get('/gettoday/{id}', [AttendancePageController::class, 'getTodayAttendance']);
        });
        Route::prefix('leave')->group(function () {
            Route::get('/getleave/{id}', [LeaveTrackerPageController::class, 'getLeaveStatus']);
            Route::post('/addleave', [LeaveTrackerPageController::class, 'addLeave']);
            Route::post('/updateleave', [LeaveTrackerPageController::class, 'updateLeaveStatus']);
        });
        Route::prefix('timetracker')->group(function () {
            Route::get('/gettracker/{id}', [TimeTrackerPageController::class, 'gettimetracker']);
            Route::post('/addschedule', [TimeTrackerPageController::class, 'addShiftSchedule']);
            Route::post('/getschedules', [TimeTrackerPageController::class, 'getMonthlyShifts']);
        });
        Route::prefix('hr')->group(function () {
            Route::get('/gethr/{id}', [HrPageController::class, 'getHr']);
            Route::get('/getpendingleaves/{id}', [HrPageController::class, 'getPendingLeaveRequests']);
            Route::get('/getallwebusers/{id}', [HrPageController::class, 'getWebUsers']);
        });
        Route::prefix('payroll')->group(function () {
            Route::get('/getpayroll/{id}', [PayrollPageController::class, 'getPayrollDetails']);
            Route::get('/getoverview/{id}', [PayrollPageController::class, 'getCurrentPayrollDetails']);
        });
        
        Route::prefix('support')->group(function () {
            Route::get('/gettickets/{id}', [SupportPageController::class, 'getAllTicketsByStatus']);
            Route::post('/addticket', [SupportPageController::class, 'addTicket']);
            Route::post('/updateticket/{ticketId}', [SupportPageController::class, 'updateTicket']);
        });
    });
    Route::prefix('enquiry')->group(function () {
        Route::post('/addenquiry', [EnquiriesController::class, 'addInquiry']);
        Route::get('/getenquiries', [EnquiriesController::class, 'getInquiry']);
    });
    Route::prefix('performance')->group(function () {
            Route::get('/getgoals/{id}', [PerformancePageController::class, 'getUserTasks']);
            Route::post('/updatetasks', [PerformancePageController::class, 'updateTaskStatus']);
            Route::get('/getteamperformance/{id}', [PerformancePageController::class, 'getTeamPerformance']);
            Route::get('/getfeedbacks/{id}', [PerformancePageController::class, 'getUserFeedbackDetails']);
            Route::get('/getfeedbackquestions/{id}', [PerformancePageController::class, 'getFeedbackQuestions']);
            Route::post('/addfeedback', [PerformancePageController::class, 'addFeedback']);
            Route::post('/updatefeedback/{id}', [PerformancePageController::class, 'updateFeedback']);
            Route::post('/addfeedbackreply', [PerformancePageController::class, 'addFeedbackReply']);
            Route::get('/getheirarchy/{id}', [PerformancePageController::class, 'getHeirarchies']);
            Route::get('/getemployeeaudit/{id}', [PerformancePageController::class, 'getEnployeeAudit']);
            Route::post('/addaudit', [PerformancePageController::class, 'addAudit']);
            Route::post('/updateaudit/{id}', [PerformancePageController::class, 'updateAudit']);
            Route::get('/getauditreport/{id}', [PerformancePageController::class, 'getAuditReport']);
            Route::get('/getauditreportingteam/{id}', [PerformancePageController::class, 'getAuditReportingTeam']);
        });
});

Route::prefix('ats')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::prefix('home')->group(function () {
            Route::get('/getdashboard/{id}', [AtsHomePageController::class, 'getDashboardDetails']);
        });
        Route::prefix('candidates')->group(function () {
            Route::get('/getcandidates', [CandidatePageController::class, 'getCandidates']);
            Route::post('/addcandidate', [CandidatePageController::class, 'addCandidate']);
        });
        Route::prefix('tracker')->group(function () {
            Route::get('/gettracker', [TrackerPageController::class, 'getTrackerData']);
        });
    });
});

Route::post('/forgot-password', [WebpageUserController::class, 'sendResetLinkEmail'])->name('password.email');
Route::post('/reset-password', [WebpageUserController::class, 'reset'])->name('password.update');
Route::get('/verify-attendance', [PayrollPageController::class, 'runAttendanceVerification']);
