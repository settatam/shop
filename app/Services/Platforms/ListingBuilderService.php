<?php

namespace App\Services\Platforms;

use App\Enums\Platform;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ProductPlatformOverride;
use App\Models\StoreMarketplace;

class ListingBuilderService
{
    public function __construct(
        protected FieldMappingService $fieldMappingService,
        protected PlatformManager $platformManager
    ) {}

    /**
     * Build complete listing data for a product on a platform.
     *
     * @return array<string, mixed>
     */
    public function buildListing(Product $product, StoreMarketplace $marketplace): array
    {
        $product->load(['attributeValues.field', 'template.fields', 'template.platformMappings', 'platformOverrides', 'images', 'variants']);
        $platform = $marketplace->platform;

        // Start with base product data
        $listing = $this->getBaseProductData($product);

        // Apply platform-specific overrides
        $override = $this->getOverride($product, $marketplace);
        if ($override) {
            $listing = $this->applyOverrides($listing, $override);
        }

        // Transform template attributes using field mappings
        $transformedAttributes = $this->fieldMappingService->transformAttributes($product, $platform);
        $listing['attributes'] = array_merge($listing['attributes'] ?? [], $transformedAttributes);

        // Apply platform-specific transformations
        $listing = $this->applyPlatformTransformations($listing, $platform, $product);

        return $listing;
    }

    /**
     * Preview what will be sent to platform (without sending).
     *
     * @return array{listing: array<string, mixed>, validation: array{valid: bool, errors: array<string>, warnings: array<string>}}
     */
    public function previewListing(Product $product, StoreMarketplace $marketplace): array
    {
        $listing = $this->buildListing($product, $marketplace);
        $validation = $this->validateListing($product, $marketplace);

        return [
            'listing' => $listing,
            'validation' => $validation,
        ];
    }

    /**
     * Get validation errors and warnings before publishing.
     *
     * @return array{valid: bool, errors: array<string>, warnings: array<string>}
     */
    public function validateListing(Product $product, StoreMarketplace $marketplace): array
    {
        $errors = [];
        $warnings = [];
        $platform = $marketplace->platform;
        $platformKey = $platform->value;

        // Check base required fields
        if (empty($product->title)) {
            $errors[] = 'Product title is required';
        }

        // Check for images
        if ($product->images->isEmpty() && $product->legacyImages->isEmpty()) {
            $errors[] = 'At least one product image is required';
        }

        // Check pricing
        $variant = $product->variants->first();
        if (! $variant || ! $variant->price) {
            $errors[] = 'Product price is required';
        }

        // Check platform-specific required fields
        $template = $product->getTemplate();
        if ($template) {
            $unmappedRequired = $this->fieldMappingService->getUnmappedRequiredFields($template, $platform);
            foreach ($unmappedRequired as $field) {
                $warnings[] = "Required platform field '{$field}' is not mapped";
            }
        } else {
            $warnings[] = 'Product has no template - platform attributes cannot be mapped';
        }

        // Platform-specific validation
        switch ($platformKey) {
            case 'ebay':
                $this->validateEbayListing($product, $marketplace, $errors, $warnings);
                break;
            case 'shopify':
                $this->validateShopifyListing($product, $marketplace, $errors, $warnings);
                break;
            case 'amazon':
                $this->validateAmazonListing($product, $marketplace, $errors, $warnings);
                break;
            case 'etsy':
                $this->validateEtsyListing($product, $marketplace, $errors, $warnings);
                break;
            case 'walmart':
                $this->validateWalmartListing($product, $marketplace, $errors, $warnings);
                break;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get the override for a product on a marketplace.
     */
    public function getOverride(Product $product, StoreMarketplace $marketplace): ?ProductPlatformOverride
    {
        return ProductPlatformOverride::where('product_id', $product->id)
            ->where('store_marketplace_id', $marketplace->id)
            ->first();
    }

    /**
     * Create or update an override for a product on a marketplace.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveOverride(Product $product, StoreMarketplace $marketplace, array $data): ProductPlatformOverride
    {
        return ProductPlatformOverride::updateOrCreate(
            [
                'product_id' => $product->id,
                'store_marketplace_id' => $marketplace->id,
            ],
            $data
        );
    }

    /**
     * Get the existing listing for a product on a marketplace.
     */
    public function getExistingListing(Product $product, StoreMarketplace $marketplace): ?PlatformListing
    {
        return PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $marketplace->id)
            ->first();
    }

    /**
     * Get all platform listings for a product.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PlatformListing>
     */
    public function getProductListings(Product $product): \Illuminate\Database\Eloquent\Collection
    {
        return PlatformListing::where('product_id', $product->id)
            ->with('marketplace')
            ->get();
    }

    /**
     * Get base product data for listing.
     *
     * @return array<string, mixed>
     */
    protected function getBaseProductData(Product $product): array
    {
        $variant = $product->variants->first();
        $images = $product->images->pluck('url')->toArray();

        if (empty($images)) {
            $images = $product->legacyImages->pluck('url')->toArray();
        }

        return [
            'title' => $product->title,
            'description' => $product->description,
            'price' => $variant?->price,
            'compare_at_price' => $variant?->compare_at_price ?? $product->compare_at_price,
            'quantity' => $variant?->quantity ?? $product->quantity ?? 0,
            'sku' => $variant?->sku,
            'barcode' => $variant?->barcode,
            'weight' => $product->weight,
            'weight_unit' => $product->weight_unit ?? 'lb',
            'images' => $images,
            'condition' => $product->condition ?? 'new',
            'brand' => $product->brand?->name,
            'category' => $product->category?->name,
            'upc' => $product->upc,
            'ean' => $product->ean,
            'mpn' => $product->mpn,
            'attributes' => [],
        ];
    }

    /**
     * Apply overrides to listing data.
     *
     * @param  array<string, mixed>  $listing
     * @return array<string, mixed>
     */
    protected function applyOverrides(array $listing, ProductPlatformOverride $override): array
    {
        if ($override->title) {
            $listing['title'] = $override->title;
        }

        if ($override->description) {
            $listing['description'] = $override->description;
        }

        if ($override->price !== null) {
            $listing['price'] = $override->price;
        }

        if ($override->compare_at_price !== null) {
            $listing['compare_at_price'] = $override->compare_at_price;
        }

        if ($override->quantity !== null) {
            $listing['quantity'] = $override->quantity;
        }

        if ($override->category_id) {
            $listing['platform_category_id'] = $override->category_id;
        }

        if ($override->attributes) {
            $listing['attributes'] = array_merge($listing['attributes'] ?? [], $override->attributes);
        }

        return $listing;
    }

    /**
     * Apply platform-specific transformations.
     *
     * @param  array<string, mixed>  $listing
     * @return array<string, mixed>
     */
    protected function applyPlatformTransformations(array $listing, Platform $platform, Product $product): array
    {
        return match ($platform) {
            Platform::Ebay => $this->transformForEbay($listing),
            Platform::Shopify => $this->transformForShopify($listing, $product),
            Platform::Amazon => $this->transformForAmazon($listing),
            Platform::Etsy => $this->transformForEtsy($listing),
            Platform::Walmart => $this->transformForWalmart($listing),
            default => $listing,
        };
    }

    /**
     * Transform listing for eBay format.
     *
     * @param  array<string, mixed>  $listing
     * @return array<string, mixed>
     */
    protected function transformForEbay(array $listing): array
    {
        // eBay title limit is 80 characters
        if (strlen($listing['title']) > 80) {
            $listing['title'] = substr($listing['title'], 0, 77).'...';
        }

        // Map condition to eBay condition ID
        $listing['condition_id'] = $this->mapEbayCondition($listing['condition'] ?? 'new');

        // Build item specifics from attributes
        $listing['item_specifics'] = [];
        foreach ($listing['attributes'] ?? [] as $name => $value) {
            if ($value !== null && $value !== '') {
                $listing['item_specifics'][] = [
                    'Name' => ucfirst(str_replace('_', ' ', $name)),
                    'Value' => $value,
                ];
            }
        }

        return $listing;
    }

    /**
     * Transform listing for Shopify format.
     *
     * @param  array<string, mixed>  $listing
     * @return array<string, mixed>
     */
    protected function transformForShopify(array $listing, Product $product): array
    {
        // Convert description to body_html
        $listing['body_html'] = $listing['description'];

        // Build metafields using template metafield configuration
        $listing['metafields'] = $this->buildMetafields($product, Platform::Shopify, $listing['attributes'] ?? []);

        return $listing;
    }

    /**
     * Build metafields array using template configuration.
     * By default, ALL non-private template fields are sent as metafields.
     * Platform mappings can customize namespace/key or exclude specific fields.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<array{namespace: string, key: string, value: mixed, type: string}>
     */
    protected function buildMetafields(Product $product, Platform $platform, array $attributes): array
    {
        $metafields = [];
        $template = $product->getTemplate();

        if (! $template) {
            // No template - no metafield configuration available
            return $metafields;
        }

        // Load template fields
        $template->load('fields');

        // Get the template's platform mapping (optional - for customization)
        $platformMapping = $template->platformMappings()
            ->where('platform', $platform)
            ->first();

        // Get custom metafield configs if mapping exists
        $customMetafieldConfigs = $platformMapping?->getEnabledMetafields() ?? [];
        $excludedFields = $platformMapping?->excluded_metafields ?? [];

        // Build metafields for ALL non-private template fields
        foreach ($template->fields as $field) {
            // Skip private fields - these are internal only
            if ($field->is_private) {
                continue;
            }

            // Skip explicitly excluded fields
            if (in_array($field->name, $excludedFields)) {
                continue;
            }

            // Get the value (from attributes array or product attribute values)
            $value = $attributes[$field->name] ?? $this->getProductAttributeValue($product, $field->name);

            // Skip empty values
            if ($value === null || $value === '') {
                continue;
            }

            // Check if there's a custom config for this field
            $config = $customMetafieldConfigs[$field->name] ?? null;

            $metafields[] = [
                'namespace' => $config['namespace'] ?? 'custom',
                'key' => $config['key'] ?? $field->name,
                'value' => $value,
                'type' => $this->getShopifyMetafieldType($value),
            ];
        }

        return $metafields;
    }

    /**
     * Get a product's attribute value by field name.
     */
    protected function getProductAttributeValue(Product $product, string $fieldName): mixed
    {
        // Check product attribute values
        $attributeValue = $product->attributeValues
            ->first(fn ($av) => $av->field?->name === $fieldName);

        return $attributeValue?->value;
    }

    /**
     * Determine the Shopify metafield type based on the value.
     */
    protected function getShopifyMetafieldType(mixed $value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        }

        if (is_int($value)) {
            return 'number_integer';
        }

        if (is_float($value)) {
            return 'number_decimal';
        }

        if (is_array($value)) {
            return 'json';
        }

        // Check if it's a numeric string
        if (is_string($value) && is_numeric($value)) {
            return str_contains($value, '.') ? 'number_decimal' : 'number_integer';
        }

        return 'single_line_text_field';
    }

    /**
     * Transform listing for Amazon format.
     *
     * @param  array<string, mixed>  $listing
     * @return array<string, mixed>
     */
    protected function transformForAmazon(array $listing): array
    {
        // Amazon uses item_name instead of title
        $listing['item_name'] = $listing['title'];
        $listing['product_description'] = $listing['description'];
        $listing['brand_name'] = $listing['brand'] ?? 'Unbranded';

        return $listing;
    }

    /**
     * Transform listing for Etsy format.
     *
     * @param  array<string, mixed>  $listing
     * @return array<string, mixed>
     */
    protected function transformForEtsy(array $listing): array
    {
        // Etsy title limit is 140 characters
        if (strlen($listing['title']) > 140) {
            $listing['title'] = substr($listing['title'], 0, 137).'...';
        }

        // Extract materials from attributes
        $listing['materials'] = $listing['attributes']['material'] ?? null;

        // Map who_made and when_made defaults
        $listing['who_made'] = $listing['who_made'] ?? 'someone_else';
        $listing['when_made'] = $listing['when_made'] ?? ($listing['condition'] === 'new' ? 'made_to_order' : '2020_2026');

        return $listing;
    }

    /**
     * Transform listing for Walmart format.
     *
     * @param  array<string, mixed>  $listing
     * @return array<string, mixed>
     */
    protected function transformForWalmart(array $listing): array
    {
        $listing['productName'] = $listing['title'];
        $listing['shortDescription'] = substr($listing['description'] ?? '', 0, 1000);
        $listing['longDescription'] = $listing['description'];
        $listing['mainImageUrl'] = $listing['images'][0] ?? null;

        return $listing;
    }

    /**
     * Map condition string to eBay condition ID.
     */
    protected function mapEbayCondition(string $condition): int
    {
        return match (strtolower($condition)) {
            'new' => 1000,
            'new_with_tags', 'new with tags' => 1000,
            'new_without_tags', 'new without tags' => 1500,
            'new_with_defects', 'new with defects' => 1750,
            'manufacturer_refurbished', 'certified_refurbished' => 2000,
            'seller_refurbished' => 2500,
            'like_new', 'like new', 'excellent' => 2750,
            'very_good', 'very good' => 3000,
            'good' => 4000,
            'acceptable' => 5000,
            'pre_owned', 'pre-owned', 'used' => 3000,
            'for_parts', 'for parts' => 7000,
            default => 3000,
        };
    }

    /**
     * Validate eBay-specific listing requirements.
     *
     * @param  array<string>  $errors
     * @param  array<string>  $warnings
     */
    protected function validateEbayListing(Product $product, StoreMarketplace $marketplace, array &$errors, array &$warnings): void
    {
        // eBay requires policies
        $credentials = $marketplace->credentials ?? [];

        if (empty($credentials['fulfillment_policy_id'])) {
            $warnings[] = 'eBay fulfillment policy not configured';
        }

        if (empty($credentials['payment_policy_id'])) {
            $warnings[] = 'eBay payment policy not configured';
        }

        if (empty($credentials['return_policy_id'])) {
            $warnings[] = 'eBay return policy not configured';
        }

        // Title length
        if (strlen($product->title) > 80) {
            $warnings[] = 'eBay title will be truncated to 80 characters';
        }
    }

    /**
     * Validate Shopify-specific listing requirements.
     *
     * @param  array<string>  $errors
     * @param  array<string>  $warnings
     */
    protected function validateShopifyListing(Product $product, StoreMarketplace $marketplace, array &$errors, array &$warnings): void
    {
        // Shopify is relatively lenient
        if (! $product->handle) {
            $warnings[] = 'Product handle not set - will be auto-generated';
        }
    }

    /**
     * Validate Amazon-specific listing requirements.
     *
     * @param  array<string>  $errors
     * @param  array<string>  $warnings
     */
    protected function validateAmazonListing(Product $product, StoreMarketplace $marketplace, array &$errors, array &$warnings): void
    {
        // Amazon requires brand
        if (! $product->brand) {
            $warnings[] = 'Amazon strongly recommends a brand name';
        }

        // Amazon requires UPC/EAN for most categories
        if (! $product->upc && ! $product->ean) {
            $warnings[] = 'Amazon may require UPC or EAN for this category';
        }
    }

    /**
     * Validate Etsy-specific listing requirements.
     *
     * @param  array<string>  $errors
     * @param  array<string>  $warnings
     */
    protected function validateEtsyListing(Product $product, StoreMarketplace $marketplace, array &$errors, array &$warnings): void
    {
        // Etsy title limit
        if (strlen($product->title) > 140) {
            $warnings[] = 'Etsy title will be truncated to 140 characters';
        }

        // Etsy requires who_made and when_made
        $warnings[] = 'Review "Who made it" and "When made" values before publishing to Etsy';
    }

    /**
     * Validate Walmart-specific listing requirements.
     *
     * @param  array<string>  $errors
     * @param  array<string>  $warnings
     */
    protected function validateWalmartListing(Product $product, StoreMarketplace $marketplace, array &$errors, array &$warnings): void
    {
        // Walmart requires brand
        if (! $product->brand) {
            $errors[] = 'Brand is required for Walmart listings';
        }

        // Walmart requires category
        if (! $product->category) {
            $warnings[] = 'Walmart category mapping is recommended';
        }
    }
}
