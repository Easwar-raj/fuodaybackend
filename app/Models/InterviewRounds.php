<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class InterviewRounds extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'admin_user_id',
        'company_name',
        'level',
        'round_name',
        'no_of_qns'
    ];
}
