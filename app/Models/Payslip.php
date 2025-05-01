<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Payslip extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'payroll_id',
        'date',
        'time',
        'basic',
        'overtime',
        'total_paid_days',
        'lop',
        'gross',
        'total_deductions',
        'total_salary',
        'status',
    ];

    protected $casts = [
        'date' => 'date',
    ];
}
