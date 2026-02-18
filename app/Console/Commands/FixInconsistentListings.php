<?php

namespace App\Console\Commands;

use App\Models\PlatformListing;
use App\Models\Product;
use Illuminate\Console\Command;

class FixInconsistentListings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'listings:fix-inconsistent {--dry-run : Show what would be fixed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Unlist all platform listings for products that are not active';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Find all listings that are active/pending but their product is not active
        $inconsistentListings = PlatformListing::whereIn('status', ['active', 'pending'])
            ->whereHas('product', function ($query) {
                $query->where('status', '!=', Product::STATUS_ACTIVE);
            })
            ->with(['product:id,title,status', 'salesChannel:id,name', 'marketplace:id,name,platform'])
            ->get();

        if ($inconsistentListings->isEmpty()) {
            $this->info('No inconsistent listings found. All listings are in sync with their products.');

            return Command::SUCCESS;
        }

        $this->warn("Found {$inconsistentListings->count()} inconsistent listing(s):");
        $this->newLine();

        $rows = $inconsistentListings->map(function ($listing) {
            $channelName = $listing->salesChannel?->name
                ?? $listing->marketplace?->name
                ?? 'Unknown';

            return [
                $listing->id,
                $listing->product?->title ?? 'Unknown',
                $listing->product?->status ?? 'N/A',
                $channelName,
                $listing->status,
            ];
        })->toArray();

        $this->table(
            ['Listing ID', 'Product', 'Product Status', 'Channel', 'Listing Status'],
            $rows
        );

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run mode - no changes made. Run without --dry-run to fix these listings.');

            return Command::SUCCESS;
        }

        if (! $this->confirm('Do you want to unlist all these listings?')) {
            $this->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        $updated = PlatformListing::whereIn('id', $inconsistentListings->pluck('id'))
            ->update(['status' => 'unlisted']);

        $this->info("Successfully unlisted {$updated} listing(s).");

        return Command::SUCCESS;
    }
}
