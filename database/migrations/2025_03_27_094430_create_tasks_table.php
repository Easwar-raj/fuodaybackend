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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_user_id')->constrained('web_users')->onDelete('cascade');
            $table->string('emp_name')->nullable();
            $table->string('emp_id')->nullable();
            $table->date('date');
            $table->text('description');
            $table->string('assigned_by')->nullable();
            $table->foreignId('assigned_by_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->string('assigned_to');
            $table->foreignId('assigned_to_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            $table->string('project')->nullable();
            $table->string('priority');
            $table->string('status')->nullable();
            $table->string('progress_note')->nullable();
            $table->date('deadline');
            $table->string('comment')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
