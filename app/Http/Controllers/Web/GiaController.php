<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Gia\GiaApiService;
use App\Services\Gia\GiaProductService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GiaController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected GiaProductService $giaProductService,
        protected GiaApiService $giaApiService,
    ) {}

    /**
     * Display the GIA product entry page.
     */
    public function index(): Response
    {
        $store = $this->storeContext->getCurrentStore();

        // Get GIA-eligible categories:
        // 1. Diamond under Loose Stones (single diamond)
        // 2. Diamond Studs GIA Certified (pair of diamonds)
        $categories = Category::where('store_id', $store->id)
            ->where(function ($query) {
                $query->where(function ($q) {
                    // Diamond under Loose Stones
                    $q->where('name', 'Diamond')
                        ->whereHas('parent', function ($parent) {
                            $parent->where('name', 'Loose Stones');
                        });
                })->orWhere('name', 'Diamond Studs GIA Certified');
            })
            ->with(['template', 'parent'])
            ->get()
            ->map(function ($category) {
                $template = $category->getEffectiveTemplate();

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'full_path' => $category->full_path,
                    'is_stud' => $category->name === 'Diamond Studs GIA Certified',
                    'template_name' => $template?->name,
                ];
            });

        return Inertia::render('gia/Index', [
            'categories' => $categories,
            'isConfigured' => $this->giaApiService->isConfigured(),
        ]);
    }

    /**
     * Fetch GIA data and create/update product.
     */
    public function getData(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'No store selected'], 400);
        }

        $validated = $request->validate([
            'reference_number' => ['required', 'string', 'max:50'],
            'gia2' => ['nullable', 'string', 'max:50'],
            'product_type_id' => ['required', 'exists:categories,id'],
        ]);

        // Verify category belongs to store
        $category = Category::where('store_id', $store->id)
            ->findOrFail($validated['product_type_id']);

        $result = $this->giaProductService->createFromGia(
            $validated['reference_number'],
            $validated['gia2'] ?? null,
            $category,
            $store,
            auth()->id(),
        );

        if ($result['errors'] && ! $result['product']) {
            return response()->json([
                'error' => true,
                'message' => $result['errors'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'product' => $result['product'],
            'warnings' => $result['errors'],
            'redirect_url' => route('products.edit', $result['product']),
        ]);
    }

    /**
     * Lookup GIA data without creating a product.
     */
    public function lookup(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'No store selected'], 400);
        }

        $validated = $request->validate([
            'reference_number' => ['required', 'string', 'max:50'],
        ]);

        $result = $this->giaApiService->getReport($validated['reference_number']);

        if ($result['errors']) {
            return response()->json([
                'error' => true,
                'message' => $result['errors'][0]['message'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
        ]);
    }
}
