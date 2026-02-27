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
        Schema::table('platform_listings', function (Blueprint $table) {
            $table->unsignedInteger('quantity_override')->nullable()->after('platform_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_listings', function (Blueprint $table) {
            $table->dropColumn('quantity_override');
        });
    }
};
