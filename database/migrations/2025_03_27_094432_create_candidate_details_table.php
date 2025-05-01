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
            $table->foreignId('candidate_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->string('place');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->date('dob');
            $table->string('job_id')->nullable();
            $table->string('designation');
            $table->string('department');
            $table->string('employment_status');
            $table->string('job_title');
            $table->string('nationality');
            $table->string('expected_ctc')->nullable();
            $table->string('address')->nullable();
            $table->string('education')->nullable();
            $table->string('certifications')->nullable();
            $table->string('skillset')->nullable();
            $table->string('experience')->nullable();
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
