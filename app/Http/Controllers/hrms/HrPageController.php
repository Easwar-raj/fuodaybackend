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

            if ($format === 'pdf') {
                $pdf = Pdf::loadView('pdf.employee_list', ['employees' => $employees]);
                return $pdf->download('employee_details.pdf');
            }

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

        $leaveRequests = LeaveRequest::with(['webUser:id,name,emp_id'])
            ->whereIn('web_user_id', $employeeIds)
            ->where('status', $status)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($leave) {
                return [
                    'id' => $leave->id,
                    'web_user_id' => $leave->web_user_id,
                    'name' => $leave->webUser->name ?? null,
                    'date' => $leave->date,
                    'type' => $leave->type,
                    'from' => $leave->from,
                    'to' => $leave->to,
                    'reason' => $leave->reason,
                    'permission_timing'=> $leave->permission_timing,
                    'status' => $leave->status,
                    'regulation_date' => $leave->regulation_date,
                    'regulation_reason' => $leave->regulation_reason,
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
        $employeeIds = WebUser::where('admin_user_id', $webUser->admin_user_id)->pluck('id');
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
            $webUserIds = WebUser::where('admin_user_id', $webUser->admin_user_id)->pluck('id');
            if (!$webUser) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }
            $attendanceRegulations = Attendance::whereNotNull('regulation_status')
                ->whereIn('web_user_id', $webUserIds)
                ->where('regulation_status', '!=', 'None')
                ->select('id', 'emp_id', 'emp_name', 'date', 'checkin', 'checkout', 'regulation_checkin', 'regulation_checkout',
                    'reason', 'regulation_date', 'regulation_status', 'status')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($item) {
                    $item->type = 'attendance';
                    return $item;
                });

            $leaveRegulations = LeaveRequest::whereNotNull('regulation_status')
                ->whereIn('web_user_id', $webUserIds)
                ->where('regulation_status', '!=', 'None')
                ->select('id', 'emp_id', 'emp_name', 'type', 'from', 'to', 'days', 'reason', 
                    'regulation_date', 'regulation_status', 'status', 'regulation_comment', 'comment')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($item) {
                    $item->type = 'leave';
                    return $item;
                });

            $allRegulations = $attendanceRegulations->concat($leaveRegulations)->sortByDesc('regulation_date')->values();

            return response()->json([
                'status' => 'Success',
                'data' => $allRegulations
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
        $request->validate([
            'id' => 'required|integer',
            'status' => 'required|in:Approved,Rejected',
            'type' => 'required|in:attendance,leave',
        ]);

        try {
            DB::beginTransaction();

            if ($request->type === 'attendance') {
                $request->validate([
                    'id' => 'exists:attendances,id',
                ]);

                $regulation = Attendance::findOrFail($request->id);

                $updateData = [
                    'regulation_status' => $request->status,
                    'reason' => $regulation->reason,
                    'regulation_date' => $regulation->regulation_date
                ];

                if ($request->status === 'Approved') {
                    $checkin = $regulation->regulation_checkin;

                    if ($checkin) {
                        $standardTime = Carbon::createFromTimeString('09:00:00');
                        $actualCheckin = Carbon::createFromTimeString($checkin);

                        if ($actualCheckin->lessThanOrEqualTo($standardTime)) {
                            $updateData['status'] = 'Present';
                        } else {
                            $updateData['status'] = 'Late';
                        }
                    } else {
                        $updateData['status'] = 'Present';
                    }
                }

                $regulation->update($updateData);

            } else if ($request->type === 'leave') {
                $request->validate([
                    'id' => 'exists:leave_requests,id',
                ]);

                $regulation = DB::table('leave_requests')->where('id', $request->id)->first();
                
                if (!$regulation) {
                    throw new Exception('Leave regulation not found');
                }

                $updateData = [
                    'regulation_status' => $request->status,
                    'regulation_date' => now(),
                ];

                if ($request->status === 'Approved') {
                    $updateData['status'] = 'Approved';
                    
                } else if ($request->status === 'Rejected') {
                    $updateData['status'] = 'Rejected';
                }

                DB::table('leave_requests')
                    ->where('id', $request->id)
                    ->update($updateData);
            }

            DB::commit();

            return response()->json([
                'status' => 'Success',
                'message' => ucfirst($request->type) . ' regulation ' . strtolower($request->status) . ' successfully.',
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'Error',
                'message' => 'Failed to update regulation status: ' . $e->getMessage(),
            ], 500);
        }
    }
}
