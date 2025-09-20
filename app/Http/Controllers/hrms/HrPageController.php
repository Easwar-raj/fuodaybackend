<?php

namespace App\Http\Controllers\hrms;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\JobOpening;
use App\Models\LeaveRequest;
use App\Models\Projects;
use App\Models\WebUser;
use App\Models\Event;
use App\Models\Audits;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use App\Models\Incentives;
use App\Models\Payroll;
use Exception;
use NumberToWords\NumberToWords;
use App\Models\EmployeeDetails;
use Illuminate\Support\Facades\Log;

class HrPageController extends Controller
{
    public function getHr($id)
    {
        // Step 1: Get the admin_user_id from the given web_user_id
        $webUser = WebUser::findOrFail($id);
        $adminUserId = $webUser->admin_user_id;

        // Step 2: Get all web user IDs under this admin (avoiding duplicate query)
        $webUserIds = WebUser::where('admin_user_id', $adminUserId)->pluck('id');

        // Total Employees
        $totalEmployees = $webUserIds->count();
        $lastWeekCount = WebUser::where('admin_user_id', $adminUserId)
            ->whereBetween('created_at', [now()->startOfWeek()->subWeek(), now()->endOfWeek()->subWeek()])
            ->count();
        $thisWeekCount = WebUser::where('admin_user_id', $adminUserId)
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();
        $employeeChange = $lastWeekCount > 0 ? (($thisWeekCount - $lastWeekCount) / $lastWeekCount) * 100 : 100;

        // Total Leave Requests
        $totalLeaveRequests = LeaveRequest::whereIn('web_user_id', $webUserIds)->count();
        $lastWeekLeaves = LeaveRequest::whereIn('web_user_id', $webUserIds)
            ->whereBetween('created_at', [now()->startOfWeek()->subWeek(), now()->endOfWeek()->subWeek()])
            ->count();
        $thisWeekLeaves = LeaveRequest::whereIn('web_user_id', $webUserIds)
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();
        $leaveChange = $lastWeekLeaves > 0 ? (($thisWeekLeaves - $lastWeekLeaves) / $lastWeekLeaves) * 100 : 100;

        // Attendance (Permissions)
        $totalPermissions = LeaveRequest::whereIn('web_user_id', $webUserIds)->where('type', 'Permission')->count();
        $lastWeekPermissions = LeaveRequest::whereIn('web_user_id', $webUserIds)->where('type', 'Permission')
            ->whereBetween('date', [now()->startOfWeek()->subWeek(), now()->endOfWeek()->subWeek()])
            ->count();
        $thisWeekPermissions = LeaveRequest::whereIn('web_user_id', $webUserIds)->where('type', 'Permission')
            ->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();
        $permissions = LeaveRequest::whereIn('web_user_id', $webUserIds)->where('type', 'Permission')->get();
        $permissionChange = $lastWeekPermissions > 0 ? (($thisWeekPermissions - $lastWeekPermissions) / $lastWeekPermissions) * 100 : 100;

        // Attendance Today
        $attendanceToday = Attendance::whereIn('web_user_id', $webUserIds)
            ->whereDate('date', today())
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $early = $attendanceToday['Early'] ?? 0;
        $regular = $attendanceToday['Regular'] ?? 0;
        $late = $attendanceToday['Late'] ?? 0;
        $totalAttendance = $early + $regular + $late;

        // Employee Growth Chart by Role and Year
        $employeeChart = WebUser::where('admin_user_id', $adminUserId)
            ->selectRaw('YEAR(created_at) as year, role, COUNT(*) as total')
            ->groupBy('year', 'role')
            ->orderBy('year')
            ->get()
            ->groupBy('year');

        // Projects + Team Members
        $projects = Projects::where('admin_user_id', $adminUserId)
            ->with(['projectTeam' => function ($query) use ($adminUserId) {
                $query->whereHas('webUser', function ($q) use ($adminUserId) {
                    $q->where('admin_user_id', $adminUserId);
                });
            }, 'projectTeam.webUser'])
            ->orderBy('deadline')
            ->take(4)
            ->get()
            ->map(function ($project) {
                return [
                    'name' => $project->name,
                    'domain' => $project->domain,
                    'deadline' => $project->deadline->format('Y-m-d'),
                    'team_members' => $project->projectTeam->map(function ($team) {
                        return [
                            'name' => $team->webUser->name ?? null,
                            'role' => $team->webUser->role ?? null,
                        ];
                    }),
                ];
            });

        // Recent Employees
        $recentEmployees = WebUser::where('admin_user_id', $adminUserId)
            ->with(['employeeDetails:id,web_user_id,profile_photo,date_of_joining'])
            ->get(['id', 'name', 'role', 'emp_id'])
            ->sortByDesc(fn ($user) => optional($user->employeeDetails)->date_of_joining)
            ->take(4)
            ->values()
            ->map(function ($user) {
                $date = $user->employeeDetails->date_of_joining ?? null;
                if ($date) {
                    $user->employeeDetails->date_of_joining = Carbon::parse($date)->format('Y-m-d');
                }
                return $user;
            });

        // Open Positions
        $openPositions = JobOpening::where('admin_user_id', $adminUserId)
            ->where('status', 'Open')
            ->take(3)
            ->get(['title', 'posted_at', 'no_of_openings']);

        $events = Event::where('admin_user_id', $adminUserId)->get();

        $audits = Audits::all()->filter(function ($audit) use ($id) {
            if ($audit->management_review) {
                $parts = explode('%', $audit->management_review);
                return isset($parts[0]) && (int)$parts[0] === $id;
            }
            return false;
        });

        return response()->json([
            'message' => 'HR Dashboard Data Retrieved Successfully',
            'status' => 'Success',
            'data' => [
                'stats' => [
                    'totalEmployees' => $totalEmployees,
                    'employeeChange' => round($employeeChange, 2),
                    'totalLeaveRequests' => $totalLeaveRequests,
                    'leaveChange' => round($leaveChange, 2),
                    'totalPermissions' => $totalPermissions,
                    'permissionChange' => round($permissionChange, 2),
                    'totalAttendance' => $totalAttendance,
                    'totalLateArrival' => $late,
                    'totalEvent' => $events->count(),
                    'totalAudits' => $audits->count()
                ],
                'attendanceToday' => [
                    'early' => $early,
                    'regular' => $regular,
                    'late' => $late,
                    'total' => $totalAttendance,
                ],
                'employeeChart' => $employeeChart,
                'projects' => $projects,
                'recentEmployees' => $recentEmployees,
                'openPositions' => $openPositions,
                'events' => $events,
                'audits' => $audits,
                'permissions' => $permissions
            ],
        ], 200);
    }

    public function getPendingLeaveRequests($id)
    {
        // Step 1: Get the admin_user_id of this web user
        $webUser = WebUser::find($id);

        if (!$webUser->admin_user_id || $webUser->role !== 'hr') {
            return response()->json(['message' => 'Invalid details'], 403);
        }

        // Step 2: Get all web user IDs under this admin
        $webUserIds = WebUser::where('admin_user_id', $webUser->admin_user_id)->pluck('id')->toArray();

        // Step 3: Get pending leave requests for these web users
        $pendingLeaves = LeaveRequest::whereIn('web_user_id', $webUserIds)
            ->where('status', 'pending')
            ->get();

        return response()->json([
            'status' => 'Success',
            'message' => 'Pending leaves retrieved successfully',
            'data' =>  $pendingLeaves
        ]);
    }

    public function getWebUsers($id)
    {
        $webUser = WebUser::find($id);

        if (!$webUser || !$webUser->admin_user_id) {
            return response()->json([
                'message' => 'User not found.'
            ], 404);
        }

        $response = [];

        // HR Users Section
        $hrUsers = WebUser::where('admin_user_id', $webUser->admin_user_id)
            ->get(['id as web_user_id', 'emp_id', 'email', 'name as emp_name', 'role']);

        $response['hr'] = [
            'total_count' => $hrUsers->count(),
            'data' => $hrUsers
        ];

        // Manager Users Section
        $reportingEmployees = EmployeeDetails::where('reporting_manager_id', $id)
            ->get(['web_user_id', 'reporting_manager_id', 'reporting_manager_name']);

        $managerTeam = WebUser::whereIn('id', $reportingEmployees->pluck('web_user_id'))
            ->get(['id as web_user_id', 'emp_id', 'email', 'name as emp_name', 'role']);

        $managerTeam = $managerTeam->map(function ($member) use ($reportingEmployees) {
            $details = $reportingEmployees->firstWhere('web_user_id', $member->web_user_id);
            return [
                'web_user_id' => $member->web_user_id,
                'emp_id' => $member->emp_id,
                'email' => $member->email,
                'emp_name' => $member->emp_name,
                'role' => $member->role,
                'reporting_manager_id' => $details->reporting_manager_id ?? null,
                'reporting_manager_name' => $details->reporting_manager_name ?? null,
            ];
        });

        $response['manager'] = [
            'total_count' => $managerTeam->count(),
            'data' => $managerTeam
        ];

        // ----------------- START: NEW TEAMS SECTION -----------------

        // Get the web_user_ids of employees who are in this user's team.
        $teamEmployeeIds = EmployeeDetails::where('team_id', $id)->pluck('web_user_id');

        // Fetch the details for those employees from the WebUser table.
        $teamMembers = WebUser::whereIn('id', $teamEmployeeIds)
            ->get(['id as web_user_id', 'emp_id', 'email', 'name as emp_name', 'role']);

        $response['teams'] = [
            'total_count' => $teamMembers->count(),
            'data' => $teamMembers
        ];

        // ----------------- END: NEW TEAMS SECTION -----------------


        return response()->json([
            'status' => 'Success',
            'message' => 'HR, Manager, and Teams data fetched successfully',
            'data' => $response
        ], 200);
    }
    
    public function downloadEmployees(Request $request): \Symfony\Component\HttpFoundation\Response|\Illuminate\Http\JsonResponse

    {
        try {
            $format = $request->query('format', 'xlsx'); // default to xlsx

            $query = DB::table('employee_details');

            if ($request->has('emp_id') && $request->filled('emp_id')) {
                $query->where('emp_id', $request->query('emp_id'));
            }

            if ($request->has('emp_name') && $request->filled('emp_name')) {
                $query->where('emp_name', 'LIKE', '%' . $request->query('emp_name') . '%');
            }

            $employees = $query->get();

            if ($employees->isEmpty()) {
                return response()->json(['message' => 'No matching employees found.'], 404);
            }

            //  Excel Format
            if ($format === 'xlsx') {
                $excelData = collect([]);
                $excelData->push([
                    'ID', 'Emp Name', 'Emp ID', 'Gender', 'Place', 'Designation', 'Department', 'Employment Type', 'About', 'Role Location',
                    'Work Mode', 'DOB', 'Address', 'Date of Joining', 'Reporting Manager ID', 'Reporting Manager Name',
                    'Aadhaar No', 'PAN No', 'Blood Group', 'Personal Contact', 'Emergency Contact',
                    'Official Contact', 'Official Email', 'Permanent Address', 'Bank Name', 'Account No', 'IFSC',
                    'PF Account No', 'UAN', 'ESI No', 'Created At', 'Updated At'
                ]);

                foreach ($employees as $emp) {
                    $excelData->push([
                        $emp->id ?? '', $emp->emp_name ?? '', $emp->emp_id ?? '', $emp->gender ?? '', $emp->place ?? '',
                        $emp->designation ?? '', $emp->department ?? '', $emp->employment_type ?? '', $emp->about ?? '',
                        $emp->role_location ?? '', $emp->work_mode ?? '', $emp->dob ?? '', $emp->address ?? '',
                        $emp->date_of_joining ?? '', $emp->reporting_manager_id ?? '', $emp->reporting_manager_name ?? '',
                        $emp->aadhaar_no ?? '', $emp->pan_no ?? '', $emp->blood_group ?? '', $emp->personal_contact_no ?? '',
                        $emp->emergency_contact_no ?? '', $emp->official_contact_no ?? '', $emp->official_email ?? '',
                        $emp->permanent_address ?? '', $emp->bank_name ?? '', $emp->account_no ?? '', $emp->ifsc ?? '',
                        $emp->pf_account_no ?? '', $emp->uan ?? '', $emp->esi_no ?? '', $emp->created_at ?? '', $emp->updated_at ?? '',
                    ]);
                }

                return Excel::download(new class($excelData) implements \Maatwebsite\Excel\Concerns\FromCollection {
                    private $data;
                    public function __construct($data) { $this->data = $data; }
                    public function collection(): Collection { return $this->data; }
                }, 'employee_details.xlsx', ExcelFormat::XLSX);
            }

            if ($format === 'pdf') {
                $pdf = Pdf::loadView('pdf.employee_list', ['employees' => $employees]);
                return $pdf->download('employee_details.pdf');
            }

            return response()->json(['error' => 'Invalid format. Use ?format=pdf or ?format=xlsx'], 400);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    public function getAllLeaveRequestsByStatus(Request $request, $status = null, $managerId = null, $teamId = null)
    {
        try {
            $user = Auth::user();
            $webUser = WebUser::find($user->id);

            $validStatuses = ['Pending', 'Approved', 'Rejected'];
            $status = ucfirst(strtolower($status ?? $request->input('status')));

            if (!$status || !in_array($status, $validStatuses)) {
                return response()->json(['message' => 'Invalid or missing status value'], 400);
            }

            /**
             * HR SECTION (all employees under same admin_user_id)
             */
            $employeeIds = WebUser::where('admin_user_id', $webUser->admin_user_id)->pluck('id');

            $hrLeaveRequests = LeaveRequest::with(['webUser:id,name,emp_id'])
                ->whereIn('web_user_id', $employeeIds)
                ->where('status', $status)
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($leave) {
                    return [
                        'id' => $leave->id,
                        'web_user_id' => $leave->web_user_id,
                        'name' => $leave->webUser->name ?? null,
                        'emp_id' => $leave->webUser->emp_id ?? null,
                        'date' => $leave->date,
                        'type' => $leave->type,
                        'from' => $leave->from,
                        'to' => $leave->to,
                        'reason' => $leave->reason,
                        'permission_timing' => $leave->permission_timing,
                        'hr_status' => $leave->hr_status,
                        'manager_status' => $leave->manager_status,
                        'status' => $leave->status,
                        'regulation_date' => $leave->regulation_date,
                        'regulation_reason' => $leave->regulation_reason,
                    ];
                });

            /**
             * MANAGER SECTION (employees who report to a manager)
             */
            $currentManagerId = $managerId ?? $webUser->id;
            $managerInfo = WebUser::find($currentManagerId);
            $reportingEmployeeIds = EmployeeDetails::where('reporting_manager_id', $currentManagerId)->pluck('web_user_id');

            $managerLeaveRequests = collect();
            if ($reportingEmployeeIds->isNotEmpty()) {
                $managerLeaveRequests = LeaveRequest::with(['webUser:id,name,emp_id'])
                    ->whereIn('web_user_id', $reportingEmployeeIds)
                    ->where('status', $status)
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(function ($leave) use ($currentManagerId, $managerInfo) {
                        return [
                            'id' => $leave->id,
                            'web_user_id' => $leave->web_user_id,
                            'name' => $leave->webUser->name ?? null,
                            'emp_id' => $leave->webUser->emp_id ?? null,
                            'date' => $leave->date,
                            'type' => $leave->type,
                            'from' => $leave->from,
                            'to' => $leave->to,
                            'reason' => $leave->reason,
                            'permission_timing' => $leave->permission_timing,
                            'status' => $leave->status,
                            'regulation_date' => $leave->regulation_date,
                            'regulation_reason' => $leave->regulation_reason,
                            'reporting_manager_id' => $currentManagerId,
                            'reporting_manager_name' => $managerInfo->name ?? null,
                        ];
                    });
            }

            /**
             * TEAM SECTION (employees whose employee_details.team_id == <team leader web_user_id>)
             *
             * Determine which team-leader id to use:
             * - If a $teamId parameter was passed -> use it
             * - Else if a team_id query param was passed -> use it
             * - Else default to logged-in user (useful when the logged-in user is the team lead)
             */
            $currentTeamLeaderId = $teamId ?? $request->input('team_id') ?? $webUser->id;
            $teamLeaveRequests = collect();

            // Get web_user_ids of employees assigned to that team leader id
            $teamEmployeeIds = EmployeeDetails::where('team_id', $currentTeamLeaderId)->pluck('web_user_id');

            if ($teamEmployeeIds->isNotEmpty()) {
                $teamLeaveRequests = LeaveRequest::with(['webUser:id,name,emp_id'])
                    ->whereIn('web_user_id', $teamEmployeeIds)
                    ->where('status', $status)
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(function ($leave) use ($currentTeamLeaderId) {
                        return [
                            'id' => $leave->id,
                            'web_user_id' => $leave->web_user_id,
                            'name' => $leave->webUser->name ?? null,
                            'emp_id' => $leave->webUser->emp_id ?? null,
                            'date' => $leave->date,
                            'type' => $leave->type,
                            'from' => $leave->from,
                            'to' => $leave->to,
                            'reason' => $leave->reason,
                            'permission_timing' => $leave->permission_timing,
                            'status' => $leave->status,
                            'regulation_date' => $leave->regulation_date,
                            'regulation_reason' => $leave->regulation_reason,
                            'team_leader_id' => $currentTeamLeaderId,
                        ];
                    });
            }

            return response()->json([
                'status' => 'Success',
                'message' => $status . ' leave requests retrieved successfully',
                'hr_section' => [
                    'total_count' => $hrLeaveRequests->count(),
                    'data' => $hrLeaveRequests,
                ],
                'manager_section' => [
                    'total_count' => $managerLeaveRequests->count(),
                    'data' => $managerLeaveRequests,
                ],
                'team_section' => [
                    'total_count' => $teamLeaveRequests->count(),
                    'data' => $teamLeaveRequests,
                ],
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in getAllLeaveRequestsByStatus', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'Error',
                'message' => 'Internal server error',
                'error_message' => $e->getMessage()
            ], 500);
        }
    }

    public function getAllEmployeeAttendance($id = null)
    {
        $user = Auth::user();
        $webUser = $id ? WebUser::find($id) : WebUser::find($user->id);

        if (!$webUser) {
            return response()->json([
                'status' => 'Error',
                'message' => 'User not found.',
            ], 404);
        }

        // ---------------- HR SECTION ----------------
        $employeeIds = WebUser::where('admin_user_id', $webUser->admin_user_id)->pluck('id');

        $hrAttendance = Attendance::with(['employee' => function ($q) {
            $q->select('id', 'name', 'emp_id');
        }])->whereIn('web_user_id', $employeeIds)
        ->orderBy('date', 'desc')
        ->get();

        $hrData = $hrAttendance->map(fn($att) => [
            'name' => $att->employee->name ?? 'N/A',
            'emp_id' => $att->employee->emp_id ?? 'N/A',
            'date' => $att->date->format('Y-m-d'),
            'checkin' => $att->checkin,
            'checkout' => $att->checkout,
            'worked_hours' => $att->worked_hours,
            'status' => $att->status,
        ]);

        // ---------------- MANAGER SECTION ----------------
        $managerId = $id ?? $webUser->id;

        $reportingEmployees = EmployeeDetails::where('reporting_manager_id', $managerId)->pluck('web_user_id');

        $managerData = collect();
        if ($reportingEmployees->isNotEmpty()) {
            $managerAttendance = Attendance::with(['employee' => function ($q) {
                $q->select('id', 'name', 'emp_id');
            }])->whereIn('web_user_id', $reportingEmployees)
            ->orderBy('date', 'desc')
            ->get();

            $managerData = $managerAttendance->map(fn($att) => [
                'name' => $att->employee->name ?? 'N/A',
                'emp_id' => $att->employee->emp_id ?? 'N/A',
                'date' => $att->date->format('Y-m-d'),
                'checkin' => $att->checkin,
                'checkout' => $att->checkout,
                'worked_hours' => $att->worked_hours,
                'status' => $att->status,
                'reporting_manager_id' => $managerId,
                'reporting_manager_name' => $webUser->name ?? 'N/A',
            ]);
        }

        // ---------------- TEAM SECTION ----------------
        $teamEmployees = EmployeeDetails::where('team_id', $managerId)->pluck('web_user_id');

        $teamData = collect();
        if ($teamEmployees->isNotEmpty()) {
            $teamAttendance = Attendance::with(['employee' => function ($q) {
                $q->select('id', 'name', 'emp_id');
            }])->whereIn('web_user_id', $teamEmployees)
            ->orderBy('date', 'desc')
            ->get();

            $teamData = $teamAttendance->map(fn($att) => [
                'name' => $att->employee->name ?? 'N/A',
                'emp_id' => $att->employee->emp_id ?? 'N/A',
                'date' => $att->date->format('Y-m-d'),
                'checkin' => $att->checkin,
                'checkout' => $att->checkout,
                'worked_hours' => $att->worked_hours,
                'status' => $att->status,
                'team_id' => $managerId,
            ]);
        }

        // ---------------- RESPONSE ----------------
        return response()->json([
            'status' => 'Success',
            'message' => 'Attendance data retrieved successfully.',

                'hr_section' => [
                    'total_count' => $hrData->count(),
                    'data' => $hrData,
                ],
                'manager_section' => [
                    'total_count' => $managerData->count(),
                    'data' => $managerData,
                ],
                'team_section' => [
                    'total_count' => $teamData->count(),
                    'data' => $teamData,
                ],

        ]);
    }

    public function getAllEmployeePayrollSummaries()
    {
        $user = Auth::user();
        $webUser = WebUser::find($user->id);
        $employees = WebUser::with('employeeDetails')
            ->where('admin_user_id', $webUser->admin_user_id)
            ->get();

        $summaries = $employees->map(function ($user) {
            $userId = $user->id;

            // Total incentives
            $incentives = Incentives::where('web_user_id', $userId)->sum('amount');

            // Get all payrolls (with optional payslip)
            $payrolls = Payroll::where('web_user_id', $userId)
                ->with('payslip')
                ->get();

            $latestPayroll = $payrolls->sortByDesc(function ($payroll) {
                return $payroll->payslip->date ?? $payroll->created_at;
            })->first();

            $payslip = $latestPayroll?->payslip;

            $totalSalary = $payslip->total_salary ?? $latestPayroll->monthly_salary ?? 0;
            $numberToWords = new NumberToWords();
            $numberTransformer = $numberToWords->getNumberTransformer('en');
            $salaryInWords = $totalSalary !== null
                ? ucfirst($numberTransformer->toWords((int) $totalSalary)) . ' only'
                : null;

            $components = Payroll::where('web_user_id', $userId)
                ->whereNotNull('salary_component')
                ->whereNotNull('type')
                ->get()
                ->groupBy('type')
                ->map(function ($items) {
                    return $items->map(function ($item) {
                        return [
                            'component' => $item->salary_component,
                            'amount'    => $item->amount,
                        ];
                    })->values();
                });

            $earnings = $components->get('Earnings') ?? collect();
            $deductions = $components->get('Deductions') ?? collect();

            $computedGross = $earnings->sum('amount');
            $computedDeductions = $deductions->sum('amount');

            $gross = $payslip->gross ?? $computedGross;
            $totalDeductions = $payslip->total_deductions ?? $computedDeductions;

            return [
                'emp_id'              => $user->emp_id,
                'name'                => $user->name,
                'designation'         => $user->employeeDetails->designation ?? $latestPayroll->designation,
                'date_of_joining'     => $user->employeeDetails->date_of_joining ?? null,
                'year'                => now()->year,
                'pf_account_no'       => $user->employeeDetails->pf_account_no ?? null,
                'uan'                 => $user->employeeDetails->uan ?? null,
                'esi_no'              => $user->employeeDetails->esi_no ?? null,
                'bank_account_no'     => $user->employeeDetails->bank_account_no ?? null,
                'total_ctc'           => $latestPayroll->ctc ?? 0,
                'monthly_salary'      => $latestPayroll->monthly_salary ?? 0,
                'latest_payslip_date' => optional($payslip?->date ?? $latestPayroll?->created_at)->format('Y-m-d'),
                'total_paid_days'     => $payslip->total_paid_days ?? 0,
                'lop'                 => $payslip->lop ?? 0,
                'gross'               => $gross ?? 0,
                'total_deductions'    => $totalDeductions ?? 0,
                'total_salary'        => $totalSalary,
                'total_salary_word'   => $salaryInWords,
                'status'              => $payslip->status ?? 'No Payslip',
                'incentives'          => $incentives,
                'salary_components'   => [
                    'earnings'   => $components->get('Earnings') ?? [],
                    'deductions' => $components->get('Deductions') ?? [],
                ],
            ];
        });

        return response()->json([
            'status' => 'Success',
            'message' => 'All employee payroll summaries fetched successfully.',
            'data' => $summaries,
        ]);
    }


    public function getEmployeePayrollSummaryById($id)
    {
        $user = WebUser::with('employeeDetails')->find($id);

        if (!$user) {
            return response()->json([
                'status' => 'Error',
                'message' => 'Employee not found.',
            ], 404);
        }

        $incentives = Incentives::where('web_user_id', $id)->sum('amount');

        $latestPayroll = Payroll::where('web_user_id', $id)
            ->whereHas('payslip')
            ->with('payslip')
            ->get()
            ->sortByDesc(function ($payroll) {
                return $payroll->payslip->date ?? now()->subYears(10);
            })
            ->first();

        $payslip = $latestPayroll?->payslip;

        $totalSalary = $payslip->total_salary ?? 0;

        $numberToWords = new NumberToWords();
        $numberTransformer = $numberToWords->getNumberTransformer('en');
        $salaryInWords = $totalSalary !== null
            ? ucfirst($numberTransformer->toWords((int) $totalSalary)) . ' only'
            : null;

        $components = Payroll::where('web_user_id', $id)
            ->whereNotNull('salary_component')
            ->whereNotNull('type')
            ->get()
            ->groupBy('type')
            ->map(function ($items) {
                return $items->map(function ($item) {
                    return [
                        'component' => $item->salary_component,
                        'amount'    => $item->amount,
                    ];
                })->values();
            });

        $summary = [
            'emp_id'              => $user->emp_id,
            'name'                => $user->name,
            'designation'         => $user->employeeDetails->designation ?? null,
            'date_of_joining'     => $user->employeeDetails->date_of_joining ?? null,
            'year'                => now()->year,
            'pf_account_no'       => $user->employeeDetails->pf_account_no ?? null,
            'uan'                 => $user->employeeDetails->uan ?? null,
            'esi_no'              => $user->employeeDetails->esi_no ?? null,
            'bank_account_no'     => $user->employeeDetails->bank_account_no ?? null,
            'total_ctc'           => $latestPayroll->ctc ?? 0,
            'monthly_salary'      => $latestPayroll->monthly_salary ?? 0,
            'latest_payslip_date' => optional($payslip?->date)->format('Y-m-d'),
            'total_paid_days'     => $payslip->total_paid_days ?? 0,
            'lop'                 => $payslip->lop ?? 0,
            'gross'               => $payslip->gross ?? 0,
            'total_deductions'    => $payslip->total_deductions ?? 0,
            'total_salary'        => $totalSalary,
            'total_salary_word'   => $salaryInWords,
            'status'              => $payslip->status ?? 'N/A',
            'incentives'          => $incentives,
            'salary_components'   => [
                'earnings'   => $components->get('Earnings') ?? [],
                'deductions' => $components->get('Deductions') ?? [],
            ],
        ];

        return response()->json([
            'status' => 'Success',
            'message' => 'Employee payroll summary fetched successfully.',
            'data' => $summary,
        ]);
    }

    public function getRegulations($id)
    {
        try {
            $webUser = WebUser::find($id);

            if (!$webUser) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // HR Section
            
            $hrUserIds = WebUser::where('admin_user_id', $webUser->admin_user_id)->pluck('id');

            $attendanceRegulationsHR = Attendance::whereNotNull('regulation_status')
                ->whereIn('web_user_id', $hrUserIds)
                ->where('regulation_status', '!=', 'None')
                ->select('id', 'emp_id', 'emp_name', 'date', 'checkin', 'checkout',
                        'regulation_checkin', 'regulation_checkout', 'reason',
                        'regulation_date', 'regulation_status', 'status')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($item) {
                    $item->type = 'attendance';
                    return $item;
                });

            $leaveRegulationsHR = LeaveRequest::whereNotNull('regulation_status')
                ->whereIn('web_user_id', $hrUserIds)
                ->where('regulation_status', '!=', 'None')
                ->select('id', 'emp_id', 'emp_name', 'type', 'from', 'to', 'days', 'reason',
                        'regulation_date', 'regulation_status', 'status',
                        'regulation_comment', 'comment')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($item) {
                    $item->type = 'leave';
                    return $item;
                });

            $allRegulationsHR = $attendanceRegulationsHR
                ->concat($leaveRegulationsHR)
                ->sortByDesc('regulation_date')
                ->values();

            // Manager Section

            $reportingEmployeeIds = EmployeeDetails::where('reporting_manager_id', $webUser->id)
                ->pluck('web_user_id');

            $managerInfo = $webUser;

            $attendanceRegulationsManager = collect();
            $leaveRegulationsManager = collect();

            if ($reportingEmployeeIds->isNotEmpty()) {
                $attendanceRegulationsManager = Attendance::whereNotNull('regulation_status')
                    ->whereIn('web_user_id', $reportingEmployeeIds)
                    ->where('regulation_status', '!=', 'None')
                    ->select('id', 'emp_id', 'emp_name', 'date', 'checkin', 'checkout',
                            'regulation_checkin', 'regulation_checkout', 'reason',
                            'regulation_date', 'regulation_status', 'status')
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->map(function ($item) use ($managerInfo) {
                        $item->type = 'attendance';
                        $item->reporting_manager_id = $managerInfo->id;
                        $item->reporting_manager_name = $managerInfo->name ?? null;
                        return $item;
                    });

                $leaveRegulationsManager = LeaveRequest::whereNotNull('regulation_status')
                    ->whereIn('web_user_id', $reportingEmployeeIds)
                    ->where('regulation_status', '!=', 'None')
                    ->select('id', 'emp_id', 'emp_name', 'type', 'from', 'to', 'days', 'reason',
                            'regulation_date', 'regulation_status', 'status',
                            'regulation_comment', 'comment')
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->map(function ($item) use ($managerInfo) {
                        $item->type = 'leave';
                        $item->reporting_manager_id = $managerInfo->id;
                        $item->reporting_manager_name = $managerInfo->name ?? null;
                        return $item;
                    });
            }

            $allRegulationsManager = $attendanceRegulationsManager
                ->concat($leaveRegulationsManager)
                ->sortByDesc('regulation_date')
                ->values();

            //Team Section

            $teamEmployeeIds = EmployeeDetails::where('team_id', $webUser->id)
                ->pluck('web_user_id');

            $attendanceRegulationsTeam = collect();
            $leaveRegulationsTeam = collect();

            if ($teamEmployeeIds->isNotEmpty()) {
                $attendanceRegulationsTeam = Attendance::whereNotNull('regulation_status')
                    ->whereIn('web_user_id', $teamEmployeeIds)
                    ->where('regulation_status', '!=', 'None')
                    ->select('id', 'emp_id', 'emp_name', 'date', 'checkin', 'checkout',
                            'regulation_checkin', 'regulation_checkout', 'reason',
                            'regulation_date', 'regulation_status', 'status')
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->map(function ($item) use ($webUser) {
                        $item->type = 'attendance';
                        $item->team_leader_id = $webUser->id;
                        $item->team_leader_name = $webUser->name ?? null;
                        return $item;
                    });

                $leaveRegulationsTeam = LeaveRequest::whereNotNull('regulation_status')
                    ->whereIn('web_user_id', $teamEmployeeIds)
                    ->where('regulation_status', '!=', 'None')
                    ->select('id', 'emp_id', 'emp_name', 'type', 'from', 'to', 'days', 'reason',
                            'regulation_date', 'regulation_status', 'status',
                            'regulation_comment', 'comment')
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->map(function ($item) use ($webUser) {
                        $item->type = 'leave';
                        $item->team_leader_id = $webUser->id;
                        $item->team_leader_name = $webUser->name ?? null;
                        return $item;
                    });
            }

            $allRegulationsTeam = $attendanceRegulationsTeam
                ->concat($leaveRegulationsTeam)
                ->sortByDesc('regulation_date')
                ->values();

            return response()->json([
                'status' => 'Success',
                'hr_section' => [
                    'total_count' => $allRegulationsHR->count(),
                    'data' => $allRegulationsHR,
                ],
                'manager_section' => [
                    'total_count' => $allRegulationsManager->count(),
                    'data' => $allRegulationsManager,
                ],
                'team_section' => [
                    'total_count' => $allRegulationsTeam->count(),
                    'data' => $allRegulationsTeam,
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'Error',
                'message' => 'Failed to fetch regulations: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateRegulationStatus(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|integer',
            'access' => 'in:HR,Manager',
            'status' => 'required|in:Approved,Rejected',
            'comment' => 'nullable|string',
            'module' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $module = $request->module ?? 'leave';

            if ($module === 'attendance') {
                $regulation = Attendance::findOrFail($request->id);

                if ($request->access === 'Manager') {
                    $regulation->manager_regulation_status = $request->status;
                } elseif ($request->access === 'HR') {
                    if ($request->status === 'Approved' && $regulation->manager_regulation_status !== 'Approved') {
                        return response()->json([
                            'message' => 'HR cannot approve before Manager approves.',
                            'status' => 'error',
                        ], 403);
                    }
                    $regulation->hr_regulation_status = $request->status;
                }

                if ($regulation->hr_regulation_status === 'Approved' && $regulation->manager_regulation_status === 'Approved') {
                    $regulation->regulation_status = 'Approved';
                    $checkin = $regulation->regulation_checkin;
                    if ($checkin) {
                        $standardTime = Carbon::createFromTimeString('09:00:00');
                        $actualCheckin = Carbon::createFromTimeString($checkin);
                        $regulation->status = $actualCheckin->lessThanOrEqualTo($standardTime) ? 'Present' : 'Late';
                    } else {
                        $regulation->status = 'Present';
                    }
                } elseif ($regulation->hr_regulation_status === 'Rejected' || $regulation->manager_regulation_status === 'Rejected') {
                    $regulation->regulation_status = 'Rejected';
                } else {
                    $regulation->regulation_status = 'Pending';
                }

                $regulation->reason = $request->comment ?? $regulation->reason;
                $regulation->save();

            } elseif ($module === 'leave') {
                $regulation = LeaveRequest::findOrFail($request->id);

                if ($request->access === 'Manager') {
                    $regulation->manager_regulation_status = $request->status;
                } elseif ($request->access === 'HR') {
                    if (
                        $request->status === 'Approved' &&
                        $regulation->manager_regulation_status !== 'Approved' &&
                        $regulation->type !== 'Permission'
                    ) {
                        return response()->json([
                            'message' => 'HR cannot approve before Manager approves.',
                            'status' => 'error',
                        ], 403);
                    }
                    $regulation->hr_regulation_status = $request->status;
                }

                if ($regulation->type === 'Permission') {
                    $regulation->regulation_status = $regulation->hr_regulation_status;
                } else {
                    if ($regulation->hr_regulation_status === 'Approved' && $regulation->manager_regulation_status === 'Approved') {
                        $regulation->regulation_status = 'Approved';
                    } elseif ($regulation->hr_regulation_status === 'Rejected' || $regulation->manager_regulation_status === 'Rejected') {
                        $regulation->regulation_status = 'Rejected';
                    } else {
                        $regulation->regulation_status = 'Pending';
                    }
                }

                $regulation->reason = $request->comment ?? $regulation->reason;
                $regulation->save();
            }

            DB::commit();

            return response()->json([
                'message' => ucfirst($module) . ' regulation updated successfully.',
                'status' => 'Success',
                'data' => $regulation
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'Error',
                'message' => 'Failed to update regulation: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getAllEmployeesWithUser()
    {
        $user = Auth::user();
        $webUserIds = WebUser::where('admin_user_id', $user->admin_user_id)->pluck('id');
        $employees = EmployeeDetails::whereIn('web_user_id', $webUserIds)
            ->select(
                'id',
                'web_user_id',
                'emp_name',
                'emp_id',
                'dob',
                'official_contact_no',
                'official_email',
                'designation',
                'department',
                'personal_contact_no',
                'profile_photo'
            )
            ->with(['webUser:id,name,email,role'])
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $employees
        ], 200);
    }
}
