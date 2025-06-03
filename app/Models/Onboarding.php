<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Onboarding extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'welcome_email_sent',
        'scheduled_date',
        'photo',
        'pan',
        'passbook',
        'payslip',
        'offer_letter',
    ];

    protected $casts = [
        'welcome_email_sent' => 'date:Y-m-d',
        'scheduled_date' => 'date:Y-m-d',
    ];
}
