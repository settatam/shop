<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Makes store_marketplace_id nullable to support local sales channels
     * (like "In Store") that don't have an external marketplace.
     */
    public function up(): void
    {
        Schema::table('platform_listings', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['store_marketplace_id']);
        });

        Schema::table('platform_listings', function (Blueprint $table) {
            // Make the column nullable and re-add foreign key with nullOnDelete
            $table->foreignId('store_marketplace_id')
                ->nullable()
                ->change();
        });

        Schema::table('platform_listings', function (Blueprint $table) {
            // Re-add the foreign key constraint
            $table->foreign('store_marketplace_id')
                ->references('id')
                ->on('store_marketplaces')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: This could fail if there are NULL values in the column
        Schema::table('platform_listings', function (Blueprint $table) {
            $table->dropForeign(['store_marketplace_id']);
        });

        Schema::table('platform_listings', function (Blueprint $table) {
            $table->foreignId('store_marketplace_id')
                ->nullable(false)
                ->change();
        });

        Schema::table('platform_listings', function (Blueprint $table) {
            $table->foreign('store_marketplace_id')
                ->references('id')
                ->on('store_marketplaces')
                ->cascadeOnDelete();
        });
    }
};
