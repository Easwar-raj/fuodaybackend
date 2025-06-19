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
            $table->string('audit_cycle_type')->nullable();
            $table->string('review_period')->nullable();
            $table->string('audit_month')->nullable();
            $table->string('attendance_percentage')->nullable();
            $table->string('self_rating')->nullable();
            $table->string('technical_skills_used')->nullable();
            $table->string('communication_collaboration')->nullable();
            $table->string('cross_functional_involvement')->nullable();
            $table->string('task_highlight')->nullable();
            $table->string('personal_highlight')->nullable();
            $table->string('areas_to_improve')->nullable();
            $table->string('initiative_taken')->nullable();
            $table->string('learnings_certifications')->nullable();
            $table->string('suggestions_to_company')->nullable();
            $table->string('previous_cycle_goals')->nullable();
            $table->string('goal_achievement')->nullable();
            $table->string('kpi_metrics')->nullable();
            $table->string('projects_worked')->nullable();
            $table->string('tasks_modules_completed')->nullable();
            $table->string('performance_evidences')->nullable();
            $table->string('manager_review_comments')->nullable();
            $table->string('execution_rating')->nullable();
            $table->string('innovation_rating')->nullable();
            $table->string('attendance_discipline_score')->nullable();
            $table->string('delivery_quality')->nullable();
            $table->string('ownership_initiative')->nullable();
            $table->string('team_growth_contribution')->nullable();
            $table->string('promotion_action_suggested')->nullable();
            $table->string('management_review')->nullable();
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
