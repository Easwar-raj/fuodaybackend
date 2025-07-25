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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_user_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->string('emp_name')->nullable();
            $table->string('emp_id')->nullable();
            $table->date('date');
            $table->string('checkin')->nullable();
            $table->string('checkout')->nullable();
            $table->string('worked_hours')->nullable();
            $table->string('location')->nullable();
            $table->string('regulation_checkin')->nullable();
            $table->string('regulation_checkout')->nullable();
            $table->string('regulation_date')->nullable();
            $table->string('regulation_status')->nullable();
            $table->string('reason')->nullable();
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
