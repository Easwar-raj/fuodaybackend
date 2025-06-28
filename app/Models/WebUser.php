<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class WebUser extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'admin_user_id', 'name', 'email', 'role', 'emp_id', 'group', 'password'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function employeeDetails()
    {
        return $this->hasOne(EmployeeDetails::class, 'web_user_id');
    }

    public function payroll()
    {
        return $this->hasMany(Payroll::class, 'web_user_id');
    }

    public function adminUser()
    {
        return $this->belongsTo(AdminUser::class);
    }
}
