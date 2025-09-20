<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Ticket extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'ticket',
        'category',
        'assigned_to_id',
        'assigned_to',
        'assigned_by',
        'priority',
        'date',
        'status',
        'system_type',
        'reassigned_id',
        'reason_to_reassign',
        'description_to_reassign',
        'work_status',
        'work_status_reason',
        'surrogate_key',
        'task_status',
        'task_description' ,
        'assignment_type',
        'support_by',
        'assignee_description'
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function webUser()
    {
        return $this->belongsTo(WebUser::class);
    }
}
