<?php

namespace Database\Seeders;

use App\Services\Agents\AgentRegistry;
use Illuminate\Database\Seeder;

class AgentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $registry = app(AgentRegistry::class);
        $registry->syncToDatabase();

        $this->command->info('Agent definitions synced to database.');
    }
}
