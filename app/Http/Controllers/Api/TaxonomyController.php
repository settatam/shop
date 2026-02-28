<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AmazonCategory;
use App\Models\EbayCategory;
use App\Models\EbayItemSpecific;
use App\Models\EtsyCategory;
use App\Models\GoogleCategory;
use App\Models\WalmartCategory;
use App\Services\AI\CategorySuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxonomyController extends Controller
{
    /**
     * Search eBay categories.
     */
    public function searchEbayCategories(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'nullable|string|max:255',
            'parent_id' => 'nullable|integer',
            'level' => 'nullable|integer|min:0|max:10',
        ]);

        $query = EbayCategory::query();

        if ($request->filled('query')) {
            $query->where('name', 'like', '%'.$request->input('query').'%')
                ->whereDoesntHave('children');
        } elseif ($request->filled('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        } else {
            $query->whereNull('parent_id');
        }

        if ($request->filled('level')) {
            $query->where('level', $request->level);
        }

        $isSearch = $request->filled('query');

        $categories = $query
            ->withCount('children')
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'ebay_category_id' => $cat->ebay_category_id,
                'level' => $cat->level,
                'parent_id' => $cat->parent_id,
                'children_count' => $cat->children_count,
                'has_children' => $cat->children_count > 0,
                'path' => $isSearch ? $cat->path : null,
            ]);

        return response()->json($categories);
    }

    /**
     * Get eBay category details with item specifics.
     */
    public function getEbayCategoryDetails(int $id): JsonResponse
    {
        $category = EbayCategory::with('parent')->findOrFail($id);

        // Build the breadcrumb path
        $path = [];
        $current = $category;
        while ($current) {
            array_unshift($path, [
                'id' => $current->id,
                'name' => $current->name,
            ]);
            $current = $current->parent;
        }

        // Get item specifics for this category
        $itemSpecifics = EbayItemSpecific::where('ebay_category_id', $category->ebay_category_id)
            ->with('values')
            ->orderByDesc('is_required')
            ->orderByDesc('is_recommended')
            ->orderBy('name')
            ->get()
            ->map(fn ($spec) => [
                'id' => $spec->id,
                'name' => $spec->name,
                'type' => $spec->type,
                'is_required' => $spec->is_required,
                'is_recommended' => $spec->is_recommended,
                'aspect_mode' => $spec->aspect_mode,
                'template_field_type' => $spec->template_field_type,
                'values' => $spec->values->pluck('value')->take(100)->toArray(),
                'values_count' => $spec->values->count(),
            ]);

        return response()->json([
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'ebay_category_id' => $category->ebay_category_id,
                'level' => $category->level,
                'path' => $path,
            ],
            'item_specifics' => $itemSpecifics,
            'item_specifics_count' => $itemSpecifics->count(),
        ]);
    }

    /**
     * Generate template fields from eBay category item specifics.
     */
    public function generateTemplateFields(int $categoryId): JsonResponse
    {
        $category = EbayCategory::findOrFail($categoryId);

        $itemSpecifics = EbayItemSpecific::where('ebay_category_id', $category->ebay_category_id)
            ->with('values')
            ->orderByDesc('is_required')
            ->orderByDesc('is_recommended')
            ->orderBy('name')
            ->get();

        $fields = $itemSpecifics->map(function ($spec, $index) {
            $hasValues = $spec->values->count() > 0;
            $values = $spec->values->pluck('value')->take(100)->toArray();

            return [
                'name' => $spec->name,
                'type' => $hasValues ? 'select' : $this->inferFieldType($spec->name),
                'is_required' => $spec->is_required,
                'placeholder' => $spec->is_required ? 'Required' : ($spec->is_recommended ? 'Recommended' : ''),
                'sort_order' => $index + 1,
                'options' => $hasValues ? $values : null,
                'source' => 'ebay',
                'ebay_item_specific_id' => $spec->id,
            ];
        });

        return response()->json([
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'path' => $category->path,
            ],
            'fields' => $fields,
            'fields_count' => $fields->count(),
        ]);
    }

    /**
     * Search Google categories.
     */
    public function searchGoogleCategories(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'nullable|string|max:255',
        ]);

        $query = GoogleCategory::query();

        if ($request->filled('query')) {
            $query->where('name', 'like', '%'.$request->input('query').'%');
        }

        $categories = $query
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'google_id' => $cat->google_id,
                'leaf_name' => $cat->leaf_name,
                'level' => $cat->level,
            ]);

        return response()->json($categories);
    }

    /**
     * Search Etsy categories.
     */
    public function searchEtsyCategories(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'nullable|string|max:255',
            'parent_id' => 'nullable|integer',
        ]);

        $query = EtsyCategory::query();

        if ($request->filled('query')) {
            $query->where('name', 'like', '%'.$request->input('query').'%');
        }

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        } elseif (! $request->filled('query')) {
            $query->whereNull('parent_id');
        }

        $categories = $query
            ->withCount('children')
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'etsy_id' => $cat->etsy_id,
                'level' => $cat->level,
                'parent_id' => $cat->parent_id,
                'children_count' => $cat->children_count,
                'has_children' => $cat->children_count > 0,
            ]);

        return response()->json($categories);
    }

    /**
     * Search Walmart categories.
     */
    public function searchWalmartCategories(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'nullable|string|max:255',
            'parent_id' => 'nullable|integer',
        ]);

        $query = WalmartCategory::query();

        if ($request->filled('query')) {
            $query->where('name', 'like', '%'.$request->input('query').'%')
                ->whereDoesntHave('children');
        } elseif ($request->filled('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        } else {
            $query->whereNull('parent_id');
        }

        $isSearch = $request->filled('query');

        $categories = $query
            ->withCount('children')
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'walmart_category_id' => $cat->walmart_category_id,
                'level' => $cat->level,
                'parent_id' => $cat->parent_id,
                'children_count' => $cat->children_count,
                'has_children' => $cat->children_count > 0,
                'path' => $isSearch ? $cat->path : null,
            ]);

        return response()->json($categories);
    }

    /**
     * Search Amazon categories.
     */
    public function searchAmazonCategories(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'nullable|string|max:255',
            'parent_id' => 'nullable|integer',
        ]);

        $query = AmazonCategory::query();

        if ($request->filled('query')) {
            $query->where('name', 'like', '%'.$request->input('query').'%')
                ->whereDoesntHave('children');
        } elseif ($request->filled('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        } else {
            $query->whereNull('parent_id');
        }

        $isSearch = $request->filled('query');

        $categories = $query
            ->withCount('children')
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'amazon_category_id' => $cat->amazon_category_id,
                'level' => $cat->level,
                'parent_id' => $cat->parent_id,
                'children_count' => $cat->children_count,
                'has_children' => $cat->children_count > 0,
                'path' => $isSearch ? $cat->path : null,
            ]);

        return response()->json($categories);
    }

    /**
     * Suggest eBay categories using AI.
     */
    public function suggestEbayCategory(Request $request, CategorySuggestionService $service): JsonResponse
    {
        $request->validate([
            'category_name' => 'required|string|max:255',
            'template_name' => 'nullable|string|max:255',
            'category_path' => 'nullable|string|max:500',
        ]);

        $suggestions = $service->suggestEbayCategories(
            $request->input('category_name'),
            $request->input('template_name'),
            $request->input('category_path'),
        );

        return response()->json($suggestions);
    }

    /**
     * Infer field type from the item specific name.
     */
    protected function inferFieldType(string $name): string
    {
        $name = strtolower($name);

        if (str_contains($name, 'weight') || str_contains($name, 'length') ||
            str_contains($name, 'width') || str_contains($name, 'height') ||
            str_contains($name, 'size') || str_contains($name, 'carat')) {
            return 'number';
        }

        if (str_contains($name, 'description') || str_contains($name, 'notes') ||
            str_contains($name, 'features')) {
            return 'textarea';
        }

        if (str_contains($name, 'date') || str_contains($name, 'year')) {
            return 'text';
        }

        return 'text';
    }
}
