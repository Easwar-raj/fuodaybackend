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
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_user_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->string('emp_name')->nullable();
            $table->string('emp_id')->nullable();
            $table->date('date')->nullable();
            $table->string('department')->nullable();
            $table->string('type');
            $table->date('from');
            $table->date('to');
            $table->string('days')->nullable();
            $table->string('reason');
            $table->string('permission_timing')->nullable();
            $table->string('status');
            $table->string('comment')->nullable();
            $table->date('regulation_date')->nullable();
            $table->string('regulation_reason')->nullable();
            $table->string('regulation_status')->nullable();
            $table->string('regulation_comment')->nullable();
            $table->enum('hr_status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->enum('manager_status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->enum('hr_regulation_status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->enum('manager_regulation_status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
