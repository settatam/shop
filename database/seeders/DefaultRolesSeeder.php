<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Store;
use Illuminate\Database\Seeder;

class DefaultRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates default roles for all stores that don't have them yet.
     */
    public function run(): void
    {
        $stores = Store::all();

        foreach ($stores as $store) {
            // Check if store already has default roles
            $existingRoles = Role::where('store_id', $store->id)
                ->whereIn('slug', ['owner', 'admin', 'manager', 'staff', 'viewer'])
                ->count();

            if ($existingRoles === 0) {
                Role::createDefaultRoles($store->id);
                $this->command->info("Created default roles for store: {$store->name}");
            } else {
                $this->command->line("Store '{$store->name}' already has roles, skipping.");
            }
        }
    }
}
