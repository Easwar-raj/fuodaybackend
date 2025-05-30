<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class TotalLeaves extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'admin_user_id',
        'company_name',
        'type',
        'total',
        'period'
    ];

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class, 'type', 'type');
    }
}
