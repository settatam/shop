<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\MoveToBucketRequest;
use App\Http\Requests\UpdateTransactionItemRequest;
use App\Mail\ItemSharedWithTeam;
use App\Models\Bucket;
use App\Models\Category;
use App\Models\Image;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Vendor;
use App\Services\ActivityLogFormatter;
use App\Services\AI\TransactionItemResearcher;
use App\Services\Chat\ChatService;
use App\Services\Image\ImageService;
use App\Services\Search\WebPriceSearchService;
use App\Services\SimilarItemFinder;
use App\Services\StoreContext;
use App\Services\Transactions\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class TransactionItemController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected TransactionService $transactionService,
        protected ImageService $imageService,
    ) {}

    public function show(Transaction $transaction, TransactionItem $item): Response
    {
        $this->authorizeItem($transaction, $item);

        $store = $this->storeContext->getCurrentStore();
        $item->load(['category', 'product', 'images', 'notes.user']);
        $transaction->load(['customer', 'user']);

        $preciousMetals = $this->getPreciousMetals();
        $conditions = $this->getConditions();

        // Load template fields if category has a template
        $templateFields = [];
        if ($item->category) {
            $template = $item->category->getEffectiveTemplate();
            if ($template) {
                $template->load('fields.options');
                $templateFields = $template->fields->map(fn ($field) => [
                    'id' => $field->id,
                    'name' => $field->name,
                    'label' => $field->label,
                    'type' => $field->type,
                    'options' => $field->options->map(fn ($opt) => [
                        'value' => $opt->value,
                        'label' => $opt->label,
                    ]),
                ]);
            }
        }

        // Format notes for the frontend
        $notes = $item->notes->map(fn ($note) => [
            'id' => $note->id,
            'content' => $note->content,
            'user' => $note->user ? [
                'id' => $note->user->id,
                'name' => $note->user->name,
            ] : null,
            'created_at' => $note->created_at->toISOString(),
            'updated_at' => $note->updated_at->toISOString(),
        ]);

        // Load buckets for the store
        $buckets = Bucket::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Load team members for sharing (exclude current user)
        $teamMembers = StoreUser::where('store_id', $store->id)
            ->where('status', 'active')
            ->where('user_id', '!=', auth()->id())
            ->with('user')
            ->get()
            ->filter(fn ($storeUser) => $storeUser->user && $storeUser->user->email)
            ->map(fn ($storeUser) => [
                'id' => $storeUser->id,
                'name' => $storeUser->user->name ?? $storeUser->full_name,
                'email' => $storeUser->user->email,
            ])
            ->values();

        // Load vendors for moving to inventory
        $vendors = Vendor::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('transactions/items/Show', [
            'transaction' => $this->formatTransaction($transaction),
            'item' => $this->formatItem($item),
            'preciousMetals' => $preciousMetals,
            'conditions' => $conditions,
            'templateFields' => $templateFields,
            'notes' => $notes,
            'buckets' => $buckets,
            'teamMembers' => $teamMembers,
            'vendors' => $vendors,
            'activityLogs' => Inertia::defer(fn () => app(ActivityLogFormatter::class)->formatForSubject($item)),
        ]);
    }

    public function edit(Transaction $transaction, TransactionItem $item): Response
    {
        $this->authorizeItem($transaction, $item);

        $store = $this->storeContext->getCurrentStore();
        $item->load(['category', 'images']);

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
                'template_id' => $category->template_id,
            ]);

        // Load template fields if category has a template
        $templateFields = [];
        if ($item->category) {
            $template = $item->category->getEffectiveTemplate();
            if ($template) {
                $template->load('fields.options');
                $templateFields = $template->fields->map(fn ($field) => [
                    'id' => $field->id,
                    'name' => $field->name,
                    'label' => $field->label,
                    'type' => $field->type,
                    'placeholder' => $field->placeholder,
                    'help_text' => $field->help_text,
                    'default_value' => $field->default_value,
                    'is_required' => $field->is_required,
                    'group_name' => $field->group_name,
                    'group_position' => $field->group_position ?? 0,
                    'width_class' => $field->width_class ?? 'full',
                    'options' => $field->options->map(fn ($opt) => [
                        'value' => $opt->value,
                        'label' => $opt->label,
                    ]),
                ]);
            }
        }

        return Inertia::render('transactions/items/Edit', [
            'transaction' => $this->formatTransaction($transaction),
            'item' => $this->formatItem($item),
            'categories' => $categories,
            'preciousMetals' => $this->getPreciousMetals(),
            'conditions' => $this->getConditions(),
            'templateFields' => $templateFields,
        ]);
    }

    public function update(UpdateTransactionItemRequest $request, Transaction $transaction, TransactionItem $item): RedirectResponse
    {
        $this->authorizeItem($transaction, $item);

        $this->transactionService->updateItem($item, $request->validated());

        return redirect()->route('web.transactions.items.show', [$transaction, $item])
            ->with('success', 'Item updated successfully.');
    }

    /**
     * Quick update for inline editing (returns JSON).
     */
    public function quickUpdate(Request $request, Transaction $transaction, TransactionItem $item): \Illuminate\Http\JsonResponse
    {
        $this->authorizeItem($transaction, $item);

        $validated = $request->validate([
            'price' => ['nullable', 'numeric', 'min:0'],
            'buy_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->transactionService->updateItem($item, $validated);

        // Refresh the item and transaction to get updated totals
        $item->refresh();
        $transaction->refresh();

        return response()->json([
            'success' => true,
            'item' => [
                'id' => $item->id,
                'price' => $item->price,
                'buy_price' => $item->buy_price,
            ],
            'transaction' => [
                'total_buy_price' => $transaction->total_buy_price,
            ],
        ]);
    }

    public function uploadImages(Request $request, Transaction $transaction, TransactionItem $item): RedirectResponse
    {
        $this->authorizeItem($transaction, $item);

        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'max:10240'],
        ]);

        $store = $this->storeContext->getCurrentStore();
        $existingCount = $item->images()->count();

        $this->imageService->uploadMultiple(
            files: $request->file('images'),
            imageable: $item,
            store: $store,
            folder: 'transaction-items',
            startSortOrder: $existingCount,
            setFirstAsPrimary: $existingCount === 0,
        );

        return redirect()->back()->with('success', 'Images uploaded successfully.');
    }

    public function deleteImage(Transaction $transaction, TransactionItem $item, Image $image): RedirectResponse
    {
        $this->authorizeItem($transaction, $item);

        if ($image->imageable_id !== $item->id || $image->imageable_type !== TransactionItem::class) {
            abort(404);
        }

        $wasPrimary = $image->is_primary;
        $this->imageService->delete($image);

        if ($wasPrimary) {
            $newPrimary = $item->images()->orderBy('sort_order')->first();
            if ($newPrimary) {
                $newPrimary->update(['is_primary' => true]);
            }
        }

        return redirect()->back()->with('success', 'Image deleted successfully.');
    }

    public function moveToInventory(Request $request, Transaction $transaction, TransactionItem $item): RedirectResponse
    {
        $this->authorizeItem($transaction, $item);

        if (! $item->canBeAddedToInventory()) {
            return redirect()->back()->with('error', 'This item cannot be moved to inventory.');
        }

        $validated = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'status' => ['sometimes', 'string', 'in:draft,active'],
        ]);

        $productData = [
            'vendor_id' => $validated['vendor_id'],
            'status' => $validated['status'] ?? 'active',
        ];

        $product = $this->transactionService->moveItemToInventory($item, $productData);

        return redirect()->route('web.transactions.items.show', [$transaction, $item])
            ->with('success', "Item moved to inventory. Product #{$product->id} created.");
    }

    public function moveToBucket(MoveToBucketRequest $request, Transaction $transaction, TransactionItem $item): RedirectResponse
    {
        $this->authorizeItem($transaction, $item);

        if (! $item->canBeAddedToBucket()) {
            return redirect()->back()->with('error', 'This item cannot be moved to a bucket.');
        }

        $store = $this->storeContext->getCurrentStore();
        $bucket = Bucket::where('id', $request->validated('bucket_id'))
            ->where('store_id', $store->id)
            ->firstOrFail();

        $value = (float) $request->validated('value');
        $bucketItem = $item->moveItemToBucket($bucket, $value);

        return redirect()->route('web.transactions.items.show', [$transaction, $item])
            ->with('success', "Item moved to bucket \"{$bucket->name}\".");
    }

    public function review(Transaction $transaction, TransactionItem $item): RedirectResponse
    {
        $this->authorizeItem($transaction, $item);

        if ($item->isReviewed()) {
            return redirect()->back()->with('error', 'This item has already been reviewed.');
        }

        $this->transactionService->reviewItem($item, auth()->id());

        return redirect()->back()->with('success', 'Item marked as reviewed.');
    }

    public function similarItems(Transaction $transaction, TransactionItem $item): JsonResponse
    {
        $this->authorizeItem($transaction, $item);

        $finder = app(SimilarItemFinder::class);
        $similar = $finder->findSimilar($item);

        return response()->json(['items' => $similar]);
    }

    public function generateAiResearch(Transaction $transaction, TransactionItem $item): JsonResponse
    {
        $this->authorizeItem($transaction, $item);

        $researcher = app(TransactionItemResearcher::class);
        $research = $researcher->generateResearch($item);

        return response()->json(['research' => $research]);
    }

    /**
     * Auto-populate template fields using AI to identify the product.
     */
    public function autoPopulateFields(Transaction $transaction, TransactionItem $item): JsonResponse
    {
        $this->authorizeItem($transaction, $item);

        if (! $item->category_id) {
            return response()->json([
                'error' => 'Please select a category first. The category determines which template fields to populate.',
            ], 422);
        }

        $populator = app(\App\Services\AI\TemplateFieldPopulator::class);
        $result = $populator->populateFields($item);

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    public function webPriceSearch(Transaction $transaction, TransactionItem $item): JsonResponse
    {
        $this->authorizeItem($transaction, $item);

        $store = $this->storeContext->getCurrentStore();
        $searchService = app(WebPriceSearchService::class);

        $criteria = [
            'title' => $item->title,
            'category' => $item->category?->name,
            'precious_metal' => $item->precious_metal,
            'attributes' => $item->attributes,
        ];

        $results = $searchService->searchPrices($store->id, $criteria);

        if (! isset($results['error'])) {
            $item->update([
                'web_search_results' => $results,
                'web_search_generated_at' => now(),
            ]);
        }

        return response()->json($results);
    }

    public function shareWithTeam(Request $request, Transaction $transaction, TransactionItem $item): RedirectResponse
    {
        $this->authorizeItem($transaction, $item);

        $validated = $request->validate([
            'team_member_ids' => ['required', 'array', 'min:1'],
            'team_member_ids.*' => ['integer', 'exists:store_users,id'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $store = $this->storeContext->getCurrentStore();
        $sender = $request->user();

        $storeUsers = StoreUser::whereIn('id', $validated['team_member_ids'])
            ->where('store_id', $store->id)
            ->with('user')
            ->get();

        $itemUrl = route('web.transactions.items.show', [$transaction, $item]);
        $sentCount = 0;

        foreach ($storeUsers as $storeUser) {
            if ($storeUser->user && $storeUser->user->email) {
                Mail::to($storeUser->user->email)->queue(
                    new ItemSharedWithTeam($item, $sender, $validated['message'] ?? null, $itemUrl)
                );
                $sentCount++;
            }
        }

        if ($sentCount === 0) {
            return redirect()->back()->with('error', 'No valid email addresses found for selected team members.');
        }

        $message = $sentCount === 1
            ? 'Item shared with 1 team member.'
            : "Item shared with {$sentCount} team members.";

        return redirect()->back()->with('success', $message);
    }

    public function chatStream(Request $request, Transaction $transaction, TransactionItem $item)
    {
        $this->authorizeItem($transaction, $item);

        $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'session_id' => ['nullable', 'string'],
        ]);

        $store = $this->storeContext->getCurrentStore();
        $chatService = app(ChatService::class);

        $session = $chatService->getOrCreateSession(
            $request->input('session_id'),
            $store->id,
            auth()->id()
        );

        return response()->stream(function () use ($chatService, $session, $request, $store, $item) {
            $contextMessage = $this->buildItemContextMessage($item)."\n\nUser question: ".$request->input('message');

            foreach ($chatService->streamMessage($session, $contextMessage, $store) as $event) {
                echo 'data: '.json_encode($event)."\n\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    protected function buildItemContextMessage(TransactionItem $item): string
    {
        $item->load(['category', 'transaction.customer']);

        $context = "I'm looking at a transaction item with the following details:\n";
        $context .= "- Title: {$item->title}\n";

        if ($item->description) {
            $context .= "- Description: {$item->description}\n";
        }
        if ($item->category) {
            $context .= "- Category: {$item->category->name}\n";
        }
        if ($item->precious_metal) {
            $context .= "- Metal: {$item->precious_metal}\n";
        }
        if ($item->dwt) {
            $context .= "- DWT: {$item->dwt}\n";
        }
        if ($item->condition) {
            $context .= "- Condition: {$item->condition}\n";
        }
        if ($item->price) {
            $context .= "- Estimated Value: \${$item->price}\n";
        }
        if ($item->buy_price) {
            $context .= "- Buy Price: \${$item->buy_price}\n";
        }

        if ($item->ai_research) {
            $context .= "\nAI Research Report:\n".json_encode($item->ai_research, JSON_PRETTY_PRINT)."\n";
        }

        return $context;
    }

    protected function authorizeItem(Transaction $transaction, TransactionItem $item): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $transaction->store_id !== $store->id) {
            abort(404);
        }

        if ($item->transaction_id !== $transaction->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatTransaction(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'transaction_number' => $transaction->transaction_number,
            'status' => $transaction->status,
            'type' => $transaction->type,
            'final_offer' => $transaction->final_offer,
            'total_buy_price' => $transaction->total_buy_price,
            'created_at' => $transaction->created_at->toISOString(),
            'customer' => $transaction->customer ? [
                'id' => $transaction->customer->id,
                'full_name' => $transaction->customer->full_name,
                'email' => $transaction->customer->email,
            ] : null,
            'user' => $transaction->user ? [
                'id' => $transaction->user->id,
                'name' => $transaction->user->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatItem(TransactionItem $item): array
    {
        return [
            'id' => $item->id,
            'transaction_id' => $item->transaction_id,
            'title' => $item->title,
            'description' => $item->description,
            'sku' => $item->sku,
            'category_id' => $item->category_id,
            'category' => $item->category ? [
                'id' => $item->category->id,
                'name' => $item->category->name,
                'full_path' => $item->category->full_path ?? $item->category->name,
            ] : null,
            'quantity' => $item->quantity,
            'price' => $item->price,
            'buy_price' => $item->buy_price,
            'dwt' => $item->dwt,
            'precious_metal' => $item->precious_metal,
            'condition' => $item->condition,
            'attributes' => $item->attributes ?? [],
            'is_added_to_inventory' => $item->is_added_to_inventory,
            'is_added_to_bucket' => $item->is_added_to_bucket,
            'date_added_to_inventory' => $item->date_added_to_inventory?->toISOString(),
            'product_id' => $item->product_id,
            'bucket_id' => $item->bucket_id,
            'ai_research' => $item->ai_research,
            'ai_research_generated_at' => $item->ai_research_generated_at?->toISOString(),
            'web_search_results' => $item->web_search_results,
            'web_search_generated_at' => $item->web_search_generated_at?->toISOString(),
            'images' => $item->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url,
                'thumbnail_url' => $image->thumbnail_url,
                'alt_text' => $image->alt_text,
                'is_primary' => $image->is_primary,
            ]),
            'created_at' => $item->created_at->toISOString(),
            'updated_at' => $item->updated_at->toISOString(),
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
}
