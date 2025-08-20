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
        Schema::create('candidate_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->nullable()->constrained('candidates')->onDelete('cascade');
            $table->string('place');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->date('dob')->nullable();
            $table->string('job_id')->nullable();
            $table->string('designation');
            $table->string('department');
            $table->string('employment_status');
            $table->string('job_title');
            $table->string('job_location')->nullable();
            $table->string('nationality')->nullable();
            $table->string('expected_ctc')->nullable();
            $table->string('address')->nullable();
            $table->string('education')->nullable();
            $table->string('certifications')->nullable();
            $table->string('skillset')->nullable();
            $table->string('experience')->nullable();
            $table->string('current_job_title')->nullable();
            $table->string('current_employer')->nullable();
            $table->string('linkedin')->nullable();
            $table->string('cv')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_details');
    }
};
