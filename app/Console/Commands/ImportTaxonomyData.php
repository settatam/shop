<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportTaxonomyData extends Command
{
    protected $signature = 'taxonomy:import
                            {--ebay : Import eBay categories and item specifics}
                            {--google : Import Google categories}
                            {--etsy : Import Etsy categories}
                            {--all : Import all taxonomy data}
                            {--connection=legacy : The database connection to import from}';

    protected $description = 'Import taxonomy data (eBay, Google, Etsy categories) from the legacy database';

    public function handle(): int
    {
        $connection = $this->option('connection');
        $importAll = $this->option('all');

        try {
            DB::connection($connection)->getPdo();
        } catch (\Exception $e) {
            $this->error("Could not connect to the '{$connection}' database.");
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Connected to '{$connection}' database.");

        if ($importAll || $this->option('ebay')) {
            $this->importEbayCategories($connection);
            $this->importEbayItemSpecifics($connection);
            $this->importEbayItemSpecificValues($connection);
        }

        if ($importAll || $this->option('google')) {
            $this->importGoogleCategories($connection);
        }

        if ($importAll || $this->option('etsy')) {
            $this->importEtsyCategories($connection);
        }

        if (! $importAll && ! $this->option('ebay') && ! $this->option('google') && ! $this->option('etsy')) {
            $this->warn('No import option specified. Use --all, --ebay, --google, or --etsy.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Taxonomy import completed successfully!');

        return self::SUCCESS;
    }

    protected function importEbayCategories(string $connection): void
    {
        $this->info('Importing eBay categories...');

        $count = DB::connection($connection)->table('ebay_categories')->count();
        $this->info("Found {$count} eBay categories to import.");

        if ($count === 0) {
            return;
        }

        // Disable foreign key checks and truncate existing data
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('ebay_categories')->truncate();

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        DB::connection($connection)
            ->table('ebay_categories')
            ->orderBy('id')
            ->chunk(1000, function ($categories) use ($bar) {
                $data = [];
                foreach ($categories as $category) {
                    $data[] = [
                        'id' => $category->id,
                        'name' => $category->name,
                        'level' => $category->level,
                        'parent_id' => $category->parent_id ?: null, // Convert 0 to null
                        'ebay_parent_id' => $category->ebay_parent_id ?: null,
                        'ebay_category_id' => $category->ebay_category_id,
                        'comments' => $category->comments ?? null,
                        'created_at' => $category->created_at ?? now(),
                        'updated_at' => $category->updated_at ?? now(),
                    ];
                    $bar->advance();
                }
                DB::table('ebay_categories')->insert($data);
            });

        $bar->finish();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->newLine();
        $this->info("Imported {$count} eBay categories.");
    }

    protected function importEbayItemSpecifics(string $connection): void
    {
        $this->info('Importing eBay item specifics...');

        $count = DB::connection($connection)->table('ebay_item_specifics')->count();
        $this->info("Found {$count} eBay item specifics to import.");

        if ($count === 0) {
            return;
        }

        // Disable foreign key checks and truncate existing data
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('ebay_item_specifics')->truncate();

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        DB::connection($connection)
            ->table('ebay_item_specifics')
            ->orderBy('id')
            ->chunk(1000, function ($specifics) use ($bar) {
                $data = [];
                foreach ($specifics as $specific) {
                    $data[] = [
                        'id' => $specific->id,
                        'ebay_category_id' => $specific->ebay_category_id,
                        'name' => $specific->name,
                        'type' => $specific->type,
                        'is_required' => $specific->is_required ?? false,
                        'is_recommended' => $specific->is_recommended ?? false,
                        'aspect_mode' => $specific->aspect_mode ?? null,
                        'is_condition_descriptor' => $specific->is_condition_descriptor ?? null,
                        'created_at' => $specific->created_at ?? now(),
                        'updated_at' => $specific->updated_at ?? now(),
                    ];
                    $bar->advance();
                }
                DB::table('ebay_item_specifics')->insert($data);
            });

        $bar->finish();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->newLine();
        $this->info("Imported {$count} eBay item specifics.");
    }

    protected function importEbayItemSpecificValues(string $connection): void
    {
        $this->info('Importing eBay item specific values...');

        $count = DB::connection($connection)->table('ebay_item_specific_values')->count();
        $this->info("Found {$count} eBay item specific values to import.");

        if ($count === 0) {
            return;
        }

        // Disable foreign key checks and truncate existing data
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('ebay_item_specific_values')->truncate();

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        DB::connection($connection)
            ->table('ebay_item_specific_values')
            ->orderBy('id')
            ->chunk(5000, function ($values) use ($bar) {
                $data = [];
                foreach ($values as $value) {
                    $data[] = [
                        'id' => $value->id,
                        'ebay_category_id' => $value->ebay_category_id,
                        'ebay_item_specific_id' => $value->ebay_item_specific_id,
                        'value' => $value->value,
                        'created_at' => $value->created_at ?? now(),
                        'updated_at' => $value->updated_at ?? now(),
                    ];
                    $bar->advance();
                }
                DB::table('ebay_item_specific_values')->insert($data);
            });

        $bar->finish();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->newLine();
        $this->info("Imported {$count} eBay item specific values.");
    }

    protected function importGoogleCategories(string $connection): void
    {
        $this->info('Importing Google categories...');

        $count = DB::connection($connection)->table('google_categories')->count();
        $this->info("Found {$count} Google categories to import.");

        if ($count === 0) {
            return;
        }

        // Truncate existing data
        DB::table('google_categories')->truncate();

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        DB::connection($connection)
            ->table('google_categories')
            ->orderBy('id')
            ->chunk(1000, function ($categories) use ($bar) {
                $data = [];
                foreach ($categories as $category) {
                    $data[] = [
                        'id' => $category->id,
                        'name' => $category->name,
                        'google_id' => $category->google_id,
                        'created_at' => $category->created_at ?? now(),
                        'updated_at' => $category->updated_at ?? now(),
                    ];
                    $bar->advance();
                }
                DB::table('google_categories')->insert($data);
            });

        $bar->finish();
        $this->newLine();
        $this->info("Imported {$count} Google categories.");
    }

    protected function importEtsyCategories(string $connection): void
    {
        $this->info('Importing Etsy categories...');

        $count = DB::connection($connection)->table('etsy_categories')->count();
        $this->info("Found {$count} Etsy categories to import.");

        if ($count === 0) {
            return;
        }

        // Disable foreign key checks and truncate existing data
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('etsy_categories')->truncate();

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        DB::connection($connection)
            ->table('etsy_categories')
            ->orderBy('id')
            ->chunk(1000, function ($categories) use ($bar) {
                $data = [];
                foreach ($categories as $category) {
                    $data[] = [
                        'id' => $category->id,
                        'name' => $category->name,
                        'etsy_id' => $category->etsy_id,
                        'level' => $category->level,
                        'etsy_parent_id' => $category->etsy_parent_id ?: null,
                        'parent_id' => $category->parent_id ?: null, // Convert 0 to null
                        'created_at' => $category->created_at ?? now(),
                        'updated_at' => $category->updated_at ?? now(),
                    ];
                    $bar->advance();
                }
                DB::table('etsy_categories')->insert($data);
            });

        $bar->finish();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->newLine();
        $this->info("Imported {$count} Etsy categories.");
    }
}
