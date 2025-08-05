<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Task extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'date',
        'description',
        'assigned_by',
        'assigned_by_id',
        'assigned_to',
        'assigned_to_id',
        'project_id',
        'project',
        'priority',
        'status',
        'progress_note',
        'deadline',
        'comment'
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'deadline' => 'date:Y-m-d',
    ];

    public function project()
    {
        return $this->belongsTo(Projects::class, 'project_id');
    }

    public function projectTeamTo()
    {
        return $this->belongsTo(ProjectTeam::class, 'assigned_to_id', 'web_user_id');
    }
    public function projectTeamBy()
    {
        return $this->belongsTo(ProjectTeam::class, 'assigned_by_id', 'web_user_id');
    }
}
