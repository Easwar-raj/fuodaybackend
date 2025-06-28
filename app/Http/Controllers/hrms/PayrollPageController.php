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
use Carbon\Carbon;
use Illuminate\Http\Request;
use NumberToWords\NumberToWords;
use Barryvdh\DomPDF\Facade\Pdf;

class PayrollPageController extends Controller
{
    public function getPayrollDetails($id)
    {
        $incentives = Incentives::where('web_user_id', $id)->sum('amount');

        $latestPayroll = Payroll::where('web_user_id', $id)
            ->whereHas('payslip') // make sure it has a payslip
            ->with('payslip')
            ->get()
            ->sortByDesc(function ($payroll) {
                return $payroll->payslip->date ?? now()->subYears(10); // fallback to a very old date if null
            })
            ->first();

        // Fetch payrolls for the admin_user_id
        $payslips = Payslip::whereHas('payroll', function ($q) use ($id) {
                $q->where('web_user_id', $id);
            })
            ->with('payroll')
            ->get()
            ->groupBy('month')
            ->map(function ($groupedPayslips) {
                // Take the first payslip in each month group
                $payslip = $groupedPayslips->first();
                $payroll = $payslip->payroll;

                return [
                    'payroll_id'       => $payroll?->id,
                    'designation'      => $payroll?->designation,
                    'date'             => $payslip->date?->format('Y-m-d'),
                    'time'             => $payslip->time ? \Carbon\Carbon::parse($payslip->time)->format('h:i A') : null,
                    'total_salary'     => $payroll?->monthly_salary,
                    'total_gross'      => $payslip->gross,
                    'total_deductions' => $payslip->total_deductions,
                    'status'           => $payslip->status,
                ];
            })
            ->values(); // Reset keys


        return response()->json([
            'status' => 'Success',
            'message' => 'Payroll details fetched successfully.',
            'data' => [
                'total_ctc'         => $latestPayroll->ctc ?? 0,
                'total_salary'      => $latestPayroll->monthly_salary ?? 0,
                'current_month_salary' => $latestPayroll->payslip->total_salary ?? 0,
                'total_gross'       => $latestPayroll->payslip->gross ?? 0,
                'payrolls' => $payslips,
                'incentives' => $incentives
            ],
        ], 200);
    }

    public function getCurrentPayrollDetails($id)
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

        // Step 3: Get WebUser + EmployeeDetails
        $webUser = WebUser::with('employeeDetails')->find($id);
        $employeeDetail = $webUser->employeeDetails;
        $adminUser = AdminUser::find($webUser->admin_user_id);

        // Step 4: Get Onboarding Details
        $onboarding = Onboarding::where('web_user_id', $id)->first();
        $numberToWords = new NumberToWords();
        $numberTransformer = $numberToWords->getNumberTransformer('en');

        $totalSalary = $payslip->total_salary;
        $salaryInWords = $totalSalary !== null
            ? ucfirst($numberTransformer->toWords((int) $totalSalary)) . ' only'
            : null;

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
                    'total_salary_word' => $salaryInWords,
                    'status'           => $payslip->status,
                    'date'             => optional($payslip->date)->format('Y-m-d'),
                    'company_name'     => $adminUser->company_name,
                    'logo'             => $adminUser->logo
                ],

                // Grouped salary components by type
                'salary_components' => $salaryComponents,

                'employee_details' => [
                    'name'             => $webUser->name,
                    'designation'      => $employeeDetail->designation ?? null,
                    'emp_id'           => $webUser->emp_id,
                    'date_of_joining'  => $employeeDetail?->date_of_joining ? $employeeDetail->date_of_joining->format('Y-m-d') : null,
                    'year'             => $employeeDetail?->date_of_joining ? $employeeDetail->date_of_joining->format('Y') : null,
                ],

                'onboarding_details' => [
                    'pf_account_no'     => $onboarding->pf_account_no ?? null,
                    'uan'               => $onboarding->uan ?? null,
                    'esi_no'            => $onboarding->esi_no ?? null,
                    'bank_account_no'   => $onboarding->account_no ?? null,
                ],
            ],
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
