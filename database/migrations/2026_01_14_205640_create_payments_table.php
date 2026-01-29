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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('payment_method', 50); // cash, card, store_credit, layaway, external
            $table->string('status', 30)->default('pending'); // pending, completed, failed, refunded
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');

            $table->string('reference')->nullable(); // Payment reference number
            $table->string('transaction_id')->nullable(); // External payment gateway transaction ID
            $table->string('gateway')->nullable(); // stripe, square, paypal, etc.

            $table->text('notes')->nullable();
            $table->json('metadata')->nullable(); // Additional gateway-specific data

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'order_id']);
            $table->index(['store_id', 'status']);
            $table->index('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
