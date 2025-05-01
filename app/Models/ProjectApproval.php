<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class ProjectApproval extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'name',
        'domain',
        'requested_to',
        'comment',
        'status',
        'deadline'
    ];

    protected $casts = [
        'deadline' => 'date',
    ];

    public function project()
    {
        return $this->belongsTo(Projects::class, 'project_id'); // assuming foreign key is project_id
    }
}
