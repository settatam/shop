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
        Schema::table('products', function (Blueprint $table) {
            $table->string('condition', 50)->nullable()->after('is_published');
            $table->decimal('domestic_shipping_cost', 10, 2)->nullable()->after('height');
            $table->decimal('international_shipping_cost', 10, 2)->nullable()->after('domestic_shipping_cost');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['condition', 'domestic_shipping_cost', 'international_shipping_cost']);
        });
    }
};
