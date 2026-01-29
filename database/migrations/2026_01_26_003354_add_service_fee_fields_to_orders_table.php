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
            // Service fee fields
            $table->decimal('service_fee_value', 10, 2)->default(0)->after('discount_cost');
            $table->enum('service_fee_unit', ['percent', 'fixed'])->default('fixed')->after('service_fee_value');
            $table->string('service_fee_reason')->nullable()->after('service_fee_unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'service_fee_value',
                'service_fee_unit',
                'service_fee_reason',
            ]);
        });
    }
};
