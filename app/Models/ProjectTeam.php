<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class ProjectTeam extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'project_id',
        'project_name',
        'web_user_id',
        'emp_name',
        'emp_id',
        'member',
        'role',
        'progress',
        'status',
        'comment'
    ];

    public function webUser() {
        return $this->belongsTo(WebUser::class);
    }

    public function project()
    {
        return $this->belongsTo(Projects::class);
    }
}
