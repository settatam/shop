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
        Schema::table('repairs', function (Blueprint $table) {
            // Payment adjustment fields
            $table->boolean('charge_taxes')->default(true)->after('tax_rate');
            $table->enum('tax_type', ['inclusive', 'exclusive'])->default('exclusive')->after('charge_taxes');

            // Discount adjustment fields
            $table->decimal('discount_value', 10, 2)->default(0)->after('discount');
            $table->enum('discount_unit', ['fixed', 'percent'])->default('fixed')->after('discount_value');
            $table->string('discount_reason')->nullable()->after('discount_unit');

            // Service fee adjustment fields
            $table->decimal('service_fee_value', 10, 2)->default(0)->after('service_fee');
            $table->enum('service_fee_unit', ['fixed', 'percent'])->default('fixed')->after('service_fee_value');
            $table->string('service_fee_reason')->nullable()->after('service_fee_unit');

            // Payment tracking fields
            $table->decimal('grand_total', 10, 2)->default(0)->after('total');
            $table->decimal('total_paid', 10, 2)->default(0)->after('grand_total');
            $table->decimal('balance_due', 10, 2)->default(0)->after('total_paid');
        });

        // Initialize grand_total from total for existing records
        \DB::statement('UPDATE repairs SET grand_total = total, balance_due = total WHERE grand_total = 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repairs', function (Blueprint $table) {
            $table->dropColumn([
                'charge_taxes',
                'tax_type',
                'discount_value',
                'discount_unit',
                'discount_reason',
                'service_fee_value',
                'service_fee_unit',
                'service_fee_reason',
                'grand_total',
                'total_paid',
                'balance_due',
            ]);
        });
    }
};
