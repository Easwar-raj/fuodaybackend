<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Dependants extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'father_name',
        'father_dob',
        'mother_name',
        'mother_dob',
        'spouce_name',
        'spouce_dob',
        'child_1_name',
        'child_1_dob',
        'child_2_name',
        'child_2_dob',
        'child_3_name',
        'child_3_dob',
        'emergency_contact_1_name',
        'emergency_contact_1_no',
        'emergency_contact_1_relationship',
        'emergency_contact_2_name',
        'emergency_contact_2_no',
        'emergency_contact_2_relationship',
    ];

    protected $casts = [
        'father_dob' => 'date',
        'mother_dob' => 'date',
        'spouce_dob' => 'date',
        'child_1_dob' => 'date',
        'child_2_dob' => 'date',
        'child_3_dob' => 'date',
    ];
}
