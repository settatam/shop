<?php

namespace App\Console\Commands;

use App\Models\Role;
use Illuminate\Console\Command;

class CleanupReportPermissions extends Command
{
    protected $signature = 'permissions:cleanup-reports';

    protected $description = 'Migrate old granular report permissions to the unified reports.view permission';

    public function handle(): int
    {
        $oldPermissions = [
            'reports.view_sales',
            'reports.view_inventory',
            'reports.view_customers',
            'reports.view_activity',
            'reports.view_buys',
            'reports.view_transactions',
        ];

        $roles = Role::all();

        if ($roles->isEmpty()) {
            $this->info('No roles found.');

            return self::SUCCESS;
        }

        $this->info("Processing {$roles->count()} roles...");

        $updated = 0;

        foreach ($roles as $role) {
            $currentPermissions = $role->permissions ?? [];

            // Check if role has any old report permissions
            $hadReportPermission = collect($currentPermissions)
                ->contains(fn ($p) => in_array($p, $oldPermissions));

            // Remove old permissions
            $newPermissions = collect($currentPermissions)
                ->reject(fn ($p) => in_array($p, $oldPermissions))
                ->values()
                ->all();

            // Add unified permission if they had any report permission
            if ($hadReportPermission && ! in_array('reports.view', $newPermissions)) {
                $newPermissions[] = 'reports.view';
            }

            // Only update if permissions changed
            if ($currentPermissions !== $newPermissions) {
                $role->update(['permissions' => $newPermissions]);
                $this->line("  Updated: {$role->name}");
                $updated++;
            }
        }

        $this->newLine();
        $this->info("Done! Updated {$updated} roles.");

        return self::SUCCESS;
    }
}
