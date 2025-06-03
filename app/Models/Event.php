<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Event extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'admin_user_id',
        'company_name',
        'event',
        'title',
        'date',
        'description'
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];
}
