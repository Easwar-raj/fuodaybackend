<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Audits extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'audit_cycle_type',
        'review_period',
        'audit_month',
        'attendance_percentage',
        'self_rating',
        'technical_skills_used',
        'communication_collaboration',
        'cross_functional_involvement',
        'task_highlight',
        'personal_highlight',
        'areas_to_improve',
        'initiative_taken',
        'learnings_certifications',
        'suggestions_to_company',
        'previous_cycle_goals',
        'goal_achievement',
        'kpi_metrics',
        'projects_worked',
        'tasks_modules_completed',
        'performance_evidences',
        'manager_review_comments',
        'execution_rating',
        'innovation_rating',
        'attendance_discipline_score',
        'delivery_quality',
        'ownership_initiative',
        'team_growth_contribution',
        'promotion_action_suggested',
        'final_attachments',
        'final_remarks',
        'management_review',
        'auditor_review'
    ];
}