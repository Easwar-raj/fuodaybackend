<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Projects extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'admin_user_id',
        'company_name',
        'name',
        'domain',
        'project_manager_id',
        'project_manager_name',
        'progress',
        'client',
        'comment',
        'deadline'
    ];

    protected $casts = [
        'deadline' => 'date:Y-m-d',
    ];

    public function projectTeam()
    {
        return $this->hasMany(ProjectTeam::class, 'project_id');
    }
}
