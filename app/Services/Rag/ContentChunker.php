<?php

namespace App\Services\Rag;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreKnowledgeBaseEntry;

class ContentChunker
{
    /**
     * Build a text chunk for a product.
     *
     * @return array{point_id: string, text: string, payload: array<string, mixed>}
     */
    public function chunkProduct(Product $product): array
    {
        $product->loadMissing(['brand', 'category', 'variants', 'attributeValues.field']);

        $parts = [];
        $parts[] = $product->title;

        if ($product->brand) {
            $parts[] = "Brand: {$product->brand->name}";
        }

        if ($product->category) {
            $parts[] = "Category: {$product->category->name}";
        }

        if ($product->condition) {
            $parts[] = "Condition: {$product->condition}";
        }

        if ($product->description) {
            $parts[] = strip_tags($product->description);
        }

        // Attributes from template fields
        foreach ($product->attributeValues as $attr) {
            if ($attr->value && $attr->field) {
                $parts[] = "{$attr->field->label}: {$attr->value}";
            }
        }

        // Variant info
        $defaultVariant = $product->variants->first();
        if ($defaultVariant?->price) {
            $parts[] = 'Price: $'.number_format($defaultVariant->price, 2);
        }

        if ($product->variants->count() > 1) {
            $variantDescriptions = $product->variants->map(function ($v) {
                $options = collect([
                    $v->option1_value ? "{$v->option1_name}: {$v->option1_value}" : null,
                    $v->option2_value ? "{$v->option2_name}: {$v->option2_value}" : null,
                    $v->option3_value ? "{$v->option3_name}: {$v->option3_value}" : null,
                ])->filter()->implode(', ');

                $price = $v->price ? '$'.number_format($v->price, 2) : '';

                return trim("{$options} {$price}");
            })->filter()->implode('; ');

            if ($variantDescriptions) {
                $parts[] = "Variants: {$variantDescriptions}";
            }
        }

        $text = implode("\n", array_filter($parts));

        return [
            'point_id' => "product_{$product->id}",
            'text' => $text,
            'payload' => [
                'store_id' => $product->store_id,
                'content_type' => 'product',
                'content_id' => $product->id,
                'title' => $product->title,
                'metadata' => [
                    'price' => $defaultVariant?->price ? round($defaultVariant->price, 2) : null,
                    'category' => $product->category?->name,
                    'brand' => $product->brand?->name,
                    'condition' => $product->condition,
                    'available' => ($product->total_quantity ?? 0) > 0,
                ],
            ],
        ];
    }

    /**
     * Build a text chunk for a knowledge base entry.
     *
     * @return array{point_id: string, text: string, payload: array<string, mixed>}
     */
    public function chunkKnowledgeBaseEntry(StoreKnowledgeBaseEntry $entry): array
    {
        $typeLabel = match ($entry->type) {
            'return_policy' => 'Return Policy',
            'shipping_info' => 'Shipping Information',
            'care_instructions' => 'Care Instructions',
            'faq' => 'FAQ',
            'about' => 'About the Store',
            default => ucfirst(str_replace('_', ' ', $entry->type)),
        };

        $text = "[{$typeLabel}] {$entry->title}\n{$entry->content}";

        return [
            'point_id' => "kb_{$entry->id}",
            'text' => $text,
            'payload' => [
                'store_id' => $entry->store_id,
                'content_type' => 'knowledge_base',
                'content_id' => $entry->id,
                'title' => $entry->title,
                'metadata' => [
                    'kb_type' => $entry->type,
                ],
            ],
        ];
    }

    /**
     * Build a text chunk for a category.
     *
     * @return array{point_id: string, text: string, payload: array<string, mixed>}
     */
    public function chunkCategory(Category $category): array
    {
        $parts = ["Category: {$category->name}"];

        if ($category->description) {
            $parts[] = strip_tags($category->description);
        }

        if ($category->meta_description) {
            $parts[] = $category->meta_description;
        }

        return [
            'point_id' => "category_{$category->id}",
            'text' => implode("\n", $parts),
            'payload' => [
                'store_id' => $category->store_id,
                'content_type' => 'category',
                'content_id' => $category->id,
                'title' => $category->name,
                'metadata' => [],
            ],
        ];
    }

    /**
     * Build a text chunk for store info.
     *
     * @return array{point_id: string, text: string, payload: array<string, mixed>}
     */
    public function chunkStore(Store $store): array
    {
        $parts = ["Store: {$store->name}"];

        if ($store->business_name && $store->business_name !== $store->name) {
            $parts[] = "Business name: {$store->business_name}";
        }

        if ($store->meta_description) {
            $parts[] = $store->meta_description;
        }

        if ($store->address) {
            $location = collect([
                $store->address, $store->city, $store->state, $store->zip,
            ])->filter()->implode(', ');
            $parts[] = "Location: {$location}";
        }

        if ($store->phone) {
            $parts[] = "Phone: {$store->phone}";
        }

        if ($store->url) {
            $parts[] = "Website: {$store->url}";
        }

        return [
            'point_id' => "store_{$store->id}",
            'text' => implode("\n", $parts),
            'payload' => [
                'store_id' => $store->id,
                'content_type' => 'store_info',
                'content_id' => $store->id,
                'title' => $store->name,
                'metadata' => [],
            ],
        ];
    }
}
