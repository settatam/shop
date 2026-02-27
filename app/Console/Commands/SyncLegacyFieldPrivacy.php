<?php

namespace App\Console\Commands;

use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncLegacyFieldPrivacy extends Command
{
    protected $signature = 'sync:legacy-field-privacy
                            {--store-id=63 : Legacy store ID}
                            {--new-store-id= : New store ID (if different from legacy)}
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Backfill is_private flag on product_template_fields from legacy html_form_fields';

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('store-id');
        $newStoreId = $this->option('new-store-id') ? (int) $this->option('new-store-id') : null;
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Find the new store
        $legacyStore = DB::connection('legacy')
            ->table('stores')
            ->where('id', $legacyStoreId)
            ->first();

        if (! $legacyStore) {
            $this->error("Legacy store with ID {$legacyStoreId} not found");

            return 1;
        }

        $newStore = $newStoreId
            ? Store::find($newStoreId)
            : Store::where('name', $legacyStore->name)->first();

        if (! $newStore) {
            $this->error('New store not found.');

            return 1;
        }

        $this->info("Syncing is_private flags for store: {$newStore->name} (ID: {$newStore->id})");

        // Get legacy private fields with their template names
        $legacyPrivateFields = DB::connection('legacy')
            ->table('html_form_fields')
            ->join('html_forms', 'html_forms.id', '=', 'html_form_fields.html_form_id')
            ->where('html_forms.store_id', $legacyStoreId)
            ->where('html_form_fields.is_private', 1)
            ->select('html_form_fields.name as field_name', 'html_forms.title as template_name')
            ->get();

        $this->info("Found {$legacyPrivateFields->count()} private fields in legacy database");

        // Build template name mapping: legacy name → new template ID
        $newTemplatesByName = ProductTemplate::where('store_id', $newStore->id)
            ->get()
            ->keyBy(fn ($t) => strtolower($t->name));

        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($legacyPrivateFields as $legacyField) {
            $templateName = strtolower($legacyField->template_name);
            $newTemplate = $newTemplatesByName->get($templateName);

            if (! $newTemplate) {
                $this->line("  <comment>Skip:</comment> Template '{$legacyField->template_name}' not found in new system");
                $skippedCount++;

                continue;
            }

            // Find the matching field in the new template
            $newField = ProductTemplateField::where('product_template_id', $newTemplate->id)
                ->where(function ($query) use ($legacyField) {
                    $fieldName = strtolower($legacyField->field_name);
                    $query->whereRaw('LOWER(name) = ?', [$fieldName])
                        ->orWhereRaw('LOWER(name) = ?', [str_replace('-', '_', $fieldName)]);
                })
                ->first();

            if (! $newField) {
                $this->line("  <comment>Skip:</comment> Field '{$legacyField->field_name}' not found in template '{$legacyField->template_name}'");
                $skippedCount++;

                continue;
            }

            if ($newField->is_private) {
                $skippedCount++;

                continue;
            }

            $this->line("  <info>Update:</info> {$legacyField->template_name} → {$newField->name} → is_private = true");

            if (! $isDryRun) {
                $newField->update(['is_private' => true]);
            }

            $updatedCount++;
        }

        $this->newLine();
        $this->info('=== Summary ===');
        $this->line("Updated: {$updatedCount}");
        $this->line("Skipped: {$skippedCount}");

        if ($isDryRun) {
            $this->warn('Dry run complete - no changes made');
        }

        return 0;
    }
}
