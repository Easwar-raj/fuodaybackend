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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_user_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->string('emp_name')->nullable();
            $table->string('emp_id')->nullable();
            $table->string('ticket');
            $table->string('category')->nullable();
            $table->foreignId('assigned_to_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->string('assigned_to')->nullable();
            $table->string('assigned_by');
            $table->string('priority');
            $table->date('date');
            $table->string('status')->nullable();
            $table->enum('system_type', ['hrms', 'ats'])->default('hrms');
            $table->foreignId('reassigned_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->string('reason_to_reassign', 500)->nullable();
            $table->text('description_to_reassign')->nullable();
            $table->enum('work_status', ['pending', 'in_progress', 'completed', 'reassigned', 'on_hold', 'cancelled'])->default('pending');
            $table->string('surrogate_key')->unique()->nullable();
            $table->text('work_status_reason')->nullable();
            $table->string('task_status')->nullable();
            $table->text('task_description')->nullable();
            $table->string('assignment_type')->nullable(); // e.g., 'Internal' or 'External'
            $table->string('support_by')->nullable(); // To store the support person's name
            $table->text('assignee_description')->nullable(); // A single column for the description
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
