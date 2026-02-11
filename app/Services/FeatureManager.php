<?php

namespace App\Services;

use App\Models\Store;

class FeatureManager
{
    /**
     * Get features for a store based on its edition.
     *
     * @return array<string>
     */
    public function getFeaturesForStore(Store $store): array
    {
        $edition = $store->edition ?? config('editions.default', 'standard');

        return $this->getFeaturesForEdition($edition);
    }

    /**
     * Get features for an edition.
     *
     * @return array<string>
     */
    public function getFeaturesForEdition(string $edition): array
    {
        return config("editions.editions.{$edition}.features", []);
    }

    /**
     * Check if a store has a specific feature.
     */
    public function storeHasFeature(Store $store, string $feature): bool
    {
        $features = $this->getFeaturesForStore($store);

        return in_array($feature, $features, true);
    }

    /**
     * Check if a store has all of the specified features.
     *
     * @param  array<string>  $features
     */
    public function storeHasAllFeatures(Store $store, array $features): bool
    {
        $storeFeatures = $this->getFeaturesForStore($store);

        foreach ($features as $feature) {
            if (! in_array($feature, $storeFeatures, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a store has any of the specified features.
     *
     * @param  array<string>  $features
     */
    public function storeHasAnyFeature(Store $store, array $features): bool
    {
        $storeFeatures = $this->getFeaturesForStore($store);

        foreach ($features as $feature) {
            if (in_array($feature, $storeFeatures, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get edition info for a store.
     *
     * @return array{name: string, description: string, features: array<string>}|null
     */
    public function getEditionInfo(Store $store): ?array
    {
        $edition = $store->edition ?? config('editions.default', 'standard');

        return config("editions.editions.{$edition}");
    }

    /**
     * Get all available editions.
     *
     * @return array<string, array{name: string, description: string, features: array<string>}>
     */
    public function getAvailableEditions(): array
    {
        return config('editions.editions', []);
    }

    /**
     * Get the feature required for a navigation item.
     */
    public function getNavigationFeature(string $navItem): ?string
    {
        return config("editions.navigation.{$navItem}");
    }

    /**
     * Filter navigation items based on store's features.
     *
     * @param  array<array{name: string, href?: string, feature?: string, children?: array}>  $navigation
     * @return array<array{name: string, href?: string, children?: array}>
     */
    public function filterNavigation(Store $store, array $navigation): array
    {
        $storeFeatures = $this->getFeaturesForStore($store);

        return array_values(array_filter(
            array_map(function ($item) use ($storeFeatures) {
                // Check if this item has a feature requirement
                $requiredFeature = $item['feature'] ?? null;

                if ($requiredFeature && ! in_array($requiredFeature, $storeFeatures, true)) {
                    return null;
                }

                // Filter children if present
                if (isset($item['children']) && is_array($item['children'])) {
                    $filteredChildren = array_values(array_filter(
                        $item['children'],
                        fn ($child) => ! isset($child['feature']) || in_array($child['feature'], $storeFeatures, true)
                    ));

                    // If all children are filtered out, remove the parent too
                    if (empty($filteredChildren)) {
                        return null;
                    }

                    $item['children'] = $filteredChildren;
                }

                return $item;
            }, $navigation)
        ));
    }

    /**
     * Get field requirements for a store based on its edition.
     *
     * @param  string  $context  The context (e.g., 'products', 'orders')
     * @return array<string, array{required?: bool, label?: string, message?: string}>
     */
    public function getFieldRequirements(Store $store, string $context): array
    {
        $edition = $store->edition ?? config('editions.default', 'standard');
        $editionConfig = config("editions.editions.{$edition}", []);

        // Start with default requirements
        $defaults = config("editions.default_field_requirements.{$context}", []);

        // Merge with edition-specific requirements (edition overrides defaults)
        $editionRequirements = $editionConfig['field_requirements'][$context] ?? [];

        return array_merge($defaults, $editionRequirements);
    }

    /**
     * Check if a field is required for a store based on its edition.
     */
    public function isFieldRequired(Store $store, string $context, string $field): bool
    {
        $requirements = $this->getFieldRequirements($store, $context);

        return $requirements[$field]['required'] ?? false;
    }

    /**
     * Get the validation rules for a context based on store edition.
     *
     * @return array<string, string>
     */
    public function getValidationRules(Store $store, string $context): array
    {
        $requirements = $this->getFieldRequirements($store, $context);
        $rules = [];

        foreach ($requirements as $field => $config) {
            if ($config['required'] ?? false) {
                $rules[$field] = 'required';
            }
        }

        return $rules;
    }
}
