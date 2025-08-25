<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_user_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->string('emp_name')->nullable();
            $table->string('emp_id')->nullable();
            $table->string('department')->nullable();
            $table->date('date_of_joining')->nullable();
            $table->text('key_tasks_completed')->nullable();
            $table->text('challenges_faced')->nullable();
            $table->text('proud_contribution')->nullable();
            $table->text('training_support_needed')->nullable();
            $table->tinyInteger('rating_technical_knowledge')->nullable();
            $table->tinyInteger('rating_teamwork')->nullable();
            $table->tinyInteger('rating_communication')->nullable();
            $table->tinyInteger('rating_punctuality')->nullable();
            $table->text('training')->nullable();
            $table->text('hike')->nullable();
            $table->text('growth_path')->nullable();
            // Reporting Manager (Evaluation Fields)
            $table->boolean('daily_call_attendance')->nullable();
            $table->string('leads_generated')->nullable();
            $table->string('targets_assigned')->nullable();
            $table->string('targets_achieved')->nullable();
            $table->string('conversion_ratio')->nullable();
            $table->string('revenue_contribution')->nullable();
            $table->boolean('deadline_consistency')->nullable();
            $table->text('discipline_accountability')->nullable();
            $table->tinyInteger('rating_proactiveness_ownership')->nullable();
            // Tech Development team
            $table->boolean('tasks_completed_on_time')->nullable();
            $table->string('code_quality_bugs_fixed')->nullable();
            $table->text('contribution_to_roadmap')->nullable();
            $table->text('innovation_ideas')->nullable();
            $table->boolean('collaboration_with_teams')->nullable();
            $table->tinyInteger('rating_technical_competency')->nullable();
            // Manager’s Final Inputs
            $table->text('employee_strengths')->nullable();
            $table->text('areas_of_improvement')->nullable();
            $table->string('recommendation_probation')->nullable();
            $table->boolean('is_eligible_for_hike')->nullable();
            $table->string('hike_percentage_suggestion')->nullable();
            $table->boolean('manager_approve')->default(false);
            $table->string('manager_review_comments')->nullable();
            // Higher Authority / Management (Final Review Fields)
            $table->text('manager_evaluation_validation')->nullable();
            $table->text('cross_team_comparison')->nullable();
            $table->string('pca_audit_score')->nullable();
            $table->string('hike_decision')->nullable();
            $table->string('final_hike_percentage')->nullable();
            $table->string('probation_decision')->nullable();
            $table->text('future_role_growth_plan')->nullable();
            $table->integer('management_review')->nullable();
            $table->string('final_remarks')->nullable();
            $table->string('auditor_review')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};
