<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('charge_taxes')->default(true)->after('label_template_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('charge_taxes')->default(true)->after('sell_out_of_stock');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('charge_taxes');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('charge_taxes');
        });
    }
};
