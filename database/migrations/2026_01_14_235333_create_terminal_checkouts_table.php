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
        Schema::create('terminal_checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('terminal_id')->constrained('payment_terminals')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            $table->string('checkout_id'); // External checkout ID from gateway
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');

            $table->string('status')->default('pending'); // pending, processing, completed, failed, cancelled, timeout
            $table->string('external_payment_id')->nullable(); // Payment ID from gateway after completion

            $table->text('error_message')->nullable();
            $table->json('gateway_response')->nullable();

            $table->integer('timeout_seconds')->default(300); // 5 minute default
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['checkout_id']);
            $table->index(['terminal_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terminal_checkouts');
    }
};
