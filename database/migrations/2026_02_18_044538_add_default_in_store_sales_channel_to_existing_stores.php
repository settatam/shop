<?php

use App\Models\SalesChannel;
use App\Models\Store;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get all stores that don't have an "In Store" sales channel
        $storesWithoutInStore = Store::whereDoesntHave('salesChannels', function ($query) {
            $query->where('code', 'in_store');
        })->get();

        foreach ($storesWithoutInStore as $store) {
            SalesChannel::create([
                'store_id' => $store->id,
                'name' => 'In Store',
                'code' => 'in_store',
                'type' => SalesChannel::TYPE_LOCAL,
                'is_local' => true,
                'is_active' => true,
                'is_default' => true,
                'sort_order' => 0,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't delete as this could cause data integrity issues
        // Orders may be linked to these channels
    }
};
