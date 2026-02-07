<?php

namespace App\Services\Marketplace\Listing;

use App\Enums\Platform;
use App\Models\Product;
use App\Services\AI\AIManager;
use App\Services\Marketplace\DTOs\PlatformProduct;

class ListingTransformer
{
    public function __construct(
        protected AIManager $ai
    ) {}

    /**
     * Transform a product for a specific platform.
     */
    public function transform(Product $product, Platform $platform): PlatformProduct
    {
        $baseProduct = $this->buildBaseProduct($product);

        return match ($platform) {
            Platform::Amazon => $this->transformForAmazon($product, $baseProduct),
            Platform::Walmart => $this->transformForWalmart($product, $baseProduct),
            Platform::Shopify => $this->transformForShopify($product, $baseProduct),
            Platform::BigCommerce => $this->transformForBigCommerce($product, $baseProduct),
            Platform::Ebay => $this->transformForEbay($product, $baseProduct),
            Platform::Etsy => $this->transformForEtsy($product, $baseProduct),
            default => $baseProduct,
        };
    }

    /**
     * Build the base platform product from a local product.
     */
    protected function buildBaseProduct(Product $product): PlatformProduct
    {
        $variant = $product->variants()->first();

        return new PlatformProduct(
            title: $product->title,
            description: $product->description ?? '',
            sku: $variant?->sku ?? $product->sku ?? null,
            barcode: $product->upc ?? $product->ean ?? null,
            price: (float) ($variant?->price ?? $product->price ?? 0),
            compareAtPrice: $product->compare_at_price ? (float) $product->compare_at_price : null,
            quantity: (int) ($variant?->quantity ?? $product->quantity ?? 0),
            weight: $product->weight ? (float) $product->weight : null,
            weightUnit: $product->weight_unit ?? 'lb',
            brand: $product->brand?->name,
            category: $product->category?->name,
            categoryId: $product->category_id ? (string) $product->category_id : null,
            images: $product->images->pluck('url')->toArray(),
            attributes: $this->buildAttributes($product),
            condition: $product->condition ?? 'new',
            status: $product->is_published ? 'active' : 'draft',
            metadata: [
                'local_id' => $product->id,
                'is_jewelry' => $product->is_jewelry,
                'metal_type' => $product->metal_type,
                'metal_purity' => $product->metal_purity,
            ],
        );
    }

    /**
     * Build product attributes array.
     */
    protected function buildAttributes(Product $product): array
    {
        $attributes = [];

        if ($product->is_jewelry) {
            if ($product->metal_type) {
                $attributes['material'] = ucfirst($product->metal_type);
            }
            if ($product->metal_purity) {
                $attributes['purity'] = $product->metal_purity;
            }
            if ($product->jewelry_type) {
                $attributes['jewelry_type'] = ucfirst($product->jewelry_type);
            }
            if ($product->main_stone_type) {
                $attributes['gemstone'] = ucfirst($product->main_stone_type);
            }
            if ($product->total_carat_weight) {
                $attributes['carat_weight'] = $product->total_carat_weight;
            }
            if ($product->ring_size) {
                $attributes['ring_size'] = $product->ring_size;
            }
            if ($product->chain_length_inches) {
                $attributes['chain_length'] = $product->chain_length_inches.' inches';
            }
        }

        return $attributes;
    }

    /**
     * Transform product for Amazon marketplace.
     */
    protected function transformForAmazon(Product $product, PlatformProduct $base): PlatformProduct
    {
        $optimized = $this->optimizeWithAI($product, Platform::Amazon);

        return new PlatformProduct(
            title: $this->truncate($optimized['title'] ?? $base->title, 200),
            description: $optimized['description'] ?? $base->description,
            sku: $base->sku,
            barcode: $base->barcode,
            price: $base->price,
            compareAtPrice: $base->compareAtPrice,
            quantity: $base->quantity,
            weight: $base->weight,
            weightUnit: $base->weightUnit,
            brand: $base->brand,
            category: $optimized['category'] ?? $base->category,
            categoryId: $optimized['category_id'] ?? $base->categoryId,
            images: $base->images,
            attributes: array_merge($base->attributes, [
                'bullet_points' => $optimized['bullet_points'] ?? [],
                'search_terms' => $optimized['search_terms'] ?? [],
            ]),
            condition: $base->condition,
            status: $base->status,
            metadata: array_merge($base->metadata, [
                'platform' => 'amazon',
                'product_type' => $optimized['product_type'] ?? null,
            ]),
        );
    }

    /**
     * Transform product for Walmart marketplace.
     */
    protected function transformForWalmart(Product $product, PlatformProduct $base): PlatformProduct
    {
        $optimized = $this->optimizeWithAI($product, Platform::Walmart);

        return new PlatformProduct(
            title: $this->truncate($optimized['title'] ?? $base->title, 200),
            description: $this->truncate($optimized['description'] ?? $base->description, 4000),
            sku: $base->sku,
            barcode: $base->barcode,
            price: $base->price,
            compareAtPrice: $base->compareAtPrice,
            quantity: $base->quantity,
            weight: $base->weight,
            weightUnit: $base->weightUnit,
            brand: $base->brand,
            category: $optimized['category'] ?? $base->category,
            categoryId: $optimized['category_id'] ?? $base->categoryId,
            images: $base->images,
            attributes: array_merge($base->attributes, [
                'short_description' => $this->truncate($optimized['short_description'] ?? '', 1000),
                'key_features' => $optimized['key_features'] ?? [],
            ]),
            condition: $base->condition,
            status: $base->status,
            metadata: array_merge($base->metadata, [
                'platform' => 'walmart',
                'shelf_name' => $optimized['shelf_name'] ?? null,
            ]),
        );
    }

    /**
     * Transform product for Shopify store.
     */
    protected function transformForShopify(Product $product, PlatformProduct $base): PlatformProduct
    {
        $optimized = $this->optimizeWithAI($product, Platform::Shopify);

        return new PlatformProduct(
            title: $optimized['title'] ?? $base->title,
            description: $optimized['description'] ?? $base->description,
            sku: $base->sku,
            barcode: $base->barcode,
            price: $base->price,
            compareAtPrice: $base->compareAtPrice,
            quantity: $base->quantity,
            weight: $base->weight,
            weightUnit: $base->weightUnit,
            brand: $base->brand,
            category: $optimized['product_type'] ?? $base->category,
            images: $base->images,
            attributes: $base->attributes,
            condition: $base->condition,
            status: $base->status,
            metadata: array_merge($base->metadata, [
                'platform' => 'shopify',
                'tags' => $optimized['tags'] ?? [],
                'seo_title' => $optimized['seo_title'] ?? null,
                'seo_description' => $optimized['seo_description'] ?? null,
                'handle' => $optimized['handle'] ?? $this->generateHandle($base->title),
            ]),
        );
    }

    /**
     * Transform product for BigCommerce store.
     */
    protected function transformForBigCommerce(Product $product, PlatformProduct $base): PlatformProduct
    {
        $optimized = $this->optimizeWithAI($product, Platform::BigCommerce);

        return new PlatformProduct(
            title: $optimized['title'] ?? $base->title,
            description: $optimized['description'] ?? $base->description,
            sku: $base->sku,
            barcode: $base->barcode,
            price: $base->price,
            compareAtPrice: $base->compareAtPrice,
            quantity: $base->quantity,
            weight: $base->weight,
            weightUnit: $base->weightUnit,
            brand: $base->brand,
            category: $base->category,
            categoryId: $base->categoryId,
            images: $base->images,
            attributes: $base->attributes,
            condition: $base->condition,
            status: $base->status,
            metadata: array_merge($base->metadata, [
                'platform' => 'bigcommerce',
                'search_keywords' => $optimized['search_keywords'] ?? '',
                'meta_description' => $optimized['meta_description'] ?? null,
            ]),
        );
    }

    /**
     * Transform product for eBay marketplace.
     */
    protected function transformForEbay(Product $product, PlatformProduct $base): PlatformProduct
    {
        $optimized = $this->optimizeWithAI($product, Platform::Ebay);

        return new PlatformProduct(
            title: $this->truncate($optimized['title'] ?? $base->title, 80),
            description: $optimized['description'] ?? $base->description,
            sku: $base->sku,
            barcode: $base->barcode,
            price: $base->price,
            compareAtPrice: $base->compareAtPrice,
            quantity: $base->quantity,
            weight: $base->weight,
            weightUnit: $base->weightUnit,
            brand: $base->brand,
            category: $optimized['category'] ?? $base->category,
            categoryId: $optimized['category_id'] ?? $base->categoryId,
            images: $base->images,
            attributes: array_merge($base->attributes, [
                'item_specifics' => $optimized['item_specifics'] ?? [],
            ]),
            condition: $this->mapEbayCondition($base->condition),
            status: $base->status,
            metadata: array_merge($base->metadata, [
                'platform' => 'ebay',
            ]),
        );
    }

    /**
     * Transform product for Etsy marketplace.
     */
    protected function transformForEtsy(Product $product, PlatformProduct $base): PlatformProduct
    {
        $optimized = $this->optimizeWithAI($product, Platform::Etsy);

        return new PlatformProduct(
            title: $this->truncate($optimized['title'] ?? $base->title, 140),
            description: $optimized['description'] ?? $base->description,
            sku: $base->sku,
            barcode: $base->barcode,
            price: $base->price,
            compareAtPrice: $base->compareAtPrice,
            quantity: $base->quantity,
            weight: $base->weight,
            weightUnit: $base->weightUnit,
            brand: $base->brand,
            category: $base->category,
            images: $base->images,
            attributes: $base->attributes,
            condition: $base->condition,
            status: $base->status,
            metadata: array_merge($base->metadata, [
                'platform' => 'etsy',
                'tags' => $optimized['tags'] ?? [],
                'who_made' => $optimized['who_made'] ?? 'someone_else',
                'when_made' => $optimized['when_made'] ?? 'made_to_order',
            ]),
        );
    }

    /**
     * Use AI to optimize product content for a specific platform.
     *
     * @return array{title: string, description: string, bullet_points?: array, tags?: array, category?: string, ...}
     */
    protected function optimizeWithAI(Product $product, Platform $platform): array
    {
        $prompt = $this->buildOptimizationPrompt($product, $platform);

        $schema = $this->getOptimizationSchema($platform);

        try {
            $response = $this->ai->generateJson($prompt, $schema, [
                'feature' => 'listing_optimization',
            ]);

            return $response->content;
        } catch (\Throwable) {
            // Return empty array on failure - will use base product
            return [];
        }
    }

    /**
     * Build the AI prompt for product optimization.
     */
    protected function buildOptimizationPrompt(Product $product, Platform $platform): string
    {
        $productInfo = [
            'title' => $product->title,
            'description' => $product->description,
            'brand' => $product->brand?->name,
            'category' => $product->category?->name,
            'price' => $product->price,
            'condition' => $product->condition,
        ];

        if ($product->is_jewelry) {
            $productInfo['jewelry_details'] = [
                'metal_type' => $product->metal_type,
                'metal_purity' => $product->metal_purity,
                'metal_color' => $product->metal_color,
                'jewelry_type' => $product->jewelry_type,
                'main_stone' => $product->main_stone_type,
                'carat_weight' => $product->total_carat_weight,
            ];
        }

        $platformGuidelines = $this->getPlatformGuidelines($platform);

        return <<<PROMPT
You are an expert e-commerce listing optimizer. Transform the following product for the {$platform->label()} platform.

## Product Information
```json
{$this->jsonEncode($productInfo)}
```

## Platform Guidelines
{$platformGuidelines}

## Instructions
1. Create an optimized title following {$platform->label()}'s best practices
2. Write a compelling description that converts
3. Generate relevant keywords and tags
4. Suggest the best category for this product
5. For marketplaces, create bullet points highlighting key features

Return optimized content that will maximize visibility and conversions on {$platform->label()}.
PROMPT;
    }

    /**
     * Get platform-specific optimization guidelines.
     */
    protected function getPlatformGuidelines(Platform $platform): string
    {
        return match ($platform) {
            Platform::Amazon => <<<'GUIDELINES'
- Title: 200 char max, include brand, key features, size/color if relevant
- 5 bullet points: Start with caps, highlight benefits, include keywords
- Description: Use HTML formatting, focus on benefits
- Search terms: 250 bytes max, no commas needed, no brand name
- Include relevant keywords naturally throughout
GUIDELINES,
            Platform::Walmart => <<<'GUIDELINES'
- Title: 200 char max, include brand and key features
- Short description: 1000 char max, summary for search results
- Key features: 5-7 bullet points
- Rich media content encouraged
- Focus on value proposition and competitive pricing
GUIDELINES,
            Platform::Shopify => <<<'GUIDELINES'
- SEO-optimized title and description
- Use HTML in description for formatting
- Generate relevant tags for collections and search
- Create URL-friendly handle
- Focus on brand storytelling
GUIDELINES,
            Platform::BigCommerce => <<<'GUIDELINES'
- Rich HTML description with formatting
- Search keywords field for discoverability
- Meta description for SEO
- Focus on product features and benefits
GUIDELINES,
            Platform::Ebay => <<<'GUIDELINES'
- Title: 80 char max, front-load keywords
- Item specifics are crucial for search
- HTML description allowed
- Condition must match eBay's condition IDs
GUIDELINES,
            Platform::Etsy => <<<'GUIDELINES'
- Title: 140 char max, include key search terms
- 13 tags maximum, use full tag length
- Story-driven description works well
- Highlight handmade/vintage/unique aspects
- who_made: i_did, collective, someone_else
- when_made: made_to_order, 2020_2024, etc.
GUIDELINES,
            default => 'Follow e-commerce best practices for title, description, and keywords.',
        };
    }

    /**
     * Get the JSON schema for AI response based on platform.
     */
    protected function getOptimizationSchema(Platform $platform): array
    {
        $baseSchema = [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
            ],
            'required' => ['title', 'description'],
        ];

        $platformProperties = match ($platform) {
            Platform::Amazon => [
                'bullet_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                'search_terms' => ['type' => 'array', 'items' => ['type' => 'string']],
                'category' => ['type' => 'string'],
                'product_type' => ['type' => 'string'],
            ],
            Platform::Walmart => [
                'short_description' => ['type' => 'string'],
                'key_features' => ['type' => 'array', 'items' => ['type' => 'string']],
                'category' => ['type' => 'string'],
                'shelf_name' => ['type' => 'string'],
            ],
            Platform::Shopify => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'seo_title' => ['type' => 'string'],
                'seo_description' => ['type' => 'string'],
                'handle' => ['type' => 'string'],
                'product_type' => ['type' => 'string'],
            ],
            Platform::BigCommerce => [
                'search_keywords' => ['type' => 'string'],
                'meta_description' => ['type' => 'string'],
            ],
            Platform::Ebay => [
                'item_specifics' => ['type' => 'object'],
                'category' => ['type' => 'string'],
                'category_id' => ['type' => 'string'],
            ],
            Platform::Etsy => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 13],
                'who_made' => ['type' => 'string', 'enum' => ['i_did', 'collective', 'someone_else']],
                'when_made' => ['type' => 'string'],
            ],
            default => [],
        };

        $baseSchema['properties'] = array_merge($baseSchema['properties'], $platformProperties);

        return $baseSchema;
    }

    /**
     * Map condition to eBay-specific condition.
     */
    protected function mapEbayCondition(string $condition): string
    {
        return match (strtolower($condition)) {
            'new' => 'NEW',
            'like_new', 'like new', 'excellent' => 'LIKE_NEW',
            'very_good', 'very good' => 'VERY_GOOD',
            'good' => 'GOOD',
            'acceptable' => 'ACCEPTABLE',
            'refurbished' => 'SELLER_REFURBISHED',
            'parts', 'for_parts' => 'FOR_PARTS_OR_NOT_WORKING',
            default => 'NEW',
        };
    }

    /**
     * Generate a URL-friendly handle from title.
     */
    protected function generateHandle(string $title): string
    {
        $handle = strtolower($title);
        $handle = preg_replace('/[^a-z0-9\s-]/', '', $handle) ?? $handle;
        $handle = preg_replace('/[\s-]+/', '-', $handle) ?? $handle;

        return trim($handle, '-');
    }

    /**
     * Truncate string to specified length.
     */
    protected function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length - 3).'...';
    }

    /**
     * JSON encode with error handling.
     */
    protected function jsonEncode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
