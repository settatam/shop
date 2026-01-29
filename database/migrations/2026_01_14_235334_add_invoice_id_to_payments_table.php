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
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
            $table->foreignId('terminal_checkout_id')->nullable()->after('invoice_id')->constrained('terminal_checkouts')->nullOnDelete();
            $table->string('gateway_payment_id')->nullable()->after('gateway');
            $table->json('gateway_response')->nullable()->after('gateway_payment_id');

            $table->index(['store_id', 'invoice_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropForeign(['terminal_checkout_id']);
            $table->dropIndex(['store_id', 'invoice_id']);
            $table->dropColumn(['invoice_id', 'terminal_checkout_id', 'gateway_payment_id', 'gateway_response']);
        });
    }
};
