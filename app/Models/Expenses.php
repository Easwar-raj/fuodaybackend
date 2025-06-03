<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Expenses extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'date',
        'type',
        'reason',
        'amount',
        'status',
        'comment'
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];
}
