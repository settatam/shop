<?php

namespace App\Services\Memos;

use App\Models\Memo;
use App\Models\MemoItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreUser;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Invoices\InvoiceService;
use App\Services\Orders\OrderCreationService;
use App\Services\StoreContext;
use App\Services\TaxService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MemoService
{
    public function __construct(
        protected StoreContext $storeContext,
        protected OrderCreationService $orderCreationService,
        protected TaxService $taxService,
        protected InvoiceService $invoiceService,
    ) {}

    public function create(array $data): Memo
    {
        return DB::transaction(function () use ($data) {
            $store = $this->storeContext->getCurrentStore();
            $warehouseId = $data['warehouse_id'] ?? $this->storeContext->getDefaultWarehouseId();
            $warehouse = $warehouseId ? Warehouse::find($warehouseId) : null;
            $defaultTaxRate = $store ? $this->taxService->getTaxRate($warehouse, $store) : 0;

            $memo = Memo::create([
                'store_id' => $store?->id ?? $data['store_id'],
                'warehouse_id' => $warehouseId,
                'vendor_id' => $data['vendor_id'] ?? null,
                'user_id' => $data['user_id'] ?? auth()->id(),
                'tenure' => $data['tenure'] ?? Memo::TENURE_30_DAYS,
                'tax_rate' => $data['tax_rate'] ?? $defaultTaxRate,
                'charge_taxes' => $data['charge_taxes'] ?? true,
                'shipping_cost' => $data['shipping_cost'] ?? 0,
                'description' => $data['description'] ?? null,
            ]);

            if (! empty($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $this->addItem($memo, $itemData);
                }
                $memo->calculateTotals();
            }

            return $memo->fresh(['vendor', 'user', 'items']);
        });
    }

    /**
     * Create a memo from the wizard form data.
     *
     * @param  array<string, mixed>  $data
     */
    public function createFromWizard(array $data): Memo
    {
        return DB::transaction(function () use ($data) {
            $store = $this->storeContext->getCurrentStore();
            $storeId = $store?->id ?? $data['store_id'];
            $warehouseId = $data['warehouse_id'] ?? $this->storeContext->getDefaultWarehouseId();
            $warehouse = $warehouseId ? Warehouse::find($warehouseId) : null;
            $defaultTaxRate = $store ? $this->taxService->getTaxRate($warehouse, $store) : 0;

            // Get or create vendor
            $vendorId = $data['vendor_id'] ?? null;
            if (! $vendorId && ! empty($data['vendor'])) {
                $vendor = Vendor::create([
                    'store_id' => $storeId,
                    'name' => $data['vendor']['name'],
                    'company_name' => $data['vendor']['company_name'] ?? null,
                    'email' => $data['vendor']['email'] ?? null,
                    'phone' => $data['vendor']['phone'] ?? null,
                ]);
                $vendorId = $vendor->id;
            }

            // Get the user ID from store_user
            $storeUser = StoreUser::find($data['store_user_id']);
            $userId = $storeUser?->user_id ?? auth()->id();

            // Create the memo
            $memo = Memo::create([
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'vendor_id' => $vendorId,
                'user_id' => $userId,
                'tenure' => $data['tenure'] ?? Memo::TENURE_30_DAYS,
                'tax_rate' => $data['tax_rate'] ?? $defaultTaxRate,
                'charge_taxes' => $data['charge_taxes'] ?? false,
                'description' => $data['description'] ?? null,
                'status' => Memo::STATUS_PENDING,
            ]);

            // Add items and mark products as out of stock
            foreach ($data['items'] as $itemData) {
                $product = Product::with('variants')->find($itemData['product_id']);

                if (! $product) {
                    continue;
                }

                $variant = $product->variants->first();

                // Cost priority: wholesale_price > cost
                $effectiveCost = $variant?->effective_cost ?? 0;

                // Create memo item
                $memo->items()->create([
                    'product_id' => $product->id,
                    'category_id' => $product->category_id,
                    'sku' => $variant?->sku ?? $product->sku,
                    'title' => $itemData['title'] ?? $product->title,
                    'description' => $itemData['description'] ?? $product->description,
                    'price' => $itemData['price'],
                    'cost' => $effectiveCost,
                    'tenor' => $itemData['tenor'] ?? $data['tenure'] ?? Memo::TENURE_30_DAYS,
                    'charge_taxes' => $data['charge_taxes'] ?? false,
                ]);

                // Mark product as out of stock (on memo)
                MemoItem::markProductOnMemo($product);
            }

            // Calculate totals
            $memo->calculateTotals();

            return $memo->fresh(['vendor', 'user', 'items.product']);
        });
    }

    public function addItem(Memo $memo, array $data): MemoItem
    {
        if (! empty($data['product_id'])) {
            // Check if product is already in an active memo
            $existingMemoItem = MemoItem::where('product_id', $data['product_id'])
                ->where('is_returned', false)
                ->whereHas('memo', fn ($q) => $q->whereNotIn('status', [Memo::STATUS_ARCHIVED, Memo::STATUS_PAYMENT_RECEIVED]))
                ->exists();

            if ($existingMemoItem) {
                throw new InvalidArgumentException('Product is already in a memo.');
            }
        }

        // Get effective cost from product variant if not provided
        $cost = $data['cost'] ?? 0;
        if (! isset($data['cost']) && ! empty($data['product_id'])) {
            $product = Product::with('variants')->find($data['product_id']);
            $variant = $product?->variants->first();
            $cost = $variant?->effective_cost ?? 0;
        }

        $item = $memo->items()->create([
            'product_id' => $data['product_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'sku' => $data['sku'] ?? null,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'price' => $data['price'] ?? 0,
            'cost' => $cost,
            'tenor' => $data['tenor'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'charge_taxes' => $data['charge_taxes'] ?? true,
        ]);

        $memo->calculateTotals();

        return $item;
    }

    public function removeItem(MemoItem $item): bool
    {
        return DB::transaction(function () use ($item) {
            $memo = $item->memo;
            $result = $item->delete();
            $memo->calculateTotals();

            return $result;
        });
    }

    public function returnItem(MemoItem $item): MemoItem
    {
        if ($item->isReturned()) {
            throw new InvalidArgumentException('Item has already been returned.');
        }

        return $item->returnItem();
    }

    public function sendToVendor(Memo $memo): Memo
    {
        if (! $memo->canBeSentToVendor()) {
            throw new InvalidArgumentException('Memo cannot be sent to vendor in its current state.');
        }

        return $memo->sendToVendor();
    }

    public function markVendorReceived(Memo $memo): Memo
    {
        if (! $memo->canBeMarkedAsReceived()) {
            throw new InvalidArgumentException('Memo cannot be marked as received in its current state.');
        }

        return $memo->markVendorReceived();
    }

    public function markVendorReturned(Memo $memo): Memo
    {
        if (! $memo->canBeMarkedAsReturned()) {
            throw new InvalidArgumentException('Memo cannot be marked as returned in its current state.');
        }

        return DB::transaction(function () use ($memo) {
            // Return all non-returned items to stock
            $memo->items()->where('is_returned', false)->each(function (MemoItem $item) {
                $item->returnToStock();
            });

            return $memo->markVendorReturned();
        });
    }

    public function cancel(Memo $memo): Memo
    {
        if (! $memo->canBeCancelled()) {
            throw new InvalidArgumentException('Memo cannot be cancelled in its current state.');
        }

        return $memo->cancel();
    }

    public function createSaleOrder(Memo $memo, array $input = []): Order
    {
        if (! $memo->vendor_id) {
            throw new InvalidArgumentException('Memo must have a vendor to create a sale order.');
        }

        return DB::transaction(function () use ($memo) {
            $items = $memo->items()
                ->where('is_returned', false)
                ->get()
                ->map(fn (MemoItem $item) => [
                    'title' => $item->title ?? 'Memo Item',
                    'sku' => $item->sku,
                    'quantity' => 1,
                    'price' => $item->price,        // Selling price
                    'cost' => $item->cost,          // Cost for profit calculation
                    'product_id' => $item->product_id,
                    'reduce_stock' => false,        // Don't reduce stock for memo items
                ])->toArray();

            // Build customer data from vendor for order creation
            $vendor = $memo->vendor;
            $customerData = $vendor ? [
                'first_name' => $vendor->name,
                'last_name' => $vendor->company_name ?? '',
                'email' => $vendor->email,
                'phone' => $vendor->phone,
            ] : null;

            $store = $memo->store ?? $this->storeContext->getCurrentStore();
            $order = $this->orderCreationService->create([
                'customer' => $customerData,
                'items' => $items,
                'sub_total' => $memo->subtotal,
                'sales_tax' => $memo->tax,
                'shipping_cost' => $memo->shipping_cost,
                'total' => $memo->total,
                'source_platform' => 'memo',
                'notes' => "Created from Memo #{$memo->memo_number}",
            ], $store);

            // Update invoice number to use MEM-<order.id> format for memo orders
            $order->update(['invoice_number' => "MEM-{$order->id}"]);

            $memo->update(['order_id' => $order->id]);
            $memo->markPaymentReceived();

            // Create and mark invoice as paid
            $invoice = $this->invoiceService->createFromMemo($memo);
            $this->invoiceService->markAsPaid($invoice);

            return $order;
        });
    }

    public function calculateTotals(Memo $memo): array
    {
        $items = $memo->items()->where('is_returned', false)->get();
        $subtotal = $items->sum('price');
        $costTotal = $items->sum('cost');

        // Calculate tax only on items where charge_taxes is true
        $taxableAmount = $items->where('charge_taxes', true)->sum('price');
        $tax = $memo->charge_taxes && $memo->tax_rate > 0 ? $taxableAmount * $memo->tax_rate : 0;
        $total = $subtotal + $tax + $memo->shipping_cost;

        return [
            'subtotal' => $subtotal,
            'cost_total' => $costTotal,
            'tax' => $tax,
            'total' => max(0, $total),
            'profit' => $subtotal - $costTotal,
            'item_count' => $items->count(),
        ];
    }

    public function calculateItemDueDate(MemoItem $item, Memo $memo): void
    {
        $tenor = $item->tenor ?? $memo->tenure;
        $dueDate = $item->created_at->addDays($tenor);

        $item->update(['due_date' => $dueDate]);
    }
}
