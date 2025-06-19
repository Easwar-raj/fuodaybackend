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
            $table->string('audit_month')->nullable();
            $table->string('task_highlight')->nullable();
            $table->string('challenges')->nullable();
            $table->string('support')->nullable();
            $table->string('self_rating')->nullable();
            $table->string('comment')->nullable();
            $table->string('work_justification')->nullable();
            $table->string('manager_review')->nullable();
            $table->string('final_remarks')->nullable();
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
