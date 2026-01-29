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
        Schema::create('queued_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_subscription_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('notifiable'); // Order, Product, Customer, etc.
            $table->json('data'); // Serialized context data
            $table->timestamp('scheduled_at');
            $table->timestamp('sent_at')->nullable();
            $table->boolean('is_sent')->default(false);
            $table->boolean('has_error')->default(false);
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['scheduled_at', 'is_sent']);
            $table->index(['store_id', 'is_sent']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queued_notifications');
    }
};
