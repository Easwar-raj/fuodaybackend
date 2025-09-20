<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class LeaveRequest extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'date',
        'department',
        'type',
        'from',
        'to',
        'days',
        'reason',
        'permission_timing',
        'status',
        'comment',
        'regulation_date',
        'regulation_reason',
        'regulation_status',
        'regulation_comment',
        'hr_status',
        'manager_status',
        'hr_regulation_status',
        'manager_regulation_status'
    ];

    protected $casts = [
        'from' => 'date:Y-m-d',
        'to' => 'date:Y-m-d',
        'date' => 'date:Y-m-d',
        'regulation_date' => 'date:Y-m-d'
    ];


    public function webUser()
    {
        return $this->belongsTo(WebUser::class, 'web_user_id');
    }
}
