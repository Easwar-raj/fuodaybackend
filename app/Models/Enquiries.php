<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Enquiries extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'full_name',
        'email',
        'contact_number',
        'message',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
    ];
}
