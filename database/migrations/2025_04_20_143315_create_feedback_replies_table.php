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
        Schema::create('feedback_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->onDelete('cascade');
            $table->string('company_name')->nullable();
            $table->date('date')->nullable();
            $table->foreignId('feedback_id')->nullable()->constrained('feedbacks')->onDelete('cascade');
            $table->foreignId('from_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->string('from_name')->nullable();
            $table->foreignId('to_id')->nullable()->constrained('web_users')->onDelete('cascade');
            $table->string('to_name')->nullable();
            $table->string('reply')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback_replies');
    }
};
