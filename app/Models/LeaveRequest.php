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
        'from' => 'date',
        'to' => 'date',
        'date' => 'date',
    ];


    public function webUser()
    {
        return $this->belongsTo(WebUser::class, 'web_user_id');
    }
}
