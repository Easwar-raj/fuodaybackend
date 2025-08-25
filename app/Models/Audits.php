<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Audits extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        // Employee Fields
        'web_user_id',
        'emp_name',
        'emp_id',
        'department',
        'date_of_joining',
        'key_tasks_completed',
        'challenges_faced',
        'proud_contribution',
        'training_support_needed',
        'rating_technical_knowledge',
        'rating_teamwork',
        'rating_communication',
        'rating_punctuality',
        'training',
        'hike',
        'growth_path',
        // Manager Fields (Sales/Recruitment/Acquisition)
        'daily_call_attendance',
        'leads_generated',
        'targets_assigned',
        'targets_achieved',
        'conversion_ratio',
        'revenue_contribution',
        'deadline_consistency',
        'discipline_accountability',
        'rating_proactiveness_ownership',
        // Manager Fields (Tech)
        'tasks_completed_on_time',
        'code_quality_bugs_fixed',
        'contribution_to_roadmap',
        'innovation_ideas',
        'collaboration_with_teams',
        'rating_technical_competency',
        // Manager's Final Inputs
        'employee_strengths',
        'areas_of_improvement',
        'recommendation_probation',
        'is_eligible_for_hike',
        'hike_percentage_suggestion',
        'manager_approve',
        'manager_review_comments',
        // Management Fields
        'manager_evaluation_validation',
        'cross_team_comparison',
        'pca_audit_score',
        'hike_decision',
        'final_hike_percentage',
        'probation_decision',
        'future_role_growth_plan',
        'management_review',
        'final_remarks',
        'auditor_review',
    ];

    protected $casts = [
        'date_of_joining' => 'date',
        'daily_call_attendance' => 'boolean',
        'deadline_consistency' => 'boolean',
        'tasks_completed_on_time' => 'boolean',
        'collaboration_with_teams' => 'boolean',
        'is_eligible_for_hike' => 'boolean',
        'manager_approve' => 'boolean',
    ];
}