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
        'job_location',
        'nationality',
        'expected_ctc',
        'address',
        'education',
        'certifications',
        'skillset',
        'experience',
        'current_job_title',
        'current_employer',
        'linkedin',
        'cv'
    ];

    protected $casts = [
        'dob' => 'date:Y-m-d',
        'interview_date' => 'date:Y-m-d'
    ];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }
}
