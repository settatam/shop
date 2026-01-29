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
        Schema::table('memos', function (Blueprint $table) {
            // Discount fields
            $table->decimal('discount_value', 10, 2)->default(0)->after('total');
            $table->enum('discount_unit', ['percent', 'fixed'])->default('fixed')->after('discount_value');
            $table->string('discount_reason')->nullable()->after('discount_unit');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_reason');

            // Service fee fields
            $table->decimal('service_fee_value', 10, 2)->default(0)->after('discount_amount');
            $table->enum('service_fee_unit', ['percent', 'fixed'])->default('fixed')->after('service_fee_value');
            $table->string('service_fee_reason')->nullable()->after('service_fee_unit');
            $table->decimal('service_fee_amount', 10, 2)->default(0)->after('service_fee_reason');

            // Tax type (percent or fixed amount)
            $table->enum('tax_type', ['percent', 'fixed'])->default('percent')->after('charge_taxes');
            $table->decimal('tax_amount', 10, 2)->default(0)->after('tax_type');

            // Grand total (subtotal - discount + service_fee + tax + shipping)
            $table->decimal('grand_total', 10, 2)->default(0)->after('tax_amount');

            // Payment tracking
            $table->decimal('total_paid', 10, 2)->default(0)->after('grand_total');
            $table->decimal('balance_due', 10, 2)->default(0)->after('total_paid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memos', function (Blueprint $table) {
            $table->dropColumn([
                'discount_value',
                'discount_unit',
                'discount_reason',
                'discount_amount',
                'service_fee_value',
                'service_fee_unit',
                'service_fee_reason',
                'service_fee_amount',
                'tax_type',
                'tax_amount',
                'grand_total',
                'total_paid',
                'balance_due',
            ]);
        });
    }
};
