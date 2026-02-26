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
        Schema::table('return_policies', function (Blueprint $table) {
            $table->string('return_shipping_cost_payer')->nullable()->after('excluded_conditions');
            $table->string('refund_method')->nullable()->after('return_shipping_cost_payer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('return_policies', function (Blueprint $table) {
            $table->dropColumn(['return_shipping_cost_payer', 'refund_method']);
        });
    }
};
