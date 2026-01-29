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
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('return_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('return_number')->unique();
            $table->string('status');
            $table->string('type');

            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('restocking_fee', 10, 2)->default(0);
            $table->decimal('refund_amount', 10, 2)->default(0);
            $table->string('refund_method')->nullable();
            $table->unsignedBigInteger('store_credit_id')->nullable();

            $table->string('reason')->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();

            $table->string('external_return_id')->nullable();
            $table->string('source_platform')->nullable();
            $table->foreignId('store_marketplace_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->string('sync_status')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['store_id', 'return_number']);
            $table->index(['store_id', 'status']);
            $table->index(['external_return_id', 'store_marketplace_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};
