<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\QuickEvaluation;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Warehouse;
use App\Services\AI\TransactionItemResearcher;
use App\Services\Image\ImageService;
use App\Services\SimilarItemFinder;
use App\Services\StoreContext;
use App\Services\Transactions\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuickEvaluationController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected TransactionService $transactionService,
        protected ImageService $imageService,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        // Get categories for the dropdown
        $categories = Category::where('store_id', $store->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'full_path' => $category->full_path,
                'parent_id' => $category->parent_id,
                'level' => $category->level,
            ]);

        // Get store users for employee dropdown
        $storeUsers = $store->storeUsers()
            ->with(['user', 'role'])
            ->get()
            ->filter(fn ($storeUser) => $storeUser->is_owner || $storeUser->hasPermission('orders.create'))
            ->map(fn ($storeUser) => [
                'id' => $storeUser->id,
                'name' => $storeUser->user?->name ?? $storeUser->name ?? 'Unknown',
            ])
            ->values();

        // Get the current user's store user ID
        $currentStoreUserId = auth()->user()?->currentStoreUser()?->id;

        // Get warehouses for the warehouse dropdown
        $warehouses = Warehouse::where('store_id', $store->id)
            ->orderBy('name')
            ->get()
            ->map(fn ($warehouse) => [
                'value' => $warehouse->id,
                'label' => $warehouse->name,
            ]);

        // Get the current user's default warehouse ID
        $currentStoreUser = auth()->user()?->currentStoreUser();
        $defaultWarehouseId = $currentStoreUser?->default_warehouse_id;

        return Inertia::render('transactions/QuickEvaluation', [
            'categories' => $categories,
            'preciousMetals' => $this->getPreciousMetals(),
            'conditions' => $this->getConditions(),
            'paymentMethods' => $this->getPaymentMethods(),
            'storeUsers' => $storeUsers,
            'currentStoreUserId' => $currentStoreUserId,
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $defaultWarehouseId,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'Please select a store first.'], 400);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'category_id' => 'nullable|exists:categories,id',
            'precious_metal' => 'nullable|string|max:50',
            'condition' => 'nullable|string|max:50',
            'estimated_weight' => 'nullable|numeric|min:0',
            'estimated_value' => 'nullable|numeric|min:0',
        ]);

        $evaluation = QuickEvaluation::create([
            'store_id' => $store->id,
            'user_id' => auth()->id(),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'precious_metal' => $validated['precious_metal'] ?? null,
            'condition' => $validated['condition'] ?? null,
            'estimated_weight' => $validated['estimated_weight'] ?? null,
            'estimated_value' => $validated['estimated_value'] ?? null,
            'status' => QuickEvaluation::STATUS_DRAFT,
        ]);

        return response()->json([
            'evaluation' => $this->formatEvaluation($evaluation),
        ]);
    }

    public function update(Request $request, QuickEvaluation $evaluation): JsonResponse
    {
        $this->authorizeEvaluation($evaluation);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'category_id' => 'nullable|exists:categories,id',
            'precious_metal' => 'nullable|string|max:50',
            'condition' => 'nullable|string|max:50',
            'estimated_weight' => 'nullable|numeric|min:0',
            'estimated_value' => 'nullable|numeric|min:0',
        ]);

        $evaluation->update($validated);

        return response()->json([
            'evaluation' => $this->formatEvaluation($evaluation),
        ]);
    }

    public function searchSimilarItems(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'Please select a store first.'], 400);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'precious_metal' => 'nullable|string|max:50',
            'condition' => 'nullable|string|max:50',
        ]);

        $finder = app(SimilarItemFinder::class);
        $similarItems = $finder->findSimilarTransactionItems($validated, $store->id, 10);

        return response()->json([
            'items' => $similarItems,
        ]);
    }

    public function generateAiResearch(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'Please select a store first.'], 400);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'category_id' => 'nullable|exists:categories,id',
            'precious_metal' => 'nullable|string|max:50',
            'condition' => 'nullable|string|max:50',
            'estimated_weight' => 'nullable|numeric|min:0',
            'image_urls' => 'nullable|array',
            'image_urls.*' => 'string|url',
        ]);

        $researcher = app(TransactionItemResearcher::class);
        $research = $researcher->generateResearchFromData(
            storeId: $store->id,
            title: $validated['title'],
            description: $validated['description'] ?? null,
            categoryId: $validated['category_id'] ?? null,
            preciousMetal: $validated['precious_metal'] ?? null,
            condition: $validated['condition'] ?? null,
            weight: $validated['estimated_weight'] ?? null,
            imageUrls: $validated['image_urls'] ?? [],
        );

        return response()->json([
            'research' => $research,
        ]);
    }

    public function uploadImages(Request $request, QuickEvaluation $evaluation): JsonResponse
    {
        $this->authorizeEvaluation($evaluation);

        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'max:10240'],
        ]);

        $store = $this->storeContext->getCurrentStore();
        $existingCount = $evaluation->images()->count();

        $this->imageService->uploadMultiple(
            files: $request->file('images'),
            imageable: $evaluation,
            store: $store,
            folder: 'quick-evaluations',
            startSortOrder: $existingCount,
            setFirstAsPrimary: $existingCount === 0,
        );

        $evaluation->load('images');

        return response()->json([
            'images' => $evaluation->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url,
                'thumbnail_url' => $image->thumbnail_url,
                'is_primary' => $image->is_primary,
            ]),
        ]);
    }

    public function convertToTransaction(Request $request, QuickEvaluation $evaluation): RedirectResponse
    {
        $this->authorizeEvaluation($evaluation);
        $store = $this->storeContext->getCurrentStore();

        if ($evaluation->isConverted()) {
            return redirect()->route('web.transactions.show', $evaluation->transaction_id)
                ->with('error', 'This evaluation has already been converted.');
        }

        $validated = $request->validate([
            'store_user_id' => 'required|exists:store_users,id',
            'customer_id' => 'nullable|exists:customers,id',
            'customer' => 'nullable|array',
            'customer.first_name' => 'required_with:customer|string|max:255',
            'customer.last_name' => 'required_with:customer|string|max:255',
            'customer.email' => 'nullable|email|max:255',
            'customer.phone_number' => 'nullable|string|max:50',
            'customer.address' => 'nullable|string|max:255',
            'customer.city' => 'nullable|string|max:255',
            'customer.state_id' => 'nullable|integer',
            'customer.zip' => 'nullable|string|max:20',
            'buy_price' => 'required|numeric|min:0',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|string',
            'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.details' => 'nullable|array',
            'customer_notes' => 'nullable|string|max:2000',
            'internal_notes' => 'nullable|string|max:2000',
        ]);

        // Build item data from evaluation
        $itemData = [
            'title' => $evaluation->title,
            'description' => $evaluation->description,
            'category_id' => $evaluation->category_id,
            'precious_metal' => $evaluation->precious_metal,
            'condition' => $evaluation->condition,
            'dwt' => $evaluation->estimated_weight,
            'price' => $evaluation->estimated_value,
            'buy_price' => $validated['buy_price'],
            'ai_research' => $evaluation->ai_research,
            'ai_research_generated_at' => $evaluation->ai_research_generated_at,
        ];

        // Create transaction using the service
        $transactionData = [
            'store_id' => $store->id,
            'store_user_id' => $validated['store_user_id'],
            'customer_id' => $validated['customer_id'],
            'customer' => $validated['customer'] ?? null,
            'items' => [$itemData],
            'offer_amount' => $validated['buy_price'],
            'warehouse_id' => $validated['warehouse_id'],
            'payments' => $validated['payments'],
            'customer_notes' => $validated['customer_notes'],
            'internal_notes' => $validated['internal_notes'],
        ];

        $result = $this->transactionService->createFromWizard($transactionData);
        $transaction = $result['transaction'];

        // Copy images from evaluation to transaction item
        $transactionItem = $transaction->items->first();
        if ($transactionItem && $evaluation->images->isNotEmpty()) {
            foreach ($evaluation->images as $image) {
                $transactionItem->images()->create([
                    'store_id' => $store->id,
                    'url' => $image->url,
                    'thumbnail_url' => $image->thumbnail_url,
                    'alt_text' => $image->alt_text,
                    'sort_order' => $image->sort_order,
                    'is_primary' => $image->is_primary,
                ]);
            }
        }

        // Mark evaluation as converted
        $evaluation->markAsConverted($transaction);

        return redirect()->route('web.transactions.show', $transaction)
            ->with('success', 'Transaction created successfully from evaluation.');
    }

    public function destroy(QuickEvaluation $evaluation): RedirectResponse
    {
        $this->authorizeEvaluation($evaluation);

        if ($evaluation->isConverted()) {
            return redirect()->back()
                ->with('error', 'Cannot delete a converted evaluation.');
        }

        $evaluation->markAsDiscarded();

        return redirect()->route('web.transactions.index')
            ->with('success', 'Evaluation discarded.');
    }

    protected function authorizeEvaluation(QuickEvaluation $evaluation): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $evaluation->store_id !== $store->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatEvaluation(QuickEvaluation $evaluation): array
    {
        return [
            'id' => $evaluation->id,
            'title' => $evaluation->title,
            'description' => $evaluation->description,
            'category_id' => $evaluation->category_id,
            'category' => $evaluation->category ? [
                'id' => $evaluation->category->id,
                'name' => $evaluation->category->name,
                'full_path' => $evaluation->category->full_path,
            ] : null,
            'precious_metal' => $evaluation->precious_metal,
            'condition' => $evaluation->condition,
            'estimated_weight' => $evaluation->estimated_weight,
            'estimated_value' => $evaluation->estimated_value,
            'similar_items' => $evaluation->similar_items,
            'ai_research' => $evaluation->ai_research,
            'ai_research_generated_at' => $evaluation->ai_research_generated_at?->toISOString(),
            'status' => $evaluation->status,
            'images' => $evaluation->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url,
                'thumbnail_url' => $image->thumbnail_url,
                'is_primary' => $image->is_primary,
            ]),
            'created_at' => $evaluation->created_at->toISOString(),
            'updated_at' => $evaluation->updated_at->toISOString(),
        ];
    }

    /**
     * @return array<array<string, string>>
     */
    protected function getPreciousMetals(): array
    {
        return [
            ['value' => TransactionItem::METAL_GOLD_10K, 'label' => '10K Gold'],
            ['value' => TransactionItem::METAL_GOLD_14K, 'label' => '14K Gold'],
            ['value' => TransactionItem::METAL_GOLD_18K, 'label' => '18K Gold'],
            ['value' => TransactionItem::METAL_GOLD_22K, 'label' => '22K Gold'],
            ['value' => TransactionItem::METAL_GOLD_24K, 'label' => '24K Gold'],
            ['value' => TransactionItem::METAL_SILVER, 'label' => 'Silver'],
            ['value' => TransactionItem::METAL_PLATINUM, 'label' => 'Platinum'],
            ['value' => TransactionItem::METAL_PALLADIUM, 'label' => 'Palladium'],
        ];
    }

    /**
     * @return array<array<string, string>>
     */
    protected function getConditions(): array
    {
        return [
            ['value' => TransactionItem::CONDITION_NEW, 'label' => 'New'],
            ['value' => TransactionItem::CONDITION_LIKE_NEW, 'label' => 'Like New'],
            ['value' => TransactionItem::CONDITION_USED, 'label' => 'Used'],
            ['value' => TransactionItem::CONDITION_DAMAGED, 'label' => 'Damaged'],
        ];
    }

    /**
     * @return array<array<string, string>>
     */
    protected function getPaymentMethods(): array
    {
        return [
            ['value' => Transaction::PAYMENT_CASH, 'label' => 'Cash'],
            ['value' => Transaction::PAYMENT_CHECK, 'label' => 'Check'],
            ['value' => Transaction::PAYMENT_STORE_CREDIT, 'label' => 'Store Credit'],
            ['value' => Transaction::PAYMENT_ACH, 'label' => 'ACH Transfer'],
            ['value' => Transaction::PAYMENT_PAYPAL, 'label' => 'PayPal'],
            ['value' => Transaction::PAYMENT_VENMO, 'label' => 'Venmo'],
            ['value' => Transaction::PAYMENT_WIRE_TRANSFER, 'label' => 'Wire Transfer'],
        ];
    }
}
