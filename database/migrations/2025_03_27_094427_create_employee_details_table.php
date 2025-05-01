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
        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_user_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->string('emp_name')->nullable();
            $table->string('emp_id')->nullable();
            $table->string('profile_photo')->nullable();
            $table->string('place');
            $table->string('designation');
            $table->string('department');
            $table->string('employment_type');
            $table->string('about')->nullable();
            $table->string('role_location')->nullable(); // place
            $table->string('work_module')->nullable(); // hybrid / remote / WFH / WFO
            $table->date('dob')->nullable();
            $table->string('address')->nullable();
            $table->date('date_of_joining');
            $table->string('reporting_manager_id')->nullable();
            $table->string('reporting_manager_name')->nullable();
            $table->string('aadhaar_no')->nullable();
            $table->string('pan_no')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('personal_contact_no')->nullable();
            $table->string('official_contact_no')->nullable();
            $table->string('official_email')->nullable();
            $table->string('permanent_address')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_no')->nullable();
            $table->string('ifsc')->nullable();
            $table->string('pf_account_no')->nullable();
            $table->string('uan')->nullable();
            $table->string('esi_no')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_details');
    }
};
