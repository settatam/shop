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
            $table->decimal('service_fee_value', 10, 2)->nullable()->after('amount');
            $table->string('service_fee_unit', 10)->nullable()->after('service_fee_value');
            $table->decimal('service_fee_amount', 10, 2)->nullable()->after('service_fee_unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['service_fee_value', 'service_fee_unit', 'service_fee_amount']);
        });
    }
};
