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
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_user_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->string('emp_name')->nullable();
            $table->string('emp_id')->nullable();
            $table->string('name');
            $table->string('experience');
            $table->date('interview_date')->nullable();
            $table->string('role');
            $table->string('L1')->nullable();
            $table->string('L2')->nullable();
            $table->string('L3')->nullable();
            $table->string('ats_score')->nullable();
            $table->string('overall_score')->nullable();
            $table->string('technical_status')->nullable();
            $table->string('technical_feedback')->nullable();
            $table->string('hr_status')->nullable();
            $table->string('hr_feedback')->nullable();
            $table->string('contact')->nullable();
            $table->string('resume');
            $table->string('feedback')->nullable();
            $table->string('hiring_manager')->nullable();
            $table->string('hiring_status')->nullable();
            $table->string('referred_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
