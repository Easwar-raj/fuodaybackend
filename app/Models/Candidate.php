<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Candidate extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'name',
        'experience',
        'interview_date',
        'role',
        'L1',
        'L2',
        'L3',
        'ats_score',
        'overall_score',
        'technical_status',
        'technical_feedback',
        'hr_status',
        'hr_feedback',
        'contact',
        'resume',
        'feedback',
        'hiring_status',
        'referred_by'
    ];

    protected $casts = [
        'interview_date' => 'date:Y-m-d',
    ];
}
