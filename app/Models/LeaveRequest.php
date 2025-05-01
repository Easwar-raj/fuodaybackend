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
        'department',
        'type',
        'from',
        'to',
        'reason',
        'status',
        'comment'
    ];

    protected $casts = [
        'from' => 'date',
        'to' => 'date',
    ];

    
    public function webUser()
    {
        return $this->belongsTo(WebUser::class, 'web_user_id');
    }
}
