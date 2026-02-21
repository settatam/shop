<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Facades\File;

class EditionViewResolver
{
    public function __construct(
        protected StoreContext $storeContext
    ) {}

    /**
     * Resolve the appropriate Inertia component path based on the store's edition.
     *
     * If an edition-specific override exists at editions/{edition}/{basePath},
     * that will be returned. Otherwise, falls back to the default basePath.
     *
     * @param  string  $basePath  The base component path (e.g., 'products/Show', 'Dashboard')
     * @param  Store|null  $store  Optional store to resolve for (defaults to current store)
     */
    public function resolve(string $basePath, ?Store $store = null): string
    {
        $store ??= $this->storeContext->getCurrentStore();
        $edition = $store?->edition ?? config('editions.default', 'standard');

        // Check for edition-specific override
        // e.g., "products/Show" → "editions/pawn_shop/products/Show"
        $editionPath = "editions/{$edition}/{$basePath}";

        if ($this->componentExists($editionPath)) {
            return $editionPath;
        }

        // Fall back to default
        return $basePath;
    }

    /**
     * Check if a Vue component exists at the given path.
     */
    protected function componentExists(string $path): bool
    {
        $vueFile = resource_path("js/pages/{$path}.vue");

        return File::exists($vueFile);
    }

    /**
     * Get all available edition overrides for a given base path.
     *
     * Useful for debugging or listing which editions have custom views.
     *
     * @return array<string, string> ['edition' => 'full_path']
     */
    public function getAvailableOverrides(string $basePath): array
    {
        $editions = array_keys(config('editions.editions', []));
        $overrides = [];

        foreach ($editions as $edition) {
            $editionPath = "editions/{$edition}/{$basePath}";
            if ($this->componentExists($editionPath)) {
                $overrides[$edition] = $editionPath;
            }
        }

        return $overrides;
    }

    /**
     * Check if the current store's edition has an override for a path.
     */
    public function hasOverride(string $basePath, ?Store $store = null): bool
    {
        $store ??= $this->storeContext->getCurrentStore();
        $edition = $store?->edition ?? config('editions.default', 'standard');

        $editionPath = "editions/{$edition}/{$basePath}";

        return $this->componentExists($editionPath);
    }
}
