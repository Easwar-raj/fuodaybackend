<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB; // <-- important!

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('policy_questions', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->string('options')->nullable(); // This can be used to store options if needed
            $table->timestamps();
        });

        // Insert hardcoded policy questions
        DB::table('policy_questions')->insert([
            [
                'question' => 'Does your organization calculate overtime on an hourly basis?',
                'options' => json_encode(['Yes', 'No']),
            ],
            [
                'question' => 'If employees work overtime on holidays, is it compensated at double rate?',
                'options' => json_encode(['Yes', 'No']),
            ],
            [
                'question' => 'Which days are considered weekly holidays in your organization?',
                'options' => json_encode(['Saturday', 'Sunday', 'Friday', 'Alternate Saturdays', 'Other']),
            ],
            [
                'question' => 'Do employees receive LOP for late arrivals? If yes, after how many warnings?',
                'options' => json_encode(['No LOP for late arrivals', 'After 1 warning', 'After 2 warnings', 'After 3 warnings']),
            ],
            [
                'question' => 'Is LOP applied for unauthorized leaves?',
                'options' => json_encode(['Yes', 'No']),
            ],
            [
                'question' => 'Is LOP applied when employees exhaust their leave quota?',
                'options' => json_encode(['Yes', 'No']),
            ],
            [
                'question' => 'What are the total weekly working hours in your organization?',
                'options' => json_encode([
                    '40 hours (5 days * 8 hours)',
                    '45 hours (5 days * 9 hours)',
                    '48 hours (6 days * 8 hours)',
                    'Other',
                ]),
            ],
            [
                'question' => 'What is the standard work time per day in your organization?',
                'options' => json_encode([
                    '8 hours',
                    '9 hours',
                    '10 hours',
                    'Flexible hours',
                ]),
            ],
            [
                'question' => 'How many hours of break time are provided per day in your organization?',
                'options' => json_encode([
                    '30 minutes',
                    '1 hour',
                    '2 hours',
                    'Breaks not fixed',
                ]),
            ],
            [
                'question' => 'What is your organization\'s general shift timing?',
                'options' => json_encode([
                    '08:00 AM - 05:00 PM',
                    '09:00 AM - 06:00 PM',
                    '10:00 AM - 07:00 PM',
                    'Flexible Shift',
                    'Other',
                ]),
            ],
            [
                'question' => 'Choose your organization\'s salary calculation period?',
                'options' => json_encode([
                    '26 to 25th of every month',
                    '1st to 31st of every month',
                    '15th to 14th of every month',
                    '1st to 15th of every month',
                    '15th to 30th of every month',
                    'Other',
                ]),
            ],
            [
                'question' => 'What is the salary date for your organization?',
                'options' => json_encode([
                    '26th of every month',
                    '1st of every month',
                    '5th of every month',
                    '10th of every month',
                    '15th of every month',
                    'Other',
                ]),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_questions');
    }
};
