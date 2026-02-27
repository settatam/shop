<?php

namespace App\Console\Commands;

use App\Models\ProductAttributeValue;
use App\Models\ProductTemplateField;
use Illuminate\Console\Command;

class CleanupJunkAttributeValues extends Command
{
    protected $signature = 'app:cleanup-junk-attribute-values
                            {--dry-run : Show what would be deleted without making changes}';

    protected $description = 'Remove product attribute values with "0" for select/radio/checkbox fields (legacy "not selected" values)';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Find all select/radio/checkbox field IDs
        $fieldIds = ProductTemplateField::whereIn('type', ProductTemplateField::TYPES_WITH_OPTIONS)
            ->pluck('id');

        if ($fieldIds->isEmpty()) {
            $this->info('No select/radio/checkbox fields found.');

            return 0;
        }

        // Find attribute values with "0" for those fields
        $query = ProductAttributeValue::where('value', '0')
            ->whereIn('product_template_field_id', $fieldIds);

        $count = $query->count();

        if ($count === 0) {
            $this->info('No junk "0" attribute values found.');

            return 0;
        }

        $this->info("Found {$count} attribute values with \"0\" for select/radio/checkbox fields.");

        if ($isDryRun) {
            $this->warn("Dry run complete - {$count} rows would be deleted.");

            return 0;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} junk attribute values.");

        return 0;
    }
}
