<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Attendance extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'date',
        'checkin',
        'checkout',
        'worked_hours',
        'location',
        'status',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];
}
