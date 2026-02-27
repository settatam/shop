<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\EbayCategory;
use App\Models\LeadSource;
use App\Models\NotificationLayout;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use App\Models\ProductTemplate;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    /**
     * Categories to exclude from onboarding (not suitable for product inventory).
     */
    protected const EXCLUDED_CATEGORIES = [
        'eBay Motors',
        'Real Estate',
        'Specialty Services',
        'Tickets & Experiences',
        'Travel',
        'Gift Cards & Coupons',
        'Everything Else',
    ];

    public function __construct(protected StoreContext $storeContext) {}

    /**
     * Display the onboarding wizard.
     */
    public function index(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        // If already completed onboarding, redirect to dashboard
        if ($store && ! $store->needsOnboarding()) {
            return redirect()->route('dashboard');
        }

        // Get product categories (top-level, excluding non-product categories)
        $ebayCategories = EbayCategory::whereNull('parent_id')
            ->whereNotIn('name', self::EXCLUDED_CATEGORIES)
            ->orderBy('name')
            ->get(['id', 'name', 'ebay_category_id']);

        // Check if user already has existing categories/templates
        $existingCategories = [];
        $hasExistingSetup = false;

        if ($store) {
            $existingCategories = Category::where('store_id', $store->id)
                ->whereNull('parent_id')
                ->withCount('children')
                ->orderBy('name')
                ->get(['id', 'name']);

            $hasExistingSetup = $existingCategories->isNotEmpty() ||
                               ProductTemplate::where('store_id', $store->id)->exists();
        }

        return Inertia::render('onboarding/Index', [
            'store' => $store,
            'productCategories' => $ebayCategories,
            'existingCategories' => $existingCategories,
            'hasExistingSetup' => $hasExistingSetup,
        ]);
    }

    /**
     * Complete the onboarding process.
     */
    public function complete(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ebay_category_ids' => ['nullable', 'array'],
            'ebay_category_ids.*' => ['integer', 'exists:ebay_categories,id'],
            'skip_categories' => ['boolean'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:2'],
            'create_sample_data' => ['boolean'],
        ]);

        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('onboarding.index')
                ->with('error', 'No store found.');
        }

        $skipCategories = $validated['skip_categories'] ?? false;
        $ebayIds = $validated['ebay_category_ids'] ?? [];

        DB::transaction(function () use ($store, $validated, $skipCategories, $ebayIds) {
            // 1. Only process categories if not skipping and there are selections
            if (! $skipCategories && ! empty($ebayIds)) {
                // Attach selected eBay categories to store
                $store->ebayCategories()->sync($ebayIds);

                // Create local categories from Level-2 children
                $this->createCategoriesFromEbaySelection($store, $ebayIds);
            }

            // 2. Update store address if provided
            $addressData = array_filter([
                'address' => $validated['address_line1'] ?? null,
                'address2' => $validated['address_line2'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'zip' => $validated['postal_code'] ?? null,
            ]);

            if (! empty($addressData)) {
                $store->update($addressData);
            }

            // 3. Create default warehouse if none exists
            if ($store->warehouses()->count() === 0) {
                Warehouse::create([
                    'store_id' => $store->id,
                    'name' => 'Main Warehouse',
                    'code' => 'MAIN',
                    'is_default' => true,
                    'address_line1' => $validated['address_line1'] ?? null,
                    'city' => $validated['city'] ?? null,
                    'state' => $validated['state'] ?? null,
                    'postal_code' => $validated['postal_code'] ?? null,
                    'country' => $validated['country'] ?? 'US',
                ]);
            }

            // 4. Create default lead sources
            LeadSource::createDefaultsForStore($store->id);

            // 5. Create default notification layouts
            NotificationLayout::createDefaultLayouts($store->id);

            // 6. Create default notification templates
            NotificationTemplate::createDefaultTemplates($store->id);

            // 7. Create default notification subscriptions (wire templates to activities)
            NotificationSubscription::createDefaultSubscriptions($store->id);

            // 8. Mark onboarding complete
            $store->update(['step' => 2]);
        });

        $message = $skipCategories
            ? 'Store setup complete! You can create categories from the Categories page.'
            : 'Store setup complete! You can now start adding products.';

        return redirect()->route('dashboard')
            ->with('success', $message);
    }

    /**
     * Create local categories from selected eBay parent categories.
     *
     * @param  \App\Models\Store  $store
     * @param  array<int>  $ebayIds
     */
    protected function createCategoriesFromEbaySelection($store, array $ebayIds): void
    {
        $sortOrder = 0;

        foreach ($ebayIds as $ebayId) {
            $parentEbayCategory = EbayCategory::with('children')->find($ebayId);

            if (! $parentEbayCategory) {
                continue;
            }

            // Create a template for this category
            $template = ProductTemplate::create([
                'store_id' => $store->id,
                'name' => $parentEbayCategory->name,
                'description' => "Product template for {$parentEbayCategory->name} items.",
                'is_active' => true,
            ]);

            // Create a parent category for the eBay Level-1 category
            $parentCategory = Category::create([
                'store_id' => $store->id,
                'name' => $parentEbayCategory->name,
                'slug' => Str::slug($parentEbayCategory->name).'-'.Str::random(4),
                'sort_order' => $sortOrder++,
                'ebay_category_id' => $parentEbayCategory->id,
                'template_id' => $template->id,
            ]);

            // Get Level-2 children and create subcategories
            $children = $parentEbayCategory->children()
                ->orderBy('name')
                ->get();

            foreach ($children as $index => $child) {
                Category::create([
                    'store_id' => $store->id,
                    'parent_id' => $parentCategory->id,
                    'name' => $child->name,
                    'slug' => Str::slug($child->name).'-'.Str::random(4),
                    'sort_order' => $index,
                    'ebay_category_id' => $child->id,
                ]);
            }
        }
    }

    /**
     * Fetch children categories for a given eBay category ID.
     * Used for previewing what categories will be created.
     */
    public function getEbayCategoryChildren(int $ebayId): \Illuminate\Http\JsonResponse
    {
        $category = EbayCategory::with('children:id,name,parent_id')->find($ebayId);

        if (! $category) {
            return response()->json(['children' => []]);
        }

        return response()->json([
            'parent' => [
                'id' => $category->id,
                'name' => $category->name,
            ],
            'children' => $category->children->map(fn ($child) => [
                'id' => $child->id,
                'name' => $child->name,
            ]),
        ]);
    }
}
