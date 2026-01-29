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
        Schema::table('stores', function (Blueprint $table) {
            $table->decimal('default_tax_rate', 5, 4)->default(0)->after('jewelry_module_enabled');
            $table->string('tax_id_number')->nullable()->after('default_tax_rate');
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 4)->nullable()->after('priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['default_tax_rate', 'tax_id_number']);
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });
    }
};
