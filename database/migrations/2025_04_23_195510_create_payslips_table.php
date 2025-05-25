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
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->nullable()->constrained('payrolls')->onDelete('cascade');
            $table->date('date')->nullable();
            $table->string('time')->nullable();
            $table->string('month')->nullable();
            $table->string('basic')->nullable();
            $table->string('overtime')->nullable();
            $table->string('total_paid_days')->nullable();
            $table->string('lop')->nullable();
            $table->string('gross')->nullable();
            $table->string('total_deductions')->nullable();
            $table->string('total_salary');
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payslip');
    }
};
