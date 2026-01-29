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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('transaction_number')->unique();
            $table->string('status')->default('pending');
            $table->string('type')->default('in_house'); // in_house, mail_in

            // Financial
            $table->decimal('preliminary_offer', 10, 2)->nullable();
            $table->decimal('final_offer', 10, 2)->nullable();
            $table->decimal('estimated_value', 10, 2)->nullable();
            $table->string('payment_method')->nullable(); // cash, check, store_credit, ach, paypal, venmo

            // Metadata
            $table->string('bin_location')->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();

            // Dates
            $table->timestamp('offer_given_at')->nullable();
            $table->timestamp('offer_accepted_at')->nullable();
            $table->timestamp('payment_processed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'transaction_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
