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
        Schema::create('notification_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_template_id')->constrained()->cascadeOnDelete();
            $table->string('activity'); // Activity slug from Activity model (products.create, orders.fulfill, etc.)
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->json('conditions')->nullable(); // Optional conditions for triggering
            $table->json('recipients')->nullable(); // Who to notify: owner, customer, staff, custom emails
            $table->string('schedule_type', 20)->default('immediate'); // immediate, delayed, scheduled
            $table->unsignedInteger('delay_minutes')->nullable(); // For delayed notifications
            $table->string('delay_unit', 10)->nullable(); // minutes, hours, days
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'activity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_subscriptions');
    }
};
