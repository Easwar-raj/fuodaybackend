<?php

namespace App\Http\Controllers\hrms;


use App\Http\Controllers\Controller;
use App\Models\Incentives;
use App\Models\Onboarding;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PayrollPageController extends Controller
{
    public function getPayrollDetails($id)
    {
        $incentives = Incentives::where('web_user_id', $id)->sum('amount');
        // Fetch payrolls for the admin_user_id
        $payrolls = Payroll::where('web_user_id', $id)->with('payslip')
            ->get()
            ->map(function ($payroll) {
                return [
                    'payroll_id'       => $payroll->id,
                    'designation'      => $payroll->designation,
                    'date'             => optional($payroll->payslip->date)->format('Y-m-d'),
                    'time'             => optional($payroll->payslip)?->time ? Carbon::parse($payroll->payslip->time)->format('h:i A') : null,
                    'total_salary'     => $payroll->monthly_salary,
                    'total_ctc'        => $payroll->ctc,
                    'total_gross'      => $payroll->payslip->gross,
                    'total_deductions' => $payroll->payslip->total_deductions,
                    'status'           => $payroll->payslip->status,
                ];
            });

        return response()->json([
            'payrolls' => $payrolls,
            'incentives' => $incentives
        ]);
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

        // Step 2: Get Payroll components for this user and month
        $payrollComponents = Payroll::where('web_user_id', $id)
            ->whereMonth('created_at', optional($payslip->date)->month)
            ->whereYear('created_at', optional($payslip->date)->year)
            ->get();

        // Step 3: Get WebUser + EmployeeDetails
        $webUser = WebUser::with('employeeDetails')->find($id);
        $employeeDetail = $webUser->employeeDetails;

        // Step 4: Get Onboarding Details
        $onboarding = Onboarding::where('web_user_id', $id)->first();

        // Step 5: Format Response
        return response()->json([
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

            'salary_components' => $payrollComponents->map(function($component) {
                return [
                    'emp_name'         => $component->emp_name,
                    'emp_id'           => $component->emp_id,
                    'designation'      => $component->designation,
                    'ctc'              => $component->ctc,
                    'monthly_salary'   => $component->monthy_salary,
                    'salary_component' => $component->salary_component,
                    'type'             => $component->type,
                    'amount'           => $component->amount,
                ];
            }),

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
        ]);
    }
}
