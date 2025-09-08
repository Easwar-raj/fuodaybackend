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
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id(); // Primary key
            $table->string('name');
            $table->string('email')->unique(); // Unique constraint on email
            $table->string('phone');
            $table->string('password'); // Store hashed passwords
            $table->string('company_name');
            $table->string('logo')->nullable();
            $table->string('brand_logo')->nullable();
            $table->string('allowed_users');
            $table->string('address')->nullable();
            $table->timestamps(); // Created at and updated at columns
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
