<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Incentives;
use App\Models\Onboarding;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\WebUser;
use App\Services\AttendanceService;
use NumberToWords\NumberToWords;
use Barryvdh\DomPDF\Facade\Pdf;

class PayrollPageController extends Controller
{
    public function getPayrollDetails($id)
    {
        // Fetch all payroll components for the given user
        $payrollComponents = Payroll::where('web_user_id', $id)->get();
    
        if ($payrollComponents->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No payroll data found.',
            ], 404);
        }
    
        $user = WebUser::find($id);
        $empName = $user ? $user->name : 'Unknown';
    
        $payrollIds = $payrollComponents->pluck('id');
        $payslips = Payslip::whereIn('payroll_id', $payrollIds)->orderBy('date', 'asc')->get();
        
        $payrollSummary = $payslips->map(function ($payslip) {
            return [
                'payroll_id'       => $payslip->payroll_id,
                'designation'      => optional($payslip->payroll)->designation ?? 'N/A',
                'date'             => optional($payslip->date)->format('Y-m-d'),
                'time'             => $payslip->time ?? null,
                'total_salary'     => (string) $payslip->total_salary,
                'gross'            => (string) $payslip->gross,
                'total_deductions' => (string) $payslip->total_deductions,
                'basic'            => (string) $payslip->basic,
                'lop'              => (string) $payslip->lop,
                'total_paid_days'  => (string) $payslip->total_paid_days,
                'status'           => $payslip->status,
            ];
        });

        $grouped = $payrollComponents->groupBy('type');
        $earnings = $grouped->get('Earnings', collect());
        $deductions = $grouped->get('Deductions', collect());
        $totalEarnings = $earnings->sum(fn($item) => (float) $item->amount);
        $latestPayroll = $payrollComponents->sortByDesc('created_at')->first();
        $incentives = Incentives::where('web_user_id', $id)->sum('amount');
    
        return response()->json([
            'status' => 'Success',
            'message' => 'All payroll details fetched successfully.',
            'data' => [
                'emp_name'             => $empName,
                'total_ctc'            => (string) ($latestPayroll->ctc ?? 0),
                'total_salary'         => (string) ($latestPayroll->monthly_salary ?? 0),
                'current_month_salary' => (string) ($latestPayroll->monthly_salary ?? 0),
                'total_gross'          => (string) $totalEarnings,
                'payrolls'             => $payrollSummary,
                'incentives'           => (float) $incentives,
                'salary_components' => [
                    'earnings' => $earnings->map(fn($item) => [
                        'component' => $item->salary_component,
                        'amount'    => (float) $item->amount,
                    ])->values(),
                    'deductions' => $deductions->map(fn($item) => [
                        'component' => $item->salary_component,
                        'amount'    => (float) $item->amount,
                    ])->values(),
                ]
            ]
        ], 200);
    }

    public function getCurrentPayrollDetails($id)
    {
        $now = now();

        // Step 1: Try to fetch current month payslip with payroll
        $payslip = Payslip::whereHas('payroll', function ($query) use ($id) {
                $query->where('web_user_id', $id);
            })
            ->whereMonth('date', $now->month)
            ->whereYear('date', $now->year)
            ->with('payroll')
            ->latest('date')
            ->first();

        // Fallback to latest payslip if current month is not found
        if (!$payslip) {
            $payslip = Payslip::whereHas('payroll', function ($query) use ($id) {
                    $query->where('web_user_id', $id);
                })
                ->with('payroll')
                ->orderByDesc('date')
                ->first();
        }

        // Get WebUser, EmployeeDetails, AdminUser
        $webUser = WebUser::with('employeeDetails')->find($id);
        $employeeDetail = $webUser->employeeDetails;
        $adminUser = AdminUser::find($webUser->admin_user_id);

        // Get Onboarding Details
        $onboarding = Onboarding::where('web_user_id', $id)->first();

        // Get latest payroll components
        $latestPayrollDate = Payroll::where('web_user_id', $id)->orderByDesc('updated_at')->value('updated_at');
        $payrollComponents = Payroll::where('web_user_id', $id)
            ->whereDate('updated_at', $latestPayrollDate)
            ->get()
            ->groupBy('type');

        $earnings = collect($payrollComponents['Earnings'] ?? [])->groupBy('salary_component')->map(fn($items) => $items->sum('amount'));
        $deductions = collect($payrollComponents['Deductions'] ?? [])->groupBy('salary_component')->map(fn($items) => $items->sum('amount'));

        $salaryComponents = [
            'Earnings'   => $earnings->toArray(),
            'Deductions' => $deductions->toArray(),
        ];

        // Default values if payslip is not found
        $basic = $earnings['Basic'] ?? 0;
        $gross = $basic + $earnings->sum();
        $totalDeductions = $deductions->sum();
        $totalSalary = $gross - $totalDeductions;

        $numberToWords = new NumberToWords();
        $numberTransformer = $numberToWords->getNumberTransformer('en');
        $salaryInWords = ucfirst($numberTransformer->toWords((int)$totalSalary)) . ' only';

        return response()->json([
            'status' => 'Success',
            'message' => 'Current payroll details fetched successfully.',
            'data' => [
                'payslip' => [
                    'month'             => optional($payslip?->date)->format('F Y') ?? $latestPayrollDate?->format('F Y'),
                    'basic'             => $payslip?->basic ?? $basic,
                    'overtime'          => $payslip?->overtime ?? 0,
                    'total_paid_days'   => $payslip?->total_paid_days ?? null,
                    'lop'               => $payslip?->lop ?? 0,
                    'gross'             => $payslip?->gross ?? $gross,
                    'total_deductions'  => $payslip?->total_deductions ?? $totalDeductions,
                    'total_salary'      => $payslip?->total_salary ?? $totalSalary,
                    'total_salary_word' => $payslip ? ucfirst($numberTransformer->toWords((int)$payslip->total_salary)) . ' only' : $salaryInWords,
                    'status'            => $payslip?->status ?? 'unpaid',
                    'date'              => optional($payslip?->date)->format('Y-m-d') ?? optional($latestPayrollDate)->format('Y-m-d'),
                    'company_name'      => $adminUser->company_name,
                    'logo'              => $adminUser->logo,
                ],

                'salary_components' => $salaryComponents,

                'employee_details' => [
                    'name'            => $webUser->name,
                    'designation'     => $employeeDetail->designation ?? null,
                    'emp_id'          => $webUser->emp_id,
                    'date_of_joining' => optional($employeeDetail?->date_of_joining)->format('Y-m-d'),
                    'year'            => optional($employeeDetail?->date_of_joining)->format('Y'),
                ],

                'onboarding_details' => [
                    'pf_account_no'    => $employeeDetail->pf_account_no ?? null,
                    'uan'              => $employeeDetail->uan ?? null,
                    'esi_no'           => $employeeDetail->esi_no ?? null,
                    'bank_account_no'  => $employeeDetail->account_no ?? null,
                ],
            ]
        ], 200);
    }

    public function getPayroll($id)
    {
        $now = now();
        // Step 1: Get the current month's payslip (or latest)
        $payslip = Payslip::whereHas('payroll', function($query) use ($id) {
                $query->where('web_user_id', $id);
            })
            ->whereMonth('date', $now->month)
            ->whereYear('date', $now->year)
            ->with('payroll')
            ->latest('date')
            ->first();
        if (!$payslip) {
            $payslip = Payslip::whereHas('payroll', function($query) use ($id) {
                    $query->where('web_user_id', $id);
                })
                ->orderByDesc('date')
                ->first();
        }
        if (!$payslip) {
            return response()->json([
                'message' => 'No payslip found.'
            ], 404);
        }

        // Get latest updated salary components
        $latestPayrollDate = Payroll::where('web_user_id', $id)->orderByDesc('updated_at')->value('updated_at'); // get latest update timestamp

        $payrollComponents = Payroll::where('web_user_id', $id)->whereDate('updated_at', $latestPayrollDate)->get()->groupBy('type'); // Group by type

        $earnings = collect($payrollComponents['Earnings'] ?? [])
            ->groupBy('salary_component')
            ->map(fn($items) => $items->sum('amount'));

        $deductions = collect($payrollComponents['Deductions'] ?? [])
            ->groupBy('salary_component')
            ->map(fn($items) => $items->sum('amount'));

        $salaryComponents = [
            'Earnings' => $earnings->toArray(),
            'Deductions' => $deductions->toArray(),
        ];
        // Step 5: Format Response
        return response()->json([
            'status' => 'Success',
            'message' => 'Current payroll details fetched successfully.',
            'data' => [
                'payslip' => [
                    'month'            => optional($payslip->date)->format('F Y'),
                    'basic'            => $payslip->basic,
                    'overtime'         => $payslip->overtime,
                    'total_paid_days'  => $payslip->total_paid_days,
                    'lop'              => $payslip->lop,
                    'gross'            => $payslip->gross,
                    'total_deductions' => $payslip->total_deductions,
                    'total_salary'     => $payslip->total_salary,
                    'status'           => $payslip->status,
                    'date'             => optional($payslip->date)->format('Y-m-d'),
                ],

                // Grouped salary components by type
                'salary_components' => $salaryComponents,
            ]
        ], 200);
    }

    public function downloadPayslip($id)
    {
        $now = now();
        // Step 1: Get the current month's payslip (or latest)
        $payslip = Payslip::whereHas('payroll', function($query) use ($id) {
                $query->where('web_user_id', $id);
            })
            ->whereMonth('date', $now->month)
            ->whereYear('date', $now->year)
            ->with('payroll')
            ->latest('date')
            ->first();
        if (!$payslip) {
            $payslip = Payslip::whereHas('payroll', function($query) use ($id) {
                    $query->where('web_user_id', $id);
                })
                ->orderByDesc('date')
                ->first();
        }
        if (!$payslip) {
            return response()->json([
                'message' => 'No payslip found.'
            ], 404);
        }

        $latestPayrollDate = Payroll::where('web_user_id', $id)->orderByDesc('updated_at')->value('updated_at');
        $payrollComponents = Payroll::where('web_user_id', $id)
            ->whereDate('updated_at', $latestPayrollDate)
            ->get()->groupBy('type');

        $webUser = WebUser::with('employeeDetails')->findOrFail($id);
        $employeeDetail = $webUser->employeeDetails;

        $numberToWords = new NumberToWords();
        $numberTransformer = $numberToWords->getNumberTransformer('en');
        $salaryInWords = $payslip->total_salary !== null
            ? ucfirst($numberTransformer->toWords((int) $payslip->total_salary)) . ' only'
            : null;

        $earnings = collect($payrollComponents['Earnings'] ?? [])
            ->groupBy('salary_component')
            ->map(fn($items) => $items->sum('amount'));

        $deductions = collect($payrollComponents['Deductions'] ?? [])
            ->groupBy('salary_component')
            ->map(fn($items) => $items->sum('amount'));

        $pdf = Pdf::loadView('payslips.payslip', [
            'payslip' => [
                'month' => optional($payslip->date)->format('F Y'),
                'gross' => $payslip->gross,
                'total_deductions' => $payslip->total_deductions,
                'total_salary' => $payslip->total_salary,
                'total_salary_word' => $salaryInWords,
            ],
            'salary_components' => [
                'Earnings' => $earnings->toArray(),
                'Deductions' => $deductions->toArray(),
            ],
            'employee' => [
                'name' => $webUser->name,
                'emp_id' => $webUser->emp_id,
                'designation' => $employeeDetail->designation ?? '',
                'date_of_joining' => optional($employeeDetail->date_of_joining)->format('Y-m-d'),
            ],
        ]);

        $fileName = 'Payslip_' . $webUser->emp_id . '_' . optional($payslip->date)->format('F_Y') . '.pdf';
        return $pdf->download($fileName);
    }

    public function runAttendanceVerification()
    {
        AttendanceService::verifyAttendanceStatuses();

        return response()->json(['message' => 'Attendance verification completed successfully.']);
    }
}
