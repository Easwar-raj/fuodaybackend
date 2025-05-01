<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class CandidateDetails extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'candidate_id',
        'place',
        'phone',
        'email',
        'dob',
        'job_id',
        'designation',
        'department',
        'employment_status',
        'job_title',
        'nationality',
        'expected_ctc',
        'address',
        'education',
        'certifications',
        'skillset',
        'experience'
    ];
}
