<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        return Inertia::render('settings/Maintenance', [
            'searchableModels' => $this->getSearchableModels(),
        ]);
    }

    /**
     * Reindex all Scout searchable models.
     */
    public function reindexSearch(): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'Please select a store first.'], 400);
        }

        try {
            Artisan::call('scout:reindex-all', ['--flush' => true]);
            $output = Artisan::output();

            return response()->json([
                'success' => true,
                'message' => 'Search index rebuilt successfully.',
                'output' => $output,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to reindex: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reindex a specific model.
     */
    public function reindexModel(string $model): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'Please select a store first.'], 400);
        }

        $searchableModels = $this->getSearchableModels();
        $modelClass = collect($searchableModels)->firstWhere('key', $model);

        if (! $modelClass) {
            return response()->json(['error' => 'Invalid model specified.'], 400);
        }

        try {
            Artisan::call('scout:flush', ['model' => $modelClass['class']]);
            Artisan::call('scout:import', ['model' => $modelClass['class']]);

            return response()->json([
                'success' => true,
                'message' => "{$modelClass['name']} index rebuilt successfully.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to reindex: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get list of searchable models.
     *
     * @return array<int, array{key: string, name: string, class: string}>
     */
    protected function getSearchableModels(): array
    {
        return [
            ['key' => 'categories', 'name' => 'Categories', 'class' => \App\Models\Category::class],
            ['key' => 'customers', 'name' => 'Customers', 'class' => \App\Models\Customer::class],
            ['key' => 'memos', 'name' => 'Memos', 'class' => \App\Models\Memo::class],
            ['key' => 'orders', 'name' => 'Orders', 'class' => \App\Models\Order::class],
            ['key' => 'products', 'name' => 'Products', 'class' => \App\Models\Product::class],
            ['key' => 'templates', 'name' => 'Product Templates', 'class' => \App\Models\ProductTemplate::class],
            ['key' => 'repairs', 'name' => 'Repairs', 'class' => \App\Models\Repair::class],
            ['key' => 'transactions', 'name' => 'Transactions', 'class' => \App\Models\Transaction::class],
            ['key' => 'transaction_items', 'name' => 'Transaction Items', 'class' => \App\Models\TransactionItem::class],
        ];
    }
}
