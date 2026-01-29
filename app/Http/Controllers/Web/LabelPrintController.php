<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\LabelTemplate;
use App\Models\Transaction;
use App\Models\Variant;
use App\Services\Shipping\ShippingLabelService;
use App\Services\StoreContext;
use App\Services\ZplGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LabelPrintController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected ZplGeneratorService $zplGenerator,
        protected ShippingLabelService $shippingLabelService,
    ) {}

    public function products(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $templates = LabelTemplate::where('store_id', $store->id)
            ->where('type', LabelTemplate::TYPE_PRODUCT)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn ($template) => [
                'id' => $template->id,
                'name' => $template->name,
                'is_default' => $template->is_default,
                'canvas_width' => $template->canvas_width,
                'canvas_height' => $template->canvas_height,
            ]);

        // Get variants with products for printing
        $variantIds = $request->query('variants', []);
        $variants = collect();

        if (! empty($variantIds)) {
            $variants = Variant::with(['product.category', 'product.brand'])
                ->whereHas('product', fn ($q) => $q->where('store_id', $store->id))
                ->whereIn('id', $variantIds)
                ->get()
                ->map(fn ($variant) => $this->formatVariantForLabel($variant));
        }

        return Inertia::render('labels/PrintProducts', [
            'templates' => $templates,
            'variants' => $variants,
            'selectedVariantIds' => $variantIds,
        ]);
    }

    public function generateProductZpl(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'No store selected'], 403);
        }

        $validated = $request->validate([
            'template_id' => 'required|exists:label_templates,id',
            'variant_ids' => 'required|array|min:1',
            'variant_ids.*' => 'integer|exists:variants,id',
            'quantity' => 'integer|min:1|max:100',
        ]);

        $template = LabelTemplate::with('elements')
            ->where('store_id', $store->id)
            ->where('type', LabelTemplate::TYPE_PRODUCT)
            ->findOrFail($validated['template_id']);

        $variants = Variant::with(['product.category', 'product.brand'])
            ->whereHas('product', fn ($q) => $q->where('store_id', $store->id))
            ->whereIn('id', $validated['variant_ids'])
            ->get();

        $quantity = $validated['quantity'] ?? 1;
        $items = [];

        foreach ($variants as $variant) {
            $data = $this->formatVariantForLabel($variant);
            for ($i = 0; $i < $quantity; $i++) {
                $items[] = $data;
            }
        }

        $zpl = $this->zplGenerator->generateBatch($template, $items);

        return response()->json([
            'zpl' => $zpl,
            'count' => count($items),
        ]);
    }

    public function transactions(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $templates = LabelTemplate::where('store_id', $store->id)
            ->where('type', LabelTemplate::TYPE_TRANSACTION)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn ($template) => [
                'id' => $template->id,
                'name' => $template->name,
                'is_default' => $template->is_default,
                'canvas_width' => $template->canvas_width,
                'canvas_height' => $template->canvas_height,
            ]);

        // Get transactions for printing
        $transactionIds = $request->query('transactions', []);
        $transactions = collect();

        if (! empty($transactionIds)) {
            $transactions = Transaction::with('customer')
                ->where('store_id', $store->id)
                ->whereIn('id', $transactionIds)
                ->get()
                ->map(fn ($transaction) => $this->formatTransactionForLabel($transaction));
        }

        return Inertia::render('labels/PrintTransactions', [
            'templates' => $templates,
            'transactions' => $transactions,
            'selectedTransactionIds' => $transactionIds,
        ]);
    }

    public function generateTransactionZpl(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'No store selected'], 403);
        }

        $validated = $request->validate([
            'template_id' => 'required|exists:label_templates,id',
            'transaction_ids' => 'required|array|min:1',
            'transaction_ids.*' => 'integer|exists:transactions,id',
            'quantity' => 'integer|min:1|max:100',
        ]);

        $template = LabelTemplate::with('elements')
            ->where('store_id', $store->id)
            ->where('type', LabelTemplate::TYPE_TRANSACTION)
            ->findOrFail($validated['template_id']);

        $transactions = Transaction::with('customer')
            ->where('store_id', $store->id)
            ->whereIn('id', $validated['transaction_ids'])
            ->get();

        $quantity = $validated['quantity'] ?? 1;
        $items = [];

        foreach ($transactions as $transaction) {
            $data = $this->formatTransactionForLabel($transaction);
            for ($i = 0; $i < $quantity; $i++) {
                $items[] = $data;
            }
        }

        $zpl = $this->zplGenerator->generateBatch($template, $items);

        return response()->json([
            'zpl' => $zpl,
            'count' => count($items),
        ]);
    }

    /**
     * Format variant data for label printing.
     *
     * @return array<string, array<string, string|null>>
     */
    protected function formatVariantForLabel(Variant $variant): array
    {
        $product = $variant->product;

        // Build options title
        $options = collect([
            $variant->option1 ? ($product->option1_name.': '.$variant->option1) : null,
            $variant->option2 ? ($product->option2_name.': '.$variant->option2) : null,
            $variant->option3 ? ($product->option3_name.': '.$variant->option3) : null,
        ])->filter()->implode(' / ');

        return [
            'product' => [
                'title' => $product->title,
                'weight' => $product->weight ? $product->weight.'g' : null,
                'upc' => $product->upc,
                'ean' => $product->ean,
                'jan' => $product->jan,
                'isbn' => $product->isbn,
                'mpn' => $product->mpn,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'metal_type' => $product->metal_type,
                'metal_purity' => $product->metal_purity,
                'metal_weight_grams' => $product->metal_weight_grams,
                'jewelry_type' => $product->jewelry_type,
                'ring_size' => $product->ring_size,
                'chain_length_inches' => $product->chain_length_inches,
                'main_stone_type' => $product->main_stone_type,
                'total_carat_weight' => $product->total_carat_weight,
            ],
            'variant' => [
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'price' => $variant->price ? '$'.number_format($variant->price / 100, 2) : null,
                'cost' => $variant->cost ? '$'.number_format($variant->cost / 100, 2) : null,
                'quantity' => (string) ($variant->quantity ?? 0),
                'option1' => $variant->option1 ? ($product->option1_name.': '.$variant->option1) : null,
                'option2' => $variant->option2 ? ($product->option2_name.': '.$variant->option2) : null,
                'option3' => $variant->option3 ? ($product->option3_name.': '.$variant->option3) : null,
                'options_title' => $options ?: null,
            ],
        ];
    }

    public function shippingLabels(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $type = $request->query('type', 'outbound');
        $transactionIds = $request->query('transactions', []);
        $transactions = collect();

        if (! empty($transactionIds)) {
            $transactions = Transaction::with(['customer', 'outboundLabel', 'returnLabel'])
                ->where('store_id', $store->id)
                ->whereIn('id', $transactionIds)
                ->get()
                ->map(fn (Transaction $transaction) => [
                    'id' => $transaction->id,
                    'transaction_number' => $transaction->transaction_number,
                    'type' => $transaction->type,
                    'customer' => $transaction->customer ? [
                        'full_name' => $transaction->customer->full_name,
                        'address' => $transaction->customer->address,
                        'city' => $transaction->customer->city,
                        'state' => $transaction->customer->state,
                        'zip' => $transaction->customer->zip,
                    ] : null,
                    'has_outbound_label' => $transaction->outboundLabel !== null,
                    'has_return_label' => $transaction->returnLabel !== null,
                    'outbound_tracking' => $transaction->outboundLabel?->tracking_number,
                    'return_tracking' => $transaction->returnLabel?->tracking_number,
                ]);
        }

        return Inertia::render('labels/PrintShippingLabels', [
            'transactions' => $transactions,
            'selectedTransactionIds' => $transactionIds,
            'labelType' => $type,
            'isConfigured' => $this->shippingLabelService->isConfigured(),
            'serviceTypes' => ShippingLabelService::getServiceTypes(),
            'packagingTypes' => ShippingLabelService::getPackagingTypes(),
        ]);
    }

    public function createBulkShippingLabels(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        if (! $this->shippingLabelService->isConfigured()) {
            return back()->with('error', 'FedEx shipping is not configured. Please add FedEx credentials in settings.');
        }

        $validated = $request->validate([
            'transaction_ids' => 'required|array|min:1',
            'transaction_ids.*' => 'integer|exists:transactions,id',
            'type' => 'required|string|in:outbound,return',
            'service_type' => 'nullable|string',
            'packaging_type' => 'nullable|string',
        ]);

        $transactions = Transaction::with('customer')
            ->where('store_id', $store->id)
            ->whereIn('id', $validated['transaction_ids'])
            ->get();

        $successCount = 0;
        $errors = [];
        $options = array_filter([
            'service_type' => $validated['service_type'] ?? null,
            'packaging_type' => $validated['packaging_type'] ?? null,
        ]);

        foreach ($transactions as $transaction) {
            try {
                if ($validated['type'] === 'outbound') {
                    $label = $this->shippingLabelService->createOutboundLabel($transaction, $options);
                    $transaction->update([
                        'outbound_tracking_number' => $label->tracking_number,
                        'outbound_carrier' => $label->carrier,
                    ]);
                } else {
                    $label = $this->shippingLabelService->createReturnLabel($transaction, $options);
                    $transaction->update([
                        'return_tracking_number' => $label->tracking_number,
                        'return_carrier' => $label->carrier,
                    ]);
                }
                $successCount++;
            } catch (\Exception $e) {
                $errors[] = "{$transaction->transaction_number}: {$e->getMessage()}";
            }
        }

        $message = "{$successCount} label(s) created successfully.";
        if (! empty($errors)) {
            $message .= ' Errors: '.implode('; ', $errors);
        }

        return back()->with($errors ? 'warning' : 'success', $message);
    }

    /**
     * Format transaction data for label printing.
     *
     * @return array<string, array<string, string|null>>
     */
    protected function formatTransactionForLabel(Transaction $transaction): array
    {
        return [
            'transaction' => [
                'transaction_number' => $transaction->transaction_number,
                'type' => $transaction->type === 'in_house' ? 'In-House Buy' : 'Mail-In Buy',
                'status' => ucfirst(str_replace('_', ' ', $transaction->status)),
                'bin_location' => $transaction->bin_location,
                'final_offer' => $transaction->final_offer ? '$'.number_format($transaction->final_offer / 100, 2) : null,
                'estimated_value' => $transaction->estimated_value ? '$'.number_format($transaction->estimated_value / 100, 2) : null,
                'preliminary_offer' => $transaction->preliminary_offer ? '$'.number_format($transaction->preliminary_offer / 100, 2) : null,
                'created_at' => $transaction->created_at?->format('M j, Y'),
                'offer_accepted_at' => $transaction->offer_accepted_at?->format('M j, Y'),
            ],
            'customer' => [
                'full_name' => $transaction->customer?->full_name,
                'phone' => $transaction->customer?->phone,
                'email' => $transaction->customer?->email,
            ],
        ];
    }
}
