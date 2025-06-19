<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Audits extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'audit_month',
        'task_highlight',
        'challenges',
        'support',
        'self_rating',
        'comment',
        'work_justification',
        'manager_review',
        'final_remarks',
        'management_review',
        'auditor_review'
    ];
}
