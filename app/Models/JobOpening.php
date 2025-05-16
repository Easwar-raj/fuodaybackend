<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class JobOpening extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'admin_user_id',
        'company_name',
        'date',
        'title',
        'position',
        'no_of_openings',
        'posted_at',
        'applied',
        'review',
        'interview',
        'reject',
        'hired',
        'status'
    ];

    protected $casts = [
        'date' => 'date',
    ];
}
