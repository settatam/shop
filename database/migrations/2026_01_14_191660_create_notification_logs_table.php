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
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('notification_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20); // email, sms, push
            $table->string('activity')->nullable(); // Activity that triggered it
            $table->string('recipient'); // Email, phone, device token
            $table->string('recipient_type', 20)->nullable(); // customer, staff, owner, custom
            $table->nullableMorphs('notifiable'); // The entity being notified about (Order, Product, etc.)
            $table->nullableMorphs('recipient_model'); // User, Customer, etc.
            $table->string('subject')->nullable();
            $table->text('content'); // Rendered content
            $table->json('data')->nullable(); // Context data used
            $table->string('status', 20)->default('pending'); // pending, sent, delivered, failed, bounced
            $table->text('error_message')->nullable();
            $table->string('external_id')->nullable(); // Message ID from provider
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'channel', 'status']);
            $table->index(['store_id', 'activity']);
            $table->index('sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
