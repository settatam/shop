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
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('trade_in_transaction_id')
                ->nullable()
                ->after('memo_id')
                ->constrained('transactions')
                ->nullOnDelete();
            $table->decimal('trade_in_credit', 12, 2)
                ->default(0)
                ->after('discount_cost');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['trade_in_transaction_id']);
            $table->dropColumn(['trade_in_transaction_id', 'trade_in_credit']);
        });
    }
};
