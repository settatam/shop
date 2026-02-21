<?php

use App\Services\EditionViewResolver;

if (! function_exists('edition_view')) {
    /**
     * Resolve the appropriate Inertia component path based on the current store's edition.
     *
     * If an edition-specific override exists at editions/{edition}/{basePath},
     * that will be returned. Otherwise, falls back to the default basePath.
     *
     * Usage in controllers:
     *   return Inertia::render(edition_view('products/Show'), ['product' => $product]);
     *   return Inertia::render(edition_view('Dashboard'), ['stats' => $stats]);
     *
     * @param  string  $path  The base component path (e.g., 'products/Show', 'Dashboard')
     */
    function edition_view(string $path): string
    {
        return app(EditionViewResolver::class)->resolve($path);
    }
}
