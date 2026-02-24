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
        Schema::create('scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('report_type'); // legacy_daily_sales, legacy_daily_buy, etc.
            $table->string('name')->nullable(); // Optional custom name
            $table->json('recipients'); // Array of email addresses
            $table->time('schedule_time')->default('00:00:00'); // Time of day to send
            $table->string('timezone')->default('America/New_York');
            $table->json('schedule_days')->nullable(); // Null = daily, or array of days [1,2,3,4,5] for weekdays
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'is_enabled']);
            $table->index(['schedule_time', 'is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_reports');
    }
};
