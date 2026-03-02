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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shipping_address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();

            $table->string('lead_number')->unique();
            $table->string('status')->default('pending_kit_request');
            $table->unsignedBigInteger('status_id')->nullable();
            $table->string('type')->default('mail_in');
            $table->string('source')->nullable();

            // Financial
            $table->decimal('preliminary_offer', 10, 2)->nullable();
            $table->decimal('final_offer', 10, 2)->nullable();
            $table->decimal('estimated_value', 10, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->json('payment_details')->nullable();

            // Metadata
            $table->string('bin_location')->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('customer_description')->nullable();
            $table->decimal('customer_amount', 10, 2)->nullable();
            $table->string('customer_categories')->nullable();

            // Tracking
            $table->string('outbound_tracking_number')->nullable();
            $table->string('outbound_carrier')->nullable();
            $table->string('return_tracking_number')->nullable();
            $table->string('return_carrier')->nullable();

            // Dates
            $table->timestamp('kit_sent_at')->nullable();
            $table->timestamp('kit_delivered_at')->nullable();
            $table->timestamp('items_received_at')->nullable();
            $table->timestamp('items_reviewed_at')->nullable();
            $table->timestamp('return_shipped_at')->nullable();
            $table->timestamp('return_delivered_at')->nullable();
            $table->timestamp('offer_given_at')->nullable();
            $table->timestamp('offer_accepted_at')->nullable();
            $table->timestamp('payment_processed_at')->nullable();

            // Legacy import tracking
            $table->unsignedBigInteger('legacy_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('status_id')->references('id')->on('statuses')->nullOnDelete();
            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'lead_number']);
            $table->index(['type', 'status']);
            $table->index('legacy_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
