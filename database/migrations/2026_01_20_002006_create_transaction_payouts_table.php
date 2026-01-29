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
        Schema::create('transaction_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('paypal'); // paypal, venmo
            $table->string('payout_batch_id')->nullable();
            $table->string('payout_item_id')->nullable();
            $table->string('transaction_id_external')->nullable(); // PayPal transaction ID
            $table->string('recipient_type'); // EMAIL, PHONE, PAYPAL_ID
            $table->string('recipient_value'); // email address, phone number, or PayPal ID
            $table->string('recipient_wallet')->nullable(); // PAYPAL, VENMO
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('pending'); // pending, processing, success, failed, unclaimed, returned
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('api_response')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['transaction_id', 'status']);
            $table->index('payout_batch_id');
            $table->index('payout_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_payouts');
    }
};
