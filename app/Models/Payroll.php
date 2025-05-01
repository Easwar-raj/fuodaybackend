<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Payroll extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'designation',
        'ctc',
        'monthly_salary',
        'salary_component',
        'type',
        'amount',
    ];

    public function payslip()
    {
        return $this->belongsTo(Payslip::class, 'payroll_id');
    }

    public function incentives()
    {
        return $this->hasMany(Incentives::class);
    }
}
