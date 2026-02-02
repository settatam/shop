<?php

namespace App\Services\Layaways;

use App\Models\Customer;
use App\Models\Layaway;
use App\Models\LayawayItem;
use App\Models\LayawaySchedule;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreUser;
use App\Models\Warehouse;
use App\Services\Orders\OrderCreationService;
use App\Services\StoreContext;
use App\Services\TaxService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LayawayService
{
    public function __construct(
        protected StoreContext $storeContext,
        protected OrderCreationService $orderCreationService,
        protected TaxService $taxService,
    ) {}

    /**
     * Create a layaway from the wizard form data.
     *
     * @param  array<string, mixed>  $data
     */
    public function createFromWizard(array $data): Layaway
    {
        return DB::transaction(function () use ($data) {
            $store = $this->storeContext->getCurrentStore();
            $storeId = $store?->id ?? $data['store_id'];
            $warehouseId = $data['warehouse_id'] ?? $this->storeContext->getDefaultWarehouseId();
            $warehouse = $warehouseId ? Warehouse::find($warehouseId) : null;
            $defaultTaxRate = $store ? $this->taxService->getTaxRate($warehouse, $store) : 0;

            // Get or create customer
            $customerId = $data['customer_id'] ?? null;
            if (! $customerId && ! empty($data['customer'])) {
                $customer = Customer::create([
                    'store_id' => $storeId,
                    'first_name' => $data['customer']['first_name'],
                    'last_name' => $data['customer']['last_name'] ?? null,
                    'email' => $data['customer']['email'] ?? null,
                    'phone' => $data['customer']['phone'] ?? null,
                ]);
                $customerId = $customer->id;
            }

            // Get the user ID from store_user
            $storeUser = StoreUser::find($data['store_user_id']);
            $userId = $storeUser?->user_id ?? auth()->id();

            $termDays = $data['term_days'] ?? Layaway::TERM_90_DAYS;
            $startDate = now();
            $dueDate = $startDate->copy()->addDays($termDays);

            // Create the layaway
            $layaway = Layaway::create([
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'customer_id' => $customerId,
                'user_id' => $userId,
                'status' => Layaway::STATUS_PENDING,
                'payment_type' => $data['payment_type'] ?? Layaway::PAYMENT_TYPE_FLEXIBLE,
                'term_days' => $termDays,
                'minimum_deposit_percent' => $data['minimum_deposit_percent'] ?? 10.00,
                'cancellation_fee_percent' => $data['cancellation_fee_percent'] ?? 10.00,
                'tax_rate' => $data['tax_rate'] ?? $defaultTaxRate,
                'start_date' => $startDate,
                'due_date' => $dueDate,
                'admin_notes' => $data['admin_notes'] ?? null,
            ]);

            // Add items and reserve products
            foreach ($data['items'] as $itemData) {
                $product = Product::with('variants')->find($itemData['product_id']);

                if (! $product) {
                    continue;
                }

                $variant = $product->variants->first();
                $quantity = $itemData['quantity'] ?? 1;
                $price = $itemData['price'] ?? $variant?->price ?? 0;

                // Create layaway item
                $item = $layaway->items()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'sku' => $variant?->sku ?? $product->sku,
                    'title' => $itemData['title'] ?? $product->title,
                    'description' => $itemData['description'] ?? $product->description,
                    'quantity' => $quantity,
                    'price' => $price,
                    'line_total' => $quantity * $price,
                    'is_reserved' => true,
                ]);

                // Reserve product inventory
                $this->reserveProduct($product, $quantity);
            }

            // Calculate totals
            $layaway->calculateTotals();

            // Generate payment schedule if scheduled payment type
            if ($layaway->isScheduled() && ! empty($data['num_payments'])) {
                $frequency = $data['payment_frequency'] ?? LayawaySchedule::FREQUENCY_BIWEEKLY;
                $this->generatePaymentSchedule($layaway, $data['num_payments'], $frequency);
            }

            return $layaway->fresh(['customer', 'user', 'items.product', 'schedules']);
        });
    }

    /**
     * Generate a payment schedule for a layaway.
     */
    public function generatePaymentSchedule(Layaway $layaway, int $numPayments, string $frequency): Collection
    {
        // Delete any existing schedules
        $layaway->schedules()->delete();

        // Calculate amount per payment (after deposit)
        $depositAmount = $layaway->deposit_amount > 0
            ? $layaway->deposit_amount
            : $layaway->minimum_deposit;

        $remainingAmount = $layaway->total - $depositAmount;
        $amountPerPayment = $numPayments > 0 ? $remainingAmount / $numPayments : $remainingAmount;
        $frequencyDays = LayawaySchedule::getFrequencyDays($frequency);

        $schedules = collect();
        $currentDate = $layaway->start_date ?? now();

        for ($i = 1; $i <= $numPayments; $i++) {
            $currentDate = $currentDate->copy()->addDays($frequencyDays);

            // For the last payment, adjust for any rounding differences
            $paymentAmount = ($i === $numPayments)
                ? $remainingAmount - ($amountPerPayment * ($numPayments - 1))
                : $amountPerPayment;

            $schedule = $layaway->schedules()->create([
                'installment_number' => $i,
                'due_date' => $currentDate,
                'amount_due' => round($paymentAmount, 2),
                'amount_paid' => 0,
                'status' => LayawaySchedule::STATUS_PENDING,
            ]);

            $schedules->push($schedule);
        }

        return $schedules;
    }

    /**
     * Record a payment on a layaway.
     *
     * @param  array<string, mixed>  $paymentData
     */
    public function recordPayment(Layaway $layaway, float $amount, array $paymentData = []): void
    {
        if (! $layaway->canReceivePayment()) {
            throw new InvalidArgumentException('This layaway cannot receive payments.');
        }

        // Record the payment on the layaway (this also activates if deposit threshold is met)
        $layaway->recordPayment($amount);

        // If scheduled, apply payment to schedules
        if ($layaway->isScheduled()) {
            $this->applyPaymentToSchedules($layaway, $amount);
        }

        // Check if fully paid
        if ($layaway->isFullyPaid()) {
            $layaway->onPaymentComplete();
        }
    }

    /**
     * Apply a payment amount to pending schedules.
     */
    protected function applyPaymentToSchedules(Layaway $layaway, float $amount): void
    {
        $remaining = $amount;

        $pendingSchedules = $layaway->schedules()
            ->whereIn('status', [LayawaySchedule::STATUS_PENDING, LayawaySchedule::STATUS_OVERDUE])
            ->orderBy('due_date')
            ->get();

        foreach ($pendingSchedules as $schedule) {
            if ($remaining <= 0) {
                break;
            }

            $amountNeeded = $schedule->remaining_amount;
            $amountToApply = min($remaining, $amountNeeded);

            $schedule->recordPayment($amountToApply);
            $remaining -= $amountToApply;
        }
    }

    /**
     * Activate a layaway (usually after deposit is received).
     */
    public function activate(Layaway $layaway): Layaway
    {
        if (! $layaway->isPending()) {
            throw new InvalidArgumentException('Can only activate pending layaways.');
        }

        if ($layaway->total_paid < $layaway->minimum_deposit) {
            throw new InvalidArgumentException('Minimum deposit has not been met.');
        }

        return $layaway->activate();
    }

    /**
     * Complete a layaway (creates final order).
     */
    public function complete(Layaway $layaway): Layaway
    {
        return DB::transaction(function () use ($layaway) {
            if (! $layaway->isActive()) {
                throw new InvalidArgumentException('Can only complete active layaways.');
            }

            if (! $layaway->isFullyPaid()) {
                throw new InvalidArgumentException('Layaway has outstanding balance.');
            }

            // Create the final order
            $order = $this->createOrderFromLayaway($layaway);
            $layaway->update(['order_id' => $order->id]);

            // Release reserved items (they're now sold via order)
            $layaway->items()->update(['is_reserved' => false]);

            return $layaway->complete();
        });
    }

    /**
     * Cancel a layaway.
     */
    public function cancel(Layaway $layaway, ?float $restockingFee = null): Layaway
    {
        return DB::transaction(function () use ($layaway, $restockingFee) {
            if ($layaway->isCompleted()) {
                throw new InvalidArgumentException('Cannot cancel a completed layaway.');
            }

            // Release all reserved items back to inventory
            foreach ($layaway->items as $item) {
                if ($item->is_reserved && $item->product) {
                    $this->releaseProduct($item->product, $item->quantity);
                }
            }

            return $layaway->cancel($restockingFee);
        });
    }

    /**
     * Create an order from a completed layaway.
     */
    protected function createOrderFromLayaway(Layaway $layaway): Order
    {
        $items = $layaway->items->map(fn (LayawayItem $item) => [
            'title' => $item->title,
            'sku' => $item->sku,
            'quantity' => $item->quantity,
            'price' => $item->price,
            'product_id' => $item->product_id,
            'reduce_stock' => false, // Stock already reserved
        ])->toArray();

        $customer = $layaway->customer;
        $customerData = $customer ? [
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
        ] : null;

        $store = $layaway->store ?? $this->storeContext->getCurrentStore();

        $order = $this->orderCreationService->create([
            'customer' => $customerData,
            'customer_id' => $layaway->customer_id,
            'items' => $items,
            'sub_total' => $layaway->subtotal,
            'sales_tax' => $layaway->tax_amount,
            'total' => $layaway->total,
            'source_platform' => 'layaway',
            'notes' => "Created from Layaway {$layaway->layaway_number}",
        ], $store);

        // Mark order as confirmed/paid since layaway is fully paid
        $order->update([
            'status' => Order::STATUS_CONFIRMED,
            'invoice_number' => "LAY-{$order->id}",
        ]);

        return $order;
    }

    /**
     * Reserve a product (reduce available quantity).
     */
    protected function reserveProduct(Product $product, int $quantity): void
    {
        $product->decrement('quantity', $quantity);
    }

    /**
     * Release a reserved product (restore available quantity).
     */
    protected function releaseProduct(Product $product, int $quantity): void
    {
        $product->increment('quantity', $quantity);
    }

    /**
     * Add an item to an existing layaway.
     *
     * @param  array<string, mixed>  $data
     */
    public function addItem(Layaway $layaway, array $data): LayawayItem
    {
        if (! $layaway->isPending()) {
            throw new InvalidArgumentException('Items can only be added to pending layaways.');
        }

        $product = Product::with('variants')->find($data['product_id']);

        if (! $product) {
            throw new InvalidArgumentException('Product not found.');
        }

        $variant = $product->variants->first();
        $quantity = $data['quantity'] ?? 1;
        $price = $data['price'] ?? $variant?->price ?? 0;

        // Reserve the product
        $this->reserveProduct($product, $quantity);

        $item = $layaway->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'sku' => $variant?->sku ?? $product->sku,
            'title' => $data['title'] ?? $product->title,
            'description' => $data['description'] ?? $product->description,
            'quantity' => $quantity,
            'price' => $price,
            'line_total' => $quantity * $price,
            'is_reserved' => true,
        ]);

        $layaway->calculateTotals();

        return $item;
    }

    /**
     * Remove an item from a layaway.
     */
    public function removeItem(LayawayItem $item): bool
    {
        if (! $item->layaway->isPending()) {
            throw new InvalidArgumentException('Items can only be removed from pending layaways.');
        }

        // Release the reserved product
        if ($item->is_reserved && $item->product) {
            $this->releaseProduct($item->product, $item->quantity);
        }

        return $item->delete();
    }

    /**
     * Calculate the refund amount for a cancelled layaway.
     */
    public function calculateRefundAmount(Layaway $layaway): float
    {
        $cancellationFee = $layaway->cancellation_fee;

        return max(0, $layaway->total_paid - $cancellationFee);
    }
}
