<?php

namespace Database\Seeders;

use App\Models\LeadSource;
use App\Models\Store;
use Illuminate\Database\Seeder;

class LeadSourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default lead sources for all existing stores
        Store::all()->each(function (Store $store) {
            LeadSource::createDefaultsForStore($store->id);
        });
    }
}
