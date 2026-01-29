<?php

namespace App\Services\Notifications;

use App\Models\Customer;
use App\Models\Memo;
use App\Models\Order;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StoreContext;

class NotificationDataPreparer
{
    public function __construct(protected StoreContext $storeContext) {}

    /**
     * Prepare comprehensive data array for notification templates.
     *
     * @param  array<string, mixed>  $context  Raw context data with models or IDs
     */
    public function prepare(array $context = []): array
    {
        $data = [];

        // Always include store data
        $store = $context['store'] ?? $this->storeContext->getCurrentStore();
        if ($store) {
            $data['store'] = $this->prepareStore($store);
        }

        // Prepare each context item
        if (isset($context['order'])) {
            $data['order'] = $this->prepareOrder($context['order']);
            // Auto-include customer from order if not provided
            if (! isset($context['customer']) && $data['order']['customer'] ?? null) {
                $data['customer'] = $data['order']['customer'];
            }
        }

        if (isset($context['customer'])) {
            $data['customer'] = $this->prepareCustomer($context['customer']);
        }

        if (isset($context['product'])) {
            $data['product'] = $this->prepareProduct($context['product']);
        }

        if (isset($context['transaction'])) {
            $data['transaction'] = $this->prepareTransaction($context['transaction']);
        }

        if (isset($context['memo'])) {
            $data['memo'] = $this->prepareMemo($context['memo']);
        }

        if (isset($context['repair'])) {
            $data['repair'] = $this->prepareRepair($context['repair']);
        }

        if (isset($context['user'])) {
            $data['user'] = $this->prepareUser($context['user']);
        }

        if (isset($context['warehouse'])) {
            $data['warehouse'] = $this->prepareWarehouse($context['warehouse']);
        }

        // Pass through any additional data
        foreach ($context as $key => $value) {
            if (! isset($data[$key]) && ! in_array($key, ['store', 'order', 'customer', 'product', 'transaction', 'memo', 'repair', 'user', 'warehouse'])) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * Prepare store data with all relevant details.
     */
    public function prepareStore(Store|int $store): array
    {
        if (is_int($store)) {
            $store = Store::with(['owner', 'warehouses'])->find($store);
        } else {
            $store->loadMissing(['owner', 'warehouses']);
        }

        if (! $store) {
            return [];
        }

        $defaultWarehouse = $store->warehouses->where('is_default', true)->first();

        return [
            'id' => $store->id,
            'name' => $store->name,
            'business_name' => $store->business_name,
            'email' => $store->account_email,
            'customer_email' => $store->customer_email,
            'phone' => $store->phone,
            'address' => $store->address,
            'address2' => $store->address2,
            'city' => $store->city,
            'state' => $store->state,
            'zip' => $store->zip,
            'full_address' => implode(', ', array_filter([
                $store->address,
                $store->address2,
                $store->city,
                $store->state,
                $store->zip,
            ])),
            'url' => $store->url,
            'domain' => $store->store_domain,
            'owner' => $store->owner ? [
                'name' => $store->owner->name,
                'email' => $store->owner->email,
            ] : null,
            'warehouse' => $defaultWarehouse ? $this->prepareWarehouse($defaultWarehouse) : null,
            'default_warehouse' => $defaultWarehouse ? $this->prepareWarehouse($defaultWarehouse) : null,
        ];
    }

    /**
     * Prepare order data with all relationships.
     */
    public function prepareOrder(Order|int $order): array
    {
        if (is_int($order)) {
            $order = Order::with([
                'customer',
                'items.product.variants',
                'items.productVariant',
                'warehouse',
                'user',
                'payments',
                'tradeInTransaction',
            ])->find($order);
        } else {
            $order->loadMissing([
                'customer',
                'items.product.variants',
                'items.productVariant',
                'warehouse',
                'user',
                'payments',
                'tradeInTransaction',
            ]);
        }

        if (! $order) {
            return [];
        }

        return [
            'id' => $order->id,
            'number' => $order->invoice_number ?? "ORD-{$order->id}",
            'invoice_number' => $order->invoice_number,
            'status' => $order->status,
            'sub_total' => (float) $order->sub_total,
            'total' => (float) $order->total,
            'sales_tax' => (float) $order->sales_tax,
            'tax_rate' => (float) $order->tax_rate,
            'shipping_cost' => (float) $order->shipping_cost,
            'discount_cost' => (float) $order->discount_cost,
            'trade_in_credit' => (float) $order->trade_in_credit,
            'service_fee' => (float) $order->service_fee_value,
            'total_paid' => (float) $order->total_paid,
            'balance_due' => (float) $order->balance_due,
            'item_count' => $order->item_count,
            'date_of_purchase' => $order->date_of_purchase?->format('Y-m-d'),
            'created_at' => $order->created_at?->format('Y-m-d H:i:s'),
            'source_platform' => $order->source_platform,
            'external_marketplace_id' => $order->external_marketplace_id,
            'notes' => $order->notes,
            'tracking_number' => $order->shipping_address['tracking_number'] ?? null,
            'billing_address' => $this->formatAddress($order->billing_address),
            'shipping_address' => $this->formatAddress($order->shipping_address),
            'customer' => $order->customer ? $this->prepareCustomer($order->customer) : null,
            'warehouse' => $order->warehouse ? $this->prepareWarehouse($order->warehouse) : null,
            'created_by' => $order->user ? [
                'name' => $order->user->name,
                'email' => $order->user->email,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->product?->title ?? $item->name ?? 'Unknown Product',
                'sku' => $item->productVariant?->sku ?? $item->product?->variants->first()?->sku,
                'quantity' => $item->quantity,
                'price' => (float) $item->price,
                'total' => (float) $item->line_total,
                'product' => $item->product ? $this->prepareProduct($item->product) : null,
            ])->toArray(),
            'payments' => $order->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'method' => $payment->payment_method,
                'status' => $payment->status,
                'reference' => $payment->reference,
                'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
            ])->toArray(),
            'has_trade_in' => $order->hasTradeIn(),
            'trade_in' => $order->tradeInTransaction ? [
                'id' => $order->tradeInTransaction->id,
                'total' => (float) $order->tradeInTransaction->total_value,
            ] : null,
        ];
    }

    /**
     * Prepare customer data.
     */
    public function prepareCustomer(Customer|int $customer): array
    {
        if (is_int($customer)) {
            $customer = Customer::with(['leadSource'])->find($customer);
        } else {
            $customer->loadMissing(['leadSource']);
        }

        if (! $customer) {
            return [];
        }

        return [
            'id' => $customer->id,
            'name' => $customer->full_name,
            'full_name' => $customer->full_name,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone_number,
            'phone_number' => $customer->phone_number,
            'company_name' => $customer->company_name,
            'address' => $customer->address,
            'address2' => $customer->address2,
            'city' => $customer->city,
            'zip' => $customer->zip,
            'full_address' => implode(', ', array_filter([
                $customer->address,
                $customer->address2,
                $customer->city,
                $customer->zip,
            ])),
            'accepts_marketing' => $customer->accepts_marketing,
            'lead_source' => $customer->leadSource?->name,
            'number_of_sales' => $customer->number_of_sales,
            'number_of_buys' => $customer->number_of_buys,
            'last_sales_date' => $customer->last_sales_date?->format('Y-m-d'),
            'created_at' => $customer->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Prepare product data with flattened template attributes.
     */
    public function prepareProduct(Product|int $product): array
    {
        if (is_int($product)) {
            $product = Product::with([
                'category',
                'brand',
                'vendor',
                'variants',
                'template.fields',
                'attributeValues.field',
                'returnPolicy',
            ])->find($product);
        } else {
            $product->loadMissing([
                'category',
                'brand',
                'vendor',
                'variants',
                'template.fields',
                'attributeValues.field',
                'returnPolicy',
            ]);
        }

        if (! $product) {
            return [];
        }

        // Base product data
        $data = [
            'id' => $product->id,
            'title' => $product->title,
            'description' => $product->description,
            'handle' => $product->handle,
            'sku' => $product->variants->first()?->sku,
            'price' => (float) ($product->variants->first()?->price ?? 0),
            'compare_at_price' => (float) $product->compare_at_price,
            'cost' => (float) ($product->variants->first()?->cost ?? 0),
            'wholesale_price' => (float) ($product->variants->first()?->wholesale_price ?? 0),
            'quantity' => $product->total_quantity,
            'weight' => (float) $product->weight,
            'weight_unit' => $product->weight_unit,
            'length' => (float) $product->length,
            'width' => (float) $product->width,
            'height' => (float) $product->height,
            'upc' => $product->upc,
            'ean' => $product->ean,
            'mpn' => $product->mpn,
            'isbn' => $product->isbn,
            'country_of_origin' => $product->country_of_origin,
            'is_published' => $product->is_published,
            'track_quantity' => $product->track_quantity,
            'has_variants' => $product->has_variants,
            'category' => $product->category?->name,
            'category_path' => $product->category?->full_path ?? $product->category?->name,
            'brand' => $product->brand?->name,
            'vendor' => $product->vendor?->name,
            'return_policy' => $product->returnPolicy?->name,
            'seo_title' => $product->seo_page_title,
            'seo_description' => $product->seo_description,
            'created_at' => $product->created_at?->format('Y-m-d H:i:s'),
            'variants' => $product->variants->map(fn ($v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'price' => (float) $v->price,
                'cost' => (float) $v->cost,
                'quantity' => $v->quantity,
                'barcode' => $v->barcode,
            ])->toArray(),
        ];

        // Flatten template attributes
        // This allows access like product.ring_size, product.metal_type, etc.
        foreach ($product->attributeValues as $attributeValue) {
            if ($attributeValue->field) {
                // Use canonical_name if available, otherwise slugify the field name
                $key = $attributeValue->field->canonical_name
                    ?? $this->slugify($attributeValue->field->name);

                $data[$key] = $attributeValue->value;
            }
        }

        // Also add template_attributes as a nested array for iteration
        $data['template_attributes'] = $product->attributeValues
            ->filter(fn ($av) => $av->field)
            ->mapWithKeys(fn ($av) => [
                ($av->field->canonical_name ?? $this->slugify($av->field->name)) => [
                    'name' => $av->field->name,
                    'label' => $av->field->label ?? $av->field->name,
                    'value' => $av->value,
                    'type' => $av->field->type,
                ],
            ])
            ->toArray();

        return $data;
    }

    /**
     * Prepare transaction (buy) data.
     */
    public function prepareTransaction(Transaction|int $transaction): array
    {
        if (is_int($transaction)) {
            $transaction = Transaction::with([
                'customer',
                'items.product',
                'warehouse',
                'user',
                'payments',
                'offers',
            ])->find($transaction);
        } else {
            $transaction->loadMissing([
                'customer',
                'items.product',
                'warehouse',
                'user',
                'payments',
                'offers',
            ]);
        }

        if (! $transaction) {
            return [];
        }

        return [
            'id' => $transaction->id,
            'number' => $transaction->transaction_number ?? "TXN-{$transaction->id}",
            'status' => $transaction->status,
            'type' => $transaction->type,
            'sub_total' => (float) $transaction->sub_total,
            'total_value' => (float) $transaction->total_value,
            'total_paid' => (float) $transaction->total_paid,
            'balance_due' => (float) $transaction->balance_due,
            'item_count' => $transaction->items->count(),
            'notes' => $transaction->notes,
            'created_at' => $transaction->created_at?->format('Y-m-d H:i:s'),
            'customer' => $transaction->customer ? $this->prepareCustomer($transaction->customer) : null,
            'warehouse' => $transaction->warehouse ? $this->prepareWarehouse($transaction->warehouse) : null,
            'created_by' => $transaction->user ? [
                'name' => $transaction->user->name,
                'email' => $transaction->user->email,
            ] : null,
            'items' => $transaction->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'offered_price' => (float) $item->offered_price,
                'accepted_price' => (float) $item->accepted_price,
                'product' => $item->product ? $this->prepareProduct($item->product) : null,
            ])->toArray(),
            'current_offer' => $transaction->offers->last() ? [
                'amount' => (float) $transaction->offers->last()->amount,
                'status' => $transaction->offers->last()->status,
                'created_at' => $transaction->offers->last()->created_at?->format('Y-m-d H:i:s'),
            ] : null,
        ];
    }

    /**
     * Prepare memo (consignment) data.
     */
    public function prepareMemo(Memo|int $memo): array
    {
        if (is_int($memo)) {
            $memo = Memo::with([
                'vendor',
                'items.product',
                'warehouse',
                'user',
                'payments',
            ])->find($memo);
        } else {
            $memo->loadMissing([
                'vendor',
                'items.product',
                'warehouse',
                'user',
                'payments',
            ]);
        }

        if (! $memo) {
            return [];
        }

        return [
            'id' => $memo->id,
            'number' => $memo->memo_number ?? "MEM-{$memo->id}",
            'status' => $memo->status,
            'total_value' => (float) $memo->total_value,
            'total_paid' => (float) $memo->total_paid,
            'balance_due' => (float) $memo->balance_due,
            'item_count' => $memo->items->count(),
            'notes' => $memo->notes,
            'sent_at' => $memo->sent_at?->format('Y-m-d H:i:s'),
            'received_at' => $memo->received_at?->format('Y-m-d H:i:s'),
            'created_at' => $memo->created_at?->format('Y-m-d H:i:s'),
            'vendor' => $memo->vendor ? [
                'id' => $memo->vendor->id,
                'name' => $memo->vendor->name,
                'email' => $memo->vendor->email,
                'phone' => $memo->vendor->phone,
                'company' => $memo->vendor->company_name,
            ] : null,
            'warehouse' => $memo->warehouse ? $this->prepareWarehouse($memo->warehouse) : null,
            'created_by' => $memo->user ? [
                'name' => $memo->user->name,
                'email' => $memo->user->email,
            ] : null,
            'items' => $memo->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'price' => (float) $item->price,
                'total' => (float) ($item->price * $item->quantity),
                'product' => $item->product ? $this->prepareProduct($item->product) : null,
            ])->toArray(),
        ];
    }

    /**
     * Prepare repair data.
     */
    public function prepareRepair(Repair|int $repair): array
    {
        if (is_int($repair)) {
            $repair = Repair::with([
                'customer',
                'items',
                'warehouse',
                'user',
                'payments',
            ])->find($repair);
        } else {
            $repair->loadMissing([
                'customer',
                'items',
                'warehouse',
                'user',
                'payments',
            ]);
        }

        if (! $repair) {
            return [];
        }

        return [
            'id' => $repair->id,
            'number' => $repair->repair_number ?? "REP-{$repair->id}",
            'status' => $repair->status,
            'total_cost' => (float) $repair->total_cost,
            'total_paid' => (float) $repair->total_paid,
            'balance_due' => (float) $repair->balance_due,
            'estimated_completion' => $repair->estimated_completion_date?->format('Y-m-d'),
            'completed_at' => $repair->completed_at?->format('Y-m-d H:i:s'),
            'notes' => $repair->notes,
            'customer_notes' => $repair->customer_notes,
            'created_at' => $repair->created_at?->format('Y-m-d H:i:s'),
            'customer' => $repair->customer ? $this->prepareCustomer($repair->customer) : null,
            'warehouse' => $repair->warehouse ? $this->prepareWarehouse($repair->warehouse) : null,
            'created_by' => $repair->user ? [
                'name' => $repair->user->name,
                'email' => $repair->user->email,
            ] : null,
            'items' => $repair->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'service_type' => $item->service_type,
                'cost' => (float) $item->cost,
                'notes' => $item->notes,
            ])->toArray(),
        ];
    }

    /**
     * Prepare user data.
     */
    public function prepareUser(User|int $user): array
    {
        if (is_int($user)) {
            $user = User::find($user);
        }

        if (! $user) {
            return [];
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Prepare warehouse data.
     */
    public function prepareWarehouse(Warehouse|int $warehouse): array
    {
        if (is_int($warehouse)) {
            $warehouse = Warehouse::find($warehouse);
        }

        if (! $warehouse) {
            return [];
        }

        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'code' => $warehouse->code,
            'email' => $warehouse->email,
            'phone' => $warehouse->phone,
            'contact_name' => $warehouse->contact_name,
            'address' => $warehouse->address_line1,
            'address_line1' => $warehouse->address_line1,
            'address_line2' => $warehouse->address_line2,
            'city' => $warehouse->city,
            'state' => $warehouse->state,
            'postal_code' => $warehouse->postal_code,
            'zip' => $warehouse->postal_code,
            'country' => $warehouse->country,
            'full_address' => $warehouse->full_address,
            'is_default' => $warehouse->is_default,
            'tax_rate' => (float) $warehouse->tax_rate,
        ];
    }

    /**
     * Get comprehensive sample data for the template editor.
     */
    public function getSampleData(): array
    {
        return [
            'store' => [
                'id' => 1,
                'name' => 'My Jewelry Store',
                'business_name' => 'My Jewelry Store LLC',
                'email' => 'store@example.com',
                'customer_email' => 'support@example.com',
                'phone' => '(555) 123-4567',
                'address' => '123 Main Street',
                'address2' => 'Suite 100',
                'city' => 'New York',
                'state' => 'NY',
                'zip' => '10001',
                'full_address' => '123 Main Street, Suite 100, New York, NY, 10001',
                'url' => 'https://myjewelrystore.com',
                'domain' => 'myjewelrystore.com',
                'owner' => [
                    'name' => 'John Owner',
                    'email' => 'owner@example.com',
                ],
                'warehouse' => [
                    'id' => 1,
                    'name' => 'Main Store',
                    'code' => 'MAIN',
                    'email' => 'main@example.com',
                    'phone' => '(555) 123-4567',
                    'contact_name' => 'Store Manager',
                    'address' => '123 Main Street',
                    'address_line1' => '123 Main Street',
                    'address_line2' => 'Suite 100',
                    'city' => 'New York',
                    'state' => 'NY',
                    'postal_code' => '10001',
                    'zip' => '10001',
                    'country' => 'United States',
                    'full_address' => '123 Main Street, Suite 100, New York, NY, 10001, United States',
                    'is_default' => true,
                    'tax_rate' => 8.875,
                ],
                'default_warehouse' => [
                    'name' => 'Main Store',
                    'full_address' => '123 Main Street, Suite 100, New York, NY, 10001',
                ],
            ],
            'order' => [
                'id' => 12345,
                'number' => 'ORD-20240115-012345',
                'invoice_number' => 'INV-20240115-012345',
                'status' => 'confirmed',
                'sub_total' => 1250.00,
                'total' => 1360.94,
                'sales_tax' => 110.94,
                'tax_rate' => 8.875,
                'shipping_cost' => 0,
                'discount_cost' => 0,
                'trade_in_credit' => 0,
                'service_fee' => 0,
                'total_paid' => 1360.94,
                'balance_due' => 0,
                'item_count' => 2,
                'date_of_purchase' => '2024-01-15',
                'created_at' => '2024-01-15 14:30:00',
                'source_platform' => null,
                'notes' => 'Please gift wrap',
                'tracking_number' => '1Z999AA10123456784',
                'billing_address' => [
                    'name' => 'Jane Doe',
                    'address' => '456 Oak Avenue',
                    'city' => 'Brooklyn',
                    'state' => 'NY',
                    'zip' => '11201',
                    'country' => 'United States',
                    'phone' => '(555) 987-6543',
                    'full_address' => '456 Oak Avenue, Brooklyn, NY 11201',
                ],
                'shipping_address' => [
                    'name' => 'Jane Doe',
                    'address' => '456 Oak Avenue',
                    'city' => 'Brooklyn',
                    'state' => 'NY',
                    'zip' => '11201',
                    'country' => 'United States',
                    'phone' => '(555) 987-6543',
                    'full_address' => '456 Oak Avenue, Brooklyn, NY 11201',
                ],
                'items' => [
                    [
                        'id' => 1,
                        'name' => '14K Gold Diamond Ring',
                        'sku' => 'RING-14K-DIA-001',
                        'quantity' => 1,
                        'price' => 999.00,
                        'total' => 999.00,
                    ],
                    [
                        'id' => 2,
                        'name' => 'Sterling Silver Necklace',
                        'sku' => 'NECK-SS-001',
                        'quantity' => 1,
                        'price' => 251.00,
                        'total' => 251.00,
                    ],
                ],
                'payments' => [
                    [
                        'id' => 1,
                        'amount' => 1360.94,
                        'method' => 'credit_card',
                        'status' => 'completed',
                        'reference' => 'ch_1234567890',
                        'created_at' => '2024-01-15 14:32:00',
                    ],
                ],
                'has_trade_in' => false,
            ],
            'customer' => [
                'id' => 100,
                'name' => 'Jane Doe',
                'full_name' => 'Jane Doe',
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'jane.doe@example.com',
                'phone' => '(555) 987-6543',
                'phone_number' => '(555) 987-6543',
                'company_name' => 'Doe Enterprises',
                'address' => '456 Oak Avenue',
                'address2' => 'Apt 2B',
                'city' => 'Brooklyn',
                'zip' => '11201',
                'full_address' => '456 Oak Avenue, Apt 2B, Brooklyn, 11201',
                'accepts_marketing' => true,
                'lead_source' => 'Website',
                'number_of_sales' => 5,
                'number_of_buys' => 2,
                'last_sales_date' => '2024-01-15',
                'created_at' => '2023-06-15 10:00:00',
            ],
            'product' => [
                'id' => 500,
                'title' => '14K Gold Diamond Ring',
                'description' => 'Beautiful 14K gold ring with 0.5 carat diamond center stone.',
                'handle' => '14k-gold-diamond-ring',
                'sku' => 'RING-14K-DIA-001',
                'price' => 999.00,
                'compare_at_price' => 1299.00,
                'cost' => 450.00,
                'wholesale_price' => 650.00,
                'quantity' => 3,
                'weight' => 5.2,
                'weight_unit' => 'g',
                'category' => 'Rings',
                'category_path' => 'Jewelry > Rings > Diamond Rings',
                'brand' => 'House Collection',
                'vendor' => 'Diamond Supply Co',
                'is_published' => true,
                // Flattened template attributes - accessible directly
                'ring_size' => '7',
                'metal_type' => '14K Yellow Gold',
                'metal_weight' => '4.5g',
                'center_stone' => 'Diamond',
                'center_stone_weight' => '0.50ct',
                'center_stone_clarity' => 'VS1',
                'center_stone_color' => 'G',
                'setting_type' => 'Prong',
                'certification' => 'GIA Certified',
                'template_attributes' => [
                    'ring_size' => ['name' => 'Ring Size', 'label' => 'Ring Size', 'value' => '7', 'type' => 'select'],
                    'metal_type' => ['name' => 'Metal Type', 'label' => 'Metal Type', 'value' => '14K Yellow Gold', 'type' => 'select'],
                    'metal_weight' => ['name' => 'Metal Weight', 'label' => 'Metal Weight', 'value' => '4.5g', 'type' => 'text'],
                    'center_stone' => ['name' => 'Center Stone', 'label' => 'Center Stone', 'value' => 'Diamond', 'type' => 'select'],
                    'center_stone_weight' => ['name' => 'Center Stone Weight', 'label' => 'Carat Weight', 'value' => '0.50ct', 'type' => 'text'],
                ],
                'variants' => [
                    ['id' => 1, 'sku' => 'RING-14K-DIA-001', 'price' => 999.00, 'cost' => 450.00, 'quantity' => 3],
                ],
            ],
            'transaction' => [
                'id' => 200,
                'number' => 'TXN-20240115-000200',
                'status' => 'offer_accepted',
                'type' => 'buy',
                'sub_total' => 500.00,
                'total_value' => 500.00,
                'total_paid' => 500.00,
                'balance_due' => 0,
                'item_count' => 2,
                'notes' => 'Customer brought in estate jewelry',
                'created_at' => '2024-01-15 11:00:00',
                'items' => [
                    ['id' => 1, 'description' => 'Gold Chain 18"', 'quantity' => 1, 'offered_price' => 200.00, 'accepted_price' => 200.00],
                    ['id' => 2, 'description' => 'Silver Bracelet', 'quantity' => 1, 'offered_price' => 300.00, 'accepted_price' => 300.00],
                ],
                'current_offer' => [
                    'amount' => 500.00,
                    'status' => 'accepted',
                    'created_at' => '2024-01-15 11:15:00',
                ],
            ],
            'memo' => [
                'id' => 50,
                'number' => 'MEM-20240115-000050',
                'status' => 'sent_to_vendor',
                'total_value' => 5000.00,
                'total_paid' => 0,
                'balance_due' => 5000.00,
                'item_count' => 3,
                'notes' => 'Consignment items for spring collection',
                'sent_at' => '2024-01-15 09:00:00',
                'created_at' => '2024-01-14 16:00:00',
                'vendor' => [
                    'id' => 10,
                    'name' => 'ABC Jewelry Supplier',
                    'email' => 'vendor@abcjewelry.com',
                    'phone' => '(555) 555-5555',
                    'company' => 'ABC Jewelry Inc.',
                ],
                'items' => [
                    ['id' => 1, 'description' => 'Diamond Tennis Bracelet', 'quantity' => 1, 'price' => 2500.00, 'total' => 2500.00],
                    ['id' => 2, 'description' => 'Pearl Earrings', 'quantity' => 2, 'price' => 750.00, 'total' => 1500.00],
                    ['id' => 3, 'description' => 'Gold Pendant', 'quantity' => 1, 'price' => 1000.00, 'total' => 1000.00],
                ],
            ],
            'repair' => [
                'id' => 75,
                'number' => 'REP-20240115-000075',
                'status' => 'completed',
                'total_cost' => 150.00,
                'total_paid' => 150.00,
                'balance_due' => 0,
                'estimated_completion' => '2024-01-20',
                'completed_at' => '2024-01-19 15:00:00',
                'notes' => 'Ring sizing and cleaning',
                'customer_notes' => 'Please call when ready',
                'created_at' => '2024-01-15 10:00:00',
                'items' => [
                    ['id' => 1, 'description' => 'Ring Sizing (2 sizes up)', 'service_type' => 'sizing', 'cost' => 75.00],
                    ['id' => 2, 'description' => 'Professional Cleaning', 'service_type' => 'cleaning', 'cost' => 25.00],
                    ['id' => 3, 'description' => 'Rhodium Plating', 'service_type' => 'plating', 'cost' => 50.00],
                ],
            ],
            'user' => [
                'id' => 1,
                'name' => 'Store Associate',
                'email' => 'associate@example.com',
                'created_at' => '2023-01-01 09:00:00',
            ],
            'warehouse' => [
                'id' => 1,
                'name' => 'Main Store',
                'code' => 'MAIN',
                'email' => 'main@example.com',
                'phone' => '(555) 123-4567',
                'contact_name' => 'Store Manager',
                'address' => '123 Main Street',
                'address_line1' => '123 Main Street',
                'address_line2' => 'Suite 100',
                'city' => 'New York',
                'state' => 'NY',
                'postal_code' => '10001',
                'zip' => '10001',
                'country' => 'United States',
                'full_address' => '123 Main Street, Suite 100, New York, NY, 10001, United States',
                'is_default' => true,
                'tax_rate' => 8.875,
            ],
            'role' => [
                'name' => 'Manager',
            ],
            'invite_url' => 'https://myjewelrystore.com/invitations/accept/abc123',
        ];
    }

    /**
     * Get available variable groups for the template editor.
     */
    public function getAvailableVariables(): array
    {
        return [
            'store' => [
                'store.name',
                'store.business_name',
                'store.email',
                'store.phone',
                'store.address',
                'store.city',
                'store.state',
                'store.zip',
                'store.full_address',
                'store.url',
                'store.owner.name',
                'store.owner.email',
                'store.warehouse.name',
                'store.warehouse.full_address',
                'store.warehouse.phone',
            ],
            'order' => [
                'order.number',
                'order.invoice_number',
                'order.status',
                'order.total',
                'order.sub_total',
                'order.sales_tax',
                'order.shipping_cost',
                'order.discount_cost',
                'order.total_paid',
                'order.balance_due',
                'order.item_count',
                'order.tracking_number',
                'order.notes',
                'order.created_at',
                'order.billing_address.full_address',
                'order.shipping_address.full_address',
                'order.items',
            ],
            'customer' => [
                'customer.name',
                'customer.first_name',
                'customer.last_name',
                'customer.email',
                'customer.phone',
                'customer.company_name',
                'customer.address',
                'customer.city',
                'customer.zip',
                'customer.full_address',
            ],
            'product' => [
                'product.title',
                'product.description',
                'product.sku',
                'product.price',
                'product.quantity',
                'product.category',
                'product.brand',
                'product.vendor',
                '-- Template Attributes --',
                'product.ring_size',
                'product.metal_type',
                'product.center_stone',
                'product.{any_template_field}',
            ],
            'transaction' => [
                'transaction.number',
                'transaction.status',
                'transaction.total_value',
                'transaction.total_paid',
                'transaction.balance_due',
                'transaction.notes',
                'transaction.items',
            ],
            'memo' => [
                'memo.number',
                'memo.status',
                'memo.total_value',
                'memo.vendor.name',
                'memo.vendor.email',
                'memo.items',
            ],
            'repair' => [
                'repair.number',
                'repair.status',
                'repair.total_cost',
                'repair.estimated_completion',
                'repair.notes',
                'repair.items',
            ],
            'user' => [
                'user.name',
                'user.email',
            ],
        ];
    }

    /**
     * Format an address array into a structured format.
     */
    protected function formatAddress(?array $address): ?array
    {
        if (! $address) {
            return null;
        }

        return [
            'name' => $address['name'] ?? $address['full_name'] ?? null,
            'address' => $address['address'] ?? $address['address_line1'] ?? $address['street'] ?? null,
            'address2' => $address['address2'] ?? $address['address_line2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? $address['province'] ?? null,
            'zip' => $address['zip'] ?? $address['postal_code'] ?? null,
            'country' => $address['country'] ?? null,
            'phone' => $address['phone'] ?? null,
            'email' => $address['email'] ?? null,
            'full_address' => implode(', ', array_filter([
                $address['address'] ?? $address['address_line1'] ?? null,
                $address['city'] ?? null,
                $address['state'] ?? null,
                $address['zip'] ?? $address['postal_code'] ?? null,
            ])),
        ];
    }

    /**
     * Convert a string to a slug format for template attribute keys.
     */
    protected function slugify(string $text): string
    {
        // Convert to lowercase and replace spaces/special chars with underscores
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);
        $text = trim($text, '_');

        return $text;
    }
}
