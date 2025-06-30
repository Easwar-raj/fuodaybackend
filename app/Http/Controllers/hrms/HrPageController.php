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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;

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
        $totalPermissions = Attendance::whereIn('web_user_id', $webUserIds)->count();
        $lastWeekPermissions = Attendance::whereIn('web_user_id', $webUserIds)
            ->whereBetween('date', [now()->startOfWeek()->subWeek(), now()->endOfWeek()->subWeek()])
            ->count();
        $thisWeekPermissions = Attendance::whereIn('web_user_id', $webUserIds)
            ->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();
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

        $events = Event::where('web_user_id', $id)->get();

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
                'audits' => $audits
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
        // Step 1: Find the admin_user_id for the given web_user_id
        $webUser = WebUser::findOrFail($id);

        if (!$webUser->admin_user_id || $webUser->role !== 'hr') {
            return response()->json(['message' => 'Invalid details'], 403);
        }
        // Step 2: Fetch all web_users having the same admin_user_id
        $webUsers = WebUser::where('admin_user_id', $webUser->admin_user_id)->get();

        return response()->json([
            'status' => 'Success',
            'message' => 'Web users retrieved successfully',
            'data' => $webUsers
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

            // PDF Format
            if ($format === 'pdf') {
                $pdf = Pdf::loadView('pdf.employee_list', ['employees' => $employees]);
                return $pdf->download('employee_details.pdf');
            }

            // Invalid format
            return response()->json(['error' => 'Invalid format. Use ?format=pdf or ?format=xlsx'], 400);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    public function getAllLeaveRequestsByStatus($status)
    {

        $user = Auth::user();
        $webUser = WebUser::find($user->id);
        $employeeIds = WebUser::where('admin_user_id', $webUser->admin_user_id)
            ->where('role', 'employee')
            ->pluck('id');
        $validStatuses = ['pending', 'approved', 'rejected'];
        $status = strtolower($status);

        if (!in_array($status, $validStatuses)) {
            return response()->json(['message' => 'Invalid status value'], 400);
        }

        $leaveRequests = LeaveRequest::with(['webUser:id,id,name,emp_id'])
            ->whereIn('web_user_id', $employeeIds)
            ->where('status', $status)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($leave) {
                return [
                    'id' => $leave->id,
                    'web_user_id' => $leave->web_user_id,
                    'date' => $leave->date,
                    'type' => $leave->type,
                    'from' => $leave->from,
                    'reason' => $leave->reason,
                    'to' => $leave->to,
                    'name' => $leave->webUser->name ?? null,
                    'status' => $leave->status,
                ];
            });
        return response()->json([
            'status' => 'Success',
            'message' => ucfirst($status) . ' leave requests retrieved successfully',
            'data' => $leaveRequests
        ]);
    }

    public function getAllEmployeeAttendance(Request $request)
    {

        $user = Auth::user();
        $webUser = WebUser::find($user->id);
        $employeeIds = WebUser::where('admin_user_id', $webUser->admin_user_id)
            ->where('role', 'employee')
            ->pluck('id');
        // Step 1: Get all attendance records, joining with web_users
        $query = Attendance::with(['employee' => function ($q) {
            $q->select('id', 'name', 'emp_id'); // Keep only needed fields
        }])->whereIn('web_user_id', $employeeIds);

        // Step 2: Apply optional filters
        if ($request->has('name')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->name . '%');
            });
        }

        if ($request->has('month')) {
            $query->whereMonth('date', $request->month);
        }

        if ($request->has('year')) {
            $query->whereYear('date', $request->year);
        }

        // Step 3: Get results
        $attendances = $query->orderBy('date', 'desc')->get();

        // Step 4: Format
        $data = $attendances->map(function ($att) {
            return [
                'name' => $att->employee->name ?? 'N/A',
                'emp_id' => $att->emp_id ?? 'N/A',
                'date' => $att->date->format('Y-m-d'),
                'checkin' => $att->checkin,
                'checkout' => $att->checkout,
                'worked_hours' => $att->worked_hours,
                'status' => $att->status,
            ];
        });

        return response()->json([
            'status' => 'Success',
            'message' => 'All employee attendance data retrieved successfully.',
            'data' => $data,
        ]);
    }

    public function getAllEmployeePayrollSummaries()
    {
        $user = Auth::user();
        $webUser = WebUser::find($user->id);
        $employees = WebUser::with('employeeDetails')->where('admin_user_id', $webUser->admin_user_id)->get();

        $summaries = $employees->map(function ($user) {
            $userId = $user->id;

            // Total incentives
            $incentives = Incentives::where('web_user_id', $userId)->sum('amount');

            // Latest payroll with payslip
            $latestPayroll = Payroll::where('web_user_id', $userId)
                ->whereHas('payslip')
                ->with('payslip')
                ->get()
                ->sortByDesc(function ($payroll) {
                    return $payroll->payslip->date ?? now()->subYears(10);
                })
                ->first();

            $payslip = $latestPayroll?->payslip;

            $totalSalary = $payslip->total_salary ?? 0;
            $salaryInWords = $this->convertNumberToWords($totalSalary);

            // Get salary components grouped by type
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

            return [
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
        });

        return response()->json([
            'status' => 'Success',
            'message' => 'All employee payroll summaries fetched successfully.',
            'data' => $summaries,
        ]);
    }

    //
    private function convertNumberToWords($number)
    {
        $f = new \NumberFormatter("en", \NumberFormatter::SPELLOUT);
        return ucfirst($f->format($number)) . ' only';
    }

    //

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
        $salaryInWords = $this->convertNumberToWords($totalSalary);

        // Get salary components grouped by type
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
}