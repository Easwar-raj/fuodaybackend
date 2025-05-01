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
        Schema::create('dependants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_user_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->string('emp_name')->nullable();
            $table->string('emp_id')->nullable();
            $table->string('father_name')->nullable();
            $table->date('father_dob')->nullable();
            $table->string('mother_name')->nullable();
            $table->date('mother_dob')->nullable();
            $table->string('spouce_name')->nullable();
            $table->date('spouce_dob')->nullable();
            $table->string('child_1_name')->nullable();
            $table->date('child_1_dob')->nullable();
            $table->string('child_2_name')->nullable();
            $table->date('child_2_dob')->nullable();
            $table->string('child_3_name')->nullable();
            $table->date('child_3_dob')->nullable();
            $table->string('emergency_contact_1_name')->nullable();
            $table->string('emergency_contact_1_no')->nullable();
            $table->string('emergency_contact_1_relationship')->nullable();
            $table->string('emergency_contact_2_name')->nullable();
            $table->string('emergency_contact_2_no')->nullable();
            $table->string('emergency_contact_2_relationship')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dependants');
    }
};
