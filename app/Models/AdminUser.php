<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class AdminUser extends Model
{
    use HasFactory, HasApiTokens;

    // Specify the table name
    protected $table = 'admin_users';

    // Fillable fields to allow mass assignment
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'company_name',
        'logo',
        'brand_logo',
        'allowed_users',
        'address'
    ];

    // Hidden attributes for serialization
    protected $hidden = [
        'password',
        'remember_token',
    ];
}
