<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Schedule extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'department',
        'team_name',
        'date',
        'shift_start',
        'shift_end',
        'event',
        'shift_status',
        'start_date',
        'end_date',
        'saturday_type',
        'saturday_dates'
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d'
    ];
}
