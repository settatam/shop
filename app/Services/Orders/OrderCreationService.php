<?php

namespace App\Services\Orders;

use App\Models\BucketItem;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Warehouse;
use App\Services\BucketService;
use App\Services\Invoices\InvoiceService;
use App\Services\TaxService;
use App\Services\TradeIn\TradeInService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderCreationService
{
    protected Order $order;

    protected Store $store;

    /**
     * @var array<string, mixed>
     */
    protected array $data;

    public function __construct(
        protected TaxService $taxService,
        protected TradeInService $tradeInService,
        protected InvoiceService $invoiceService,
        protected BucketService $bucketService,
    ) {}

    /**
     * Create an order from wizard data.
     *
     * @param  array<string, mixed>  $data
     */
    public function createFromWizard(array $data, Store $store): Order
    {
        return DB::transaction(function () use ($data, $store) {
            // Get user from store_user
            $storeUser = StoreUser::with('user')->find($data['store_user_id']);
            $userId = $storeUser?->user_id;

            // Get or create customer
            $customerId = null;
            if (! empty($data['customer_id'])) {
                $customerId = $data['customer_id'];
            } elseif (! empty($data['customer'])) {
                $customer = Customer::create([
                    'store_id' => $store->id,
                    'first_name' => $data['customer']['first_name'],
                    'last_name' => $data['customer']['last_name'],
                    'email' => $data['customer']['email'] ?? null,
                    'phone_number' => $data['customer']['phone'] ?? null,
                ]);
                $customerId = $customer->id;
            }

            // Get warehouse if provided
            $warehouse = null;
            if (! empty($data['warehouse_id'])) {
                $warehouse = Warehouse::find($data['warehouse_id']);
            }

            // Determine tax rate
            $taxRate = $data['tax_rate'] ?? $this->taxService->getTaxRate($warehouse, $store);

            // Generate invoice number
            $invoiceNumber = $this->generateInvoiceNumber($store);

            // Calculate trade-in credit if trade-in items are provided
            $tradeInCredit = 0;
            $tradeInTransaction = null;

            if (! empty($data['trade_in_items']) && $customerId) {
                $tradeInCredit = $this->tradeInService->calculateTradeInCredit($data['trade_in_items']);
            }

            // Create the order
            $this->order = Order::create([
                'store_id' => $store->id,
                'customer_id' => $customerId,
                'user_id' => $userId,
                'warehouse_id' => $warehouse?->id,
                'status' => Order::STATUS_PENDING,
                'invoice_number' => $invoiceNumber,
                'date_of_purchase' => now(),
                'tax_rate' => $taxRate,
                'shipping_cost' => $data['shipping_cost'] ?? 0,
                'discount_cost' => $data['discount_cost'] ?? 0,
                'trade_in_credit' => $tradeInCredit,
                'notes' => $data['notes'] ?? null,
                'billing_address' => $data['billing_address'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'source_platform' => 'in_store',
            ]);

            $this->store = $store;

            // Add items
            foreach ($data['items'] as $itemData) {
                $product = Product::with('variants')->find($itemData['product_id']);
                $variant = isset($itemData['variant_id'])
                    ? ProductVariant::find($itemData['variant_id'])
                    : $product?->variants->first();

                $this->order->items()->create([
                    'product_id' => $product?->id,
                    'product_variant_id' => $variant?->id,
                    'sku' => $itemData['sku'] ?? $variant?->sku,
                    'title' => $itemData['title'] ?? $product?->title ?? 'Unknown Item',
                    'quantity' => $itemData['quantity'] ?? 1,
                    'price' => $itemData['price'],
                    'cost' => $itemData['cost'] ?? $variant?->cost,
                    'discount' => $itemData['discount'] ?? 0,
                    'notes' => $itemData['notes'] ?? null,
                ]);

                // Reduce stock
                if ($variant) {
                    $this->reduceStock($variant, $itemData['quantity'] ?? 1);
                }
            }

            // Add bucket items (if any)
            if (! empty($data['bucket_items'])) {
                foreach ($data['bucket_items'] as $bucketItemData) {
                    $bucketItem = BucketItem::find($bucketItemData['id']);

                    if ($bucketItem && ! $bucketItem->isSold()) {
                        // Create order item from bucket item
                        $orderItem = $this->order->items()->create([
                            'bucket_item_id' => $bucketItem->id,
                            'sku' => null,
                            'title' => $bucketItem->title,
                            'quantity' => 1,
                            'price' => $bucketItemData['price'] ?? $bucketItem->value,
                            'cost' => $bucketItem->value, // Cost is the bucket item value
                            'discount' => 0,
                            'notes' => $bucketItemData['notes'] ?? $bucketItem->description,
                        ]);

                        // Mark bucket item as sold
                        $this->bucketService->sellItem($bucketItem, $orderItem);
                    }
                }
            }

            // Create trade-in transaction and link to order
            if (! empty($data['trade_in_items']) && $customerId) {
                $tradeInTransaction = $this->tradeInService->createTradeIn(
                    $data['trade_in_items'],
                    $customerId,
                    $store,
                    $warehouse?->id,
                    $userId
                );

                $this->tradeInService->applyTradeInToOrder($this->order, $tradeInTransaction);
            }

            // Calculate totals with tax (including trade-in credit)
            $this->calculateTotalsWithTax($taxRate, $tradeInCredit);

            // Handle excess trade-in credit (customer receives refund)
            if ($tradeInCredit > 0 && $this->order->total <= 0) {
                $excessAmount = $tradeInCredit - ($this->order->sub_total + $this->order->shipping_cost + $this->order->sales_tax - $this->order->discount_cost);
                if ($excessAmount > 0 && $tradeInTransaction) {
                    $this->tradeInService->handleExcessCredit(
                        $this->order,
                        $excessAmount,
                        $data['excess_credit_payout_method'] ?? 'cash'
                    );
                }
            }

            // Create invoice for the order
            $this->invoiceService->createFromOrder($this->order);

            return $this->order->fresh(['items', 'customer', 'user', 'warehouse', 'tradeInTransaction.items', 'invoice']);
        });
    }

    /**
     * Generate a unique invoice number.
     */
    protected function generateInvoiceNumber(Store $store): string
    {
        $prefix = 'INV-';
        $date = now()->format('Ymd');
        $count = Order::where('store_id', $store->id)
            ->whereDate('created_at', now()->toDateString())
            ->count() + 1;

        return $prefix.$date.'-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate totals including tax and trade-in credit.
     * Tax is calculated on the net amount after subtracting trade-in credit.
     */
    protected function calculateTotalsWithTax(float $taxRate, float $tradeInCredit = 0): void
    {
        $this->order->refresh();
        $this->order->load('items');

        $subTotal = $this->order->items->sum(fn ($item) => $item->line_total);

        // Taxable amount = subtotal - discounts - trade_in_credit
        // This ensures tax is only charged on the net purchase amount
        $taxableAmount = max(0, $subTotal - ($this->order->discount_cost ?? 0) - $tradeInCredit);
        $salesTax = $taxableAmount * $taxRate;

        $total = $subTotal
            + ($this->order->shipping_cost ?? 0)
            + $salesTax
            - ($this->order->discount_cost ?? 0)
            - $tradeInCredit;

        $this->order->update([
            'sub_total' => $subTotal,
            'sales_tax' => $salesTax,
            'total' => max(0, $total),
        ]);
    }

    public function create(array $data, Store $store): Order
    {
        $this->data = $data;
        $this->store = $store;

        return DB::transaction(function () {
            $this->createOrder();
            $this->assignCustomer();
            $this->handleItems();
            $this->handleShipping();
            $this->handleDiscount();
            $this->calculateTotals();
            $this->handlePayments();
            $this->handleAddresses();
            $this->handleNotes();

            // Create invoice for the order
            $this->invoiceService->createFromOrder($this->order);

            return $this->order->fresh(['items', 'customer', 'payments', 'invoice']);
        });
    }

    protected function createOrder(): void
    {
        $this->order = Order::create([
            'store_id' => $this->store->id,
            'user_id' => $this->data['user_id'] ?? auth()->id(),
            'status' => Order::STATUS_PENDING,
            'date_of_purchase' => $this->data['date_of_purchase'] ?? now(),
            'source_platform' => $this->data['source_platform'] ?? null,
            'external_marketplace_id' => $this->data['external_marketplace_id'] ?? null,
        ]);
    }

    protected function assignCustomer(): void
    {
        if (empty($this->data['customer'])) {
            return;
        }

        $customerData = $this->data['customer'];

        if (isset($customerData['id'])) {
            $customer = Customer::find($customerData['id']);
            if ($customer && $customer->store_id === $this->store->id) {
                $this->order->update(['customer_id' => $customer->id]);

                return;
            }
        }

        if (isset($customerData['email'])) {
            $customer = Customer::firstOrCreate(
                [
                    'email' => $customerData['email'],
                    'store_id' => $this->store->id,
                ],
                [
                    'first_name' => $customerData['first_name'] ?? null,
                    'last_name' => $customerData['last_name'] ?? null,
                    'phone_number' => $customerData['phone'] ?? null,
                ]
            );

            $this->order->update(['customer_id' => $customer->id]);
        }
    }

    protected function handleItems(): void
    {
        if (empty($this->data['items'])) {
            throw new InvalidArgumentException('Order must have at least one item.');
        }

        foreach ($this->data['items'] as $itemData) {
            $this->addItem($itemData);
        }
    }

    protected function addItem(array $itemData): void
    {
        $variant = null;
        $product = null;

        if (isset($itemData['product_variant_id'])) {
            $variant = ProductVariant::with('product')->find($itemData['product_variant_id']);
            if ($variant) {
                $product = $variant->product;
            }
        }

        $quantity = $itemData['quantity'] ?? 1;

        if (($itemData['validate_stock'] ?? false) && $variant) {
            $this->validateStock($variant, $quantity);
        }

        $price = $itemData['price'] ?? ($variant?->price ?? 0);
        $cost = $itemData['cost'] ?? ($variant?->cost ?? null);

        $this->order->items()->create([
            'product_id' => $product?->id ?? $itemData['product_id'] ?? null,
            'product_variant_id' => $variant?->id ?? $itemData['product_variant_id'] ?? null,
            'sku' => $itemData['sku'] ?? ($variant?->sku ?? null),
            'title' => $itemData['title'] ?? ($variant?->title ?? $product?->title ?? 'Unknown Item'),
            'quantity' => $quantity,
            'price' => $price,
            'cost' => $cost,
            'discount' => $itemData['discount'] ?? 0,
            'tax' => $itemData['tax'] ?? null,
            'notes' => $itemData['notes'] ?? null,
        ]);

        if (($itemData['reduce_stock'] ?? true) && $variant) {
            $this->reduceStock($variant, $quantity);
        }
    }

    protected function validateStock(ProductVariant $variant, int $quantity): void
    {
        $availableStock = Inventory::where('product_variant_id', $variant->id)
            ->sum(DB::raw('quantity - reserved_quantity'));

        if ($availableStock < $quantity) {
            throw new InvalidArgumentException(
                "Insufficient stock for {$variant->sku}. Available: {$availableStock}, Requested: {$quantity}"
            );
        }
    }

    protected function reduceStock(ProductVariant $variant, int $quantity): void
    {
        $remaining = $quantity;

        $inventories = Inventory::where('product_variant_id', $variant->id)
            ->where('quantity', '>', 0)
            ->orderBy('quantity', 'desc')
            ->get();

        foreach ($inventories as $inventory) {
            if ($remaining <= 0) {
                break;
            }

            $available = $inventory->quantity - $inventory->reserved_quantity;
            $reduceBy = min($available, $remaining);

            if ($reduceBy > 0) {
                $inventory->decrement('quantity', $reduceBy);
                $inventory->update(['last_sold_at' => now()]);
                $remaining -= $reduceBy;
            }
        }
    }

    protected function handleShipping(): void
    {
        if (isset($this->data['shipping_cost'])) {
            $this->order->update(['shipping_cost' => $this->data['shipping_cost']]);
        }

        if (isset($this->data['shipping_weight'])) {
            $this->order->update(['shipping_weight' => $this->data['shipping_weight']]);
        }
    }

    protected function handleDiscount(): void
    {
        if (isset($this->data['discount_cost'])) {
            $this->order->update(['discount_cost' => $this->data['discount_cost']]);
        }
    }

    protected function calculateTotals(): void
    {
        $this->order->refresh();
        $this->order->load('items');

        $subTotal = $this->order->items->sum(fn ($item) => $item->line_total);
        $total = $subTotal
            + ($this->order->shipping_cost ?? 0)
            + ($this->order->sales_tax ?? 0)
            - ($this->order->discount_cost ?? 0);

        $this->order->update([
            'sub_total' => $subTotal,
            'total' => max(0, $total),
        ]);
    }

    protected function handlePayments(): void
    {
        if (empty($this->data['payments'])) {
            return;
        }

        foreach ($this->data['payments'] as $paymentData) {
            $this->addPayment($paymentData);
        }

        $this->updateOrderStatusFromPayments();
    }

    public function addPayment(array $paymentData): Payment
    {
        $payment = Payment::create([
            'store_id' => $this->store->id,
            'payable_type' => Order::class,
            'payable_id' => $this->order->id,
            'order_id' => $this->order->id, // Keep for backwards compatibility
            'customer_id' => $this->order->customer_id,
            'user_id' => $paymentData['user_id'] ?? auth()->id(),
            'payment_method' => $paymentData['payment_method'] ?? Payment::METHOD_CASH,
            'status' => $paymentData['status'] ?? Payment::STATUS_COMPLETED,
            'amount' => $paymentData['amount'],
            'currency' => $paymentData['currency'] ?? 'USD',
            'reference' => $paymentData['reference'] ?? null,
            'transaction_id' => $paymentData['transaction_id'] ?? null,
            'gateway' => $paymentData['gateway'] ?? null,
            'notes' => $paymentData['notes'] ?? null,
            'metadata' => $paymentData['metadata'] ?? null,
            'paid_at' => ($paymentData['status'] ?? Payment::STATUS_COMPLETED) === Payment::STATUS_COMPLETED
                ? now()
                : null,
        ]);

        return $payment;
    }

    protected function updateOrderStatusFromPayments(): void
    {
        $this->order->refresh();

        if ($this->order->isFullyPaid()) {
            $this->order->update(['status' => Order::STATUS_CONFIRMED]);
        } elseif ($this->order->total_paid > 0) {
            $this->order->update(['status' => Order::STATUS_PARTIAL_PAYMENT]);
        }
    }

    protected function handleAddresses(): void
    {
        if (isset($this->data['billing_address'])) {
            $this->order->update(['billing_address' => $this->data['billing_address']]);
        }

        if (isset($this->data['shipping_address'])) {
            $this->order->update(['shipping_address' => $this->data['shipping_address']]);
        }
    }

    protected function handleNotes(): void
    {
        if (isset($this->data['notes'])) {
            $this->order->update(['notes' => $this->data['notes']]);
        }
    }

    public function addPaymentToOrder(Order $order, array $paymentData): Payment
    {
        $this->order = $order;
        $this->store = $order->store;

        $payment = $this->addPayment($paymentData);
        $this->updateOrderStatusFromPayments();

        return $payment;
    }

    public function cancelOrder(Order $order): Order
    {
        if ($order->isCancelled()) {
            return $order;
        }

        return DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                if ($item->product_variant_id) {
                    $this->restoreStock($item->product_variant_id, $item->quantity);
                }
            }

            $order->cancel();

            return $order->fresh();
        });
    }

    protected function restoreStock(int $variantId, int $quantity): void
    {
        $inventory = Inventory::where('product_variant_id', $variantId)->first();

        if ($inventory) {
            $inventory->increment('quantity', $quantity);
        }
    }
}
