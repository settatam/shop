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
        Schema::table('transaction_offers', function (Blueprint $table) {
            // Track who responded to the offer (admin or customer)
            $table->foreignId('responded_by_user_id')->nullable()->after('customer_response')->constrained('users')->nullOnDelete();
            $table->foreignId('responded_by_customer_id')->nullable()->after('responded_by_user_id')->constrained('customers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaction_offers', function (Blueprint $table) {
            $table->dropForeign(['responded_by_user_id']);
            $table->dropForeign(['responded_by_customer_id']);
            $table->dropColumn(['responded_by_user_id', 'responded_by_customer_id']);
        });
    }
};
