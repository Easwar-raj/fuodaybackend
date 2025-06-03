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
        'status',
        'comment'
    ];

    protected $casts = [
        'from' => 'date:Y-m-d',
        'to' => 'date:Y-m-d',
        'date' => 'date:Y-m-d',
    ];


    public function webUser()
    {
        return $this->belongsTo(WebUser::class, 'web_user_id');
    }
}
