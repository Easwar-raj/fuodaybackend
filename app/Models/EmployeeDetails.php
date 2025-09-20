<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class EmployeeDetails extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'gender',
        'role_location',
        'designation',
        'department',
        'employment_type',
        'profile_photo',
        'about',
        'role_location',
        'work_module',
        'dob',
        'address',
        'date_of_joining',
        'reporting_manager_id',
        'reporting_manager_name',
        'team_id',
        'aadhaar_no',
        'pan_no',
        'blood_group',
        'emergency_contact_no',
        'personal_contact_no',
        'official_contact_no',
        'official_email',
        'permanent_address',
        'bank_name',
        'account_no',
        'ifsc',
        'pf_account_no',
        'uan',
        'esi_no',
        'place',
        'quote',
        'author',
        'welcome_image',
        'access'
    ];

    protected $casts = [
        'dob' => 'date:Y-m-d',
        'date_of_joining' => 'date:Y-m-d',
    ];

    public function webUser()
    {
        return $this->belongsTo(WebUser::class, 'web_user_id');
    }

    public function employeeDetails()
    {
        return $this->hasOne(EmployeeDetails::class, 'web_user_id', 'id');
    }

    public function reportingManager()
    {
        return $this->belongsTo(WebUser::class, 'reporting_manager_id');
    }

    public function teamLead()
    {
        return $this->belongsTo(WebUser::class, 'team_id', 'id');
    }

}
