<?php

use App\Models\SalesChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration adds sales_channel_id to platform_listings, unifying
     * the concept of "where a product is listed" under SalesChannel.
     *
     * - External channels (Amazon, eBay, etc.): sales_channel links to store_marketplace
     * - Local channels (In Store): sales_channel has no store_marketplace
     *
     * This allows products to have channel-specific pricing, titles, and descriptions
     * regardless of whether the channel is local or external.
     */
    public function up(): void
    {
        Schema::table('platform_listings', function (Blueprint $table) {
            $table->foreignId('sales_channel_id')
                ->nullable()
                ->after('store_marketplace_id')
                ->constrained('sales_channels')
                ->nullOnDelete();
        });

        // Populate sales_channel_id from existing store_marketplace_id relationships
        // Find the sales_channel that links to each store_marketplace
        $listings = DB::table('platform_listings')
            ->whereNotNull('store_marketplace_id')
            ->whereNull('sales_channel_id')
            ->get();

        foreach ($listings as $listing) {
            $salesChannel = SalesChannel::where('store_marketplace_id', $listing->store_marketplace_id)
                ->first();

            if ($salesChannel) {
                DB::table('platform_listings')
                    ->where('id', $listing->id)
                    ->update(['sales_channel_id' => $salesChannel->id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_listings', function (Blueprint $table) {
            $table->dropForeign(['sales_channel_id']);
            $table->dropColumn('sales_channel_id');
        });
    }
};
