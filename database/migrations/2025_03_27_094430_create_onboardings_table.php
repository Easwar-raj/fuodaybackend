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
        Schema::create('onboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_user_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->string('emp_name')->nullable();
            $table->string('emp_id')->nullable();
            $table->date('welcome_email_sent');
            $table->date('scheduled_date');
            $table->string('photo');
            $table->string('pan');
            $table->string('passbook');
            $table->string('payslip');
            $table->string('offer_letter');
            $table->string('aadhaar_no')->nullable();
            $table->string('pan_no')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('personal_contact_no');
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
        Schema::dropIfExists('onboardings');
    }
};
