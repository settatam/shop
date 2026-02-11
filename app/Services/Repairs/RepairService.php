<?php

namespace App\Services\Repairs;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Repair;
use App\Models\RepairItem;
use App\Models\StoreUser;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Invoices\InvoiceService;
use App\Services\Orders\OrderCreationService;
use App\Services\StoreContext;
use App\Services\TaxService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RepairService
{
    public function __construct(
        protected StoreContext $storeContext,
        protected OrderCreationService $orderCreationService,
        protected TaxService $taxService,
        protected InvoiceService $invoiceService,
    ) {}

    public function create(array $data): Repair
    {
        return DB::transaction(function () use ($data) {
            $store = $this->storeContext->getCurrentStore();
            $warehouseId = $data['warehouse_id'] ?? $this->storeContext->getDefaultWarehouseId();
            $warehouse = $warehouseId ? Warehouse::find($warehouseId) : null;
            $defaultTaxRate = $store ? $this->taxService->getTaxRate($warehouse, $store) : 0;

            $repair = Repair::create([
                'store_id' => $store?->id ?? $data['store_id'],
                'warehouse_id' => $warehouseId,
                'customer_id' => $data['customer_id'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? null,
                'user_id' => $data['user_id'] ?? auth()->id(),
                'service_fee' => $data['service_fee'] ?? 0,
                'tax_rate' => $data['tax_rate'] ?? $defaultTaxRate,
                'shipping_cost' => $data['shipping_cost'] ?? 0,
                'discount' => $data['discount'] ?? 0,
                'description' => $data['description'] ?? null,
                'is_appraisal' => $data['is_appraisal'] ?? false,
            ]);

            if (! empty($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $this->addItem($repair, $itemData);
                }
                $repair->calculateTotals();
            }

            return $repair->fresh(['customer', 'vendor', 'user', 'items']);
        });
    }

    /**
     * Create a repair from the wizard form data.
     *
     * @param  array<string, mixed>  $data
     */
    public function createFromWizard(array $data): Repair
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
                    'last_name' => $data['customer']['last_name'],
                    'company_name' => $data['customer']['company_name'] ?? null,
                    'email' => $data['customer']['email'] ?? null,
                    'phone_number' => $data['customer']['phone_number'] ?? null,
                ]);
                $customerId = $customer->id;
            }

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

            // Create the repair
            $repair = Repair::create([
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'customer_id' => $customerId,
                'vendor_id' => $vendorId,
                'user_id' => $userId,
                'service_fee' => $data['service_fee'] ?? 0,
                'tax_rate' => $data['tax_rate'] ?? $defaultTaxRate,
                'shipping_cost' => $data['shipping_cost'] ?? 0,
                'discount' => $data['discount'] ?? 0,
                'description' => $data['description'] ?? null,
                'is_appraisal' => $data['is_appraisal'] ?? false,
                'status' => Repair::STATUS_PENDING,
            ]);

            // Add items
            foreach ($data['items'] as $itemData) {
                $repair->items()->create([
                    'category_id' => $itemData['category_id'] ?? null,
                    'title' => $itemData['title'],
                    'description' => $itemData['description'] ?? null,
                    'vendor_cost' => $itemData['vendor_cost'] ?? 0,
                    'customer_cost' => $itemData['customer_cost'] ?? 0,
                    'dwt' => $itemData['dwt'] ?? null,
                    'precious_metal' => $itemData['precious_metal'] ?? null,
                    'status' => RepairItem::STATUS_PENDING,
                ]);
            }

            // Calculate totals
            $repair->calculateTotals();

            return $repair->fresh(['customer', 'vendor', 'user', 'items.category']);
        });
    }

    public function addItem(Repair $repair, array $data): RepairItem
    {
        $item = $repair->items()->create([
            'product_id' => $data['product_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'sku' => $data['sku'] ?? null,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'vendor_cost' => $data['vendor_cost'] ?? 0,
            'customer_cost' => $data['customer_cost'] ?? 0,
            'dwt' => $data['dwt'] ?? null,
            'precious_metal' => $data['precious_metal'] ?? null,
        ]);

        // Mark product as in repair if linked to a product
        if (! empty($data['product_id'])) {
            $product = Product::find($data['product_id']);
            if ($product) {
                RepairItem::markProductInRepair($product);
            }
        }

        $repair->calculateTotals();

        return $item;
    }

    public function updateItem(RepairItem $item, array $data): RepairItem
    {
        $item->update([
            'product_id' => $data['product_id'] ?? $item->product_id,
            'category_id' => $data['category_id'] ?? $item->category_id,
            'sku' => $data['sku'] ?? $item->sku,
            'title' => $data['title'] ?? $item->title,
            'description' => $data['description'] ?? $item->description,
            'vendor_cost' => $data['vendor_cost'] ?? $item->vendor_cost,
            'customer_cost' => $data['customer_cost'] ?? $item->customer_cost,
            'dwt' => $data['dwt'] ?? $item->dwt,
            'precious_metal' => $data['precious_metal'] ?? $item->precious_metal,
        ]);

        $item->repair->calculateTotals();

        return $item->fresh();
    }

    public function removeItem(RepairItem $item): bool
    {
        $repair = $item->repair;

        // Restore product status if linked to a product
        $item->returnToStock();

        $result = $item->delete();
        $repair->calculateTotals();

        return $result;
    }

    public function sendToVendor(Repair $repair): Repair
    {
        if (! $repair->canBeSentToVendor()) {
            throw new InvalidArgumentException('Repair cannot be sent to vendor in its current state.');
        }

        return $repair->sendToVendor();
    }

    public function markReceivedByVendor(Repair $repair): Repair
    {
        if (! $repair->canBeMarkedAsReceived()) {
            throw new InvalidArgumentException('Repair cannot be marked as received in its current state.');
        }

        return $repair->markReceivedByVendor();
    }

    public function markCompleted(Repair $repair): Repair
    {
        if (! $repair->canBeCompleted()) {
            throw new InvalidArgumentException('Repair cannot be marked as completed in its current state.');
        }

        return $repair->markCompleted();
    }

    public function cancel(Repair $repair): Repair
    {
        if ($repair->isPaymentReceived()) {
            throw new InvalidArgumentException('Cannot cancel repair that has received payment.');
        }

        return $repair->cancel();
    }

    public function createSaleOrder(Repair $repair): Order
    {
        if (! $repair->isCompleted() && ! $repair->customer_id) {
            throw new InvalidArgumentException('Repair must be completed and have a customer to create a sale order.');
        }

        return DB::transaction(function () use ($repair) {
            $items = $repair->items->map(fn (RepairItem $item) => [
                'product_id' => $item->product_id,
                'sku' => $item->sku,
                'title' => $item->title ?? $item->product?->title ?? 'Repair Service',
                'quantity' => 1,
                'cost' => $item->vendor_cost,    // What vendor charges us
                'price' => $item->customer_cost, // What customer pays
                'reduce_stock' => false,         // Don't reduce stock for repair items
            ])->toArray();

            // Add service fee as a line item if present
            if ($repair->service_fee > 0) {
                $items[] = [
                    'title' => 'Service Fee',
                    'sku' => 'SERVICE-FEE',
                    'quantity' => 1,
                    'cost' => 0,                      // No cost for service fee
                    'price' => $repair->service_fee,  // Pure profit
                    'reduce_stock' => false,
                ];
            }

            $store = $repair->store ?? $this->storeContext->getCurrentStore();
            $order = $this->orderCreationService->create([
                'customer_id' => $repair->customer_id,
                'items' => $items,
                'sub_total' => $repair->subtotal,
                'sales_tax' => $repair->tax,
                'shipping_cost' => $repair->shipping_cost,
                'discount_cost' => $repair->discount,
                'total' => $repair->total,
                'source_platform' => 'repair',
                'notes' => "Created from Repair #{$repair->repair_number}",
            ], $store);

            // Update invoice number to use REP-<order.id> format for repair orders
            $order->update(['invoice_number' => "REP-{$order->id}"]);

            $repair->update(['order_id' => $order->id]);
            $repair->markPaymentReceived();

            // Create and mark invoice as paid
            $invoice = $this->invoiceService->createFromRepair($repair);
            $this->invoiceService->markAsPaid($invoice);

            return $order;
        });
    }

    public function calculateTotals(Repair $repair): array
    {
        $items = $repair->items;
        $subtotal = $items->sum('customer_cost');
        $vendorTotal = $items->sum('vendor_cost');
        $tax = $repair->tax_rate > 0 ? $subtotal * $repair->tax_rate : 0;
        $total = $subtotal + $repair->service_fee + $tax + $repair->shipping_cost - $repair->discount;

        return [
            'subtotal' => $subtotal,
            'vendor_total' => $vendorTotal,
            'tax' => $tax,
            'total' => max(0, $total),
            'profit' => $subtotal - $vendorTotal,
        ];
    }
}
