<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderFromWizardRequest;
use App\Models\Address;
use App\Models\Bucket;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PlatformOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingLabel;
use App\Models\State;
use App\Models\StoreMarketplace;
use App\Models\TransactionItem;
use App\Models\Warehouse;
use App\Services\ActivityLogFormatter;
use App\Services\Orders\OrderCreationService;
use App\Services\StoreContext;
use App\Services\TaxService;
use App\Services\TradeIn\TradeInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected OrderCreationService $orderCreationService,
        protected TaxService $taxService,
        protected TradeInService $tradeInService,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        // Get distinct marketplaces from orders
        $marketplaces = Order::where('store_id', $store->id)
            ->whereNotNull('source_platform')
            ->distinct()
            ->pluck('source_platform')
            ->map(fn ($platform) => [
                'value' => $platform,
                'label' => $this->formatMarketplaceLabel($platform),
            ])
            ->values()
            ->toArray();

        // Get vendors that have products sold in orders
        $vendors = \App\Models\Vendor::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($vendor) => [
                'value' => $vendor->id,
                'label' => $vendor->name,
            ])
            ->toArray();

        return Inertia::render('orders/Index', [
            'statuses' => $this->getStatuses(),
            'marketplaces' => $marketplaces,
            'paymentMethods' => $this->getPaymentMethods(),
            'vendors' => $vendors,
        ]);
    }

    /**
     * Format marketplace label for display.
     */
    protected function formatMarketplaceLabel(string $platform): string
    {
        $labels = [
            'pos' => 'In Store',
            'in_store' => 'In Store',
            'shopify' => 'Shopify',
            'reb' => 'REB',
            'memo' => 'Memo',
            'repair' => 'Repair',
            'website' => 'Website',
            'online' => 'Online',
        ];

        return $labels[$platform] ?? ucfirst(str_replace('_', ' ', $platform));
    }

    public function show(Order $order): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $order->store_id !== $store->id) {
            abort(404);
        }

        $order->load([
            'customer.leadSource',
            'user',
            'warehouse',
            'salesChannel.storeMarketplace',
            'platformOrder.marketplace',
            'items.product.images',
            'items.variant',
            'invoice',
            'payments.user',
            'returns',
            'tradeInTransaction.items',
            'notes.user',
        ]);

        // Check if FedEx and ShipStation are configured
        $fedexConfigured = app(\App\Services\Shipping\ShippingLabelService::class)->isConfigured($store);
        $shipstationConfigured = \App\Services\ShipStation\ShipStationService::forStore($store->id)->isConfigured();

        return Inertia::render('orders/Show', [
            'order' => $this->formatOrder($order),
            'statuses' => $this->getStatuses(),
            'paymentMethods' => $this->getPaymentMethods(),
            'activityLogs' => Inertia::defer(fn () => app(ActivityLogFormatter::class)->formatForSubject($order)),
            'fedexConfigured' => $fedexConfigured,
            'shipstationConfigured' => $shipstationConfigured,
        ]);
    }

    public function printInvoice(Order $order): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $order->store_id !== $store->id) {
            abort(404);
        }

        $order->load([
            'customer.defaultAddress.state',
            'customer.addresses.state',
            'user',
            'warehouse',
            'items.product.images',
            'items.product.category',
            'items.variant',
            'payments.user',
        ]);

        // Generate barcode
        $barcode = null;
        if ($order->invoice_number || $order->order_id) {
            $barcodeNumber = $order->invoice_number ?? $order->order_id;
            $generator = new \Picqer\Barcode\BarcodeGeneratorPNG;
            $barcode = 'data:image/png;base64,'.base64_encode($generator->getBarcode($barcodeNumber, $generator::TYPE_CODE_128));
        }

        return Inertia::render('orders/PrintInvoice', [
            'order' => [
                'id' => $order->id,
                'order_id' => $order->order_id,
                'invoice_number' => $order->invoice_number,
                'status' => $order->status,
                'sub_total' => $order->sub_total,
                'sales_tax' => $order->sales_tax,
                'tax_rate' => $order->tax_rate,
                'shipping_cost' => $order->shipping_cost,
                'discount_cost' => $order->discount_cost,
                'trade_in_credit' => $order->trade_in_credit ?? 0,
                'total' => $order->total,
                'total_paid' => $order->total_paid,
                'balance_due' => $order->balance_due,
                'notes' => $order->notes,
                'date_of_purchase' => $order->date_of_purchase?->toISOString(),
                'created_at' => $order->created_at->toISOString(),
                'shipping_address' => $order->shipping_address,
                'billing_address' => $order->billing_address,
                'customer' => $order->customer ? (function () use ($order) {
                    // Try default address first, then fall back to any address
                    $customerAddress = $order->customer->defaultAddress
                        ?? $order->customer->addresses->first();

                    return [
                        'id' => $order->customer->id,
                        'full_name' => $order->customer->full_name,
                        'company_name' => $customerAddress?->company ?? $order->customer->company_name,
                        'email' => $order->customer->email,
                        'phone' => $customerAddress?->phone ?? $order->customer->phone_number,
                        'address' => $customerAddress?->address ?? $order->customer->address,
                        'address2' => $customerAddress?->address2 ?? $order->customer->address2,
                        'city' => $customerAddress?->city ?? $order->customer->city,
                        'state' => $customerAddress?->state_abbreviation ?? $customerAddress?->state?->name ?? $order->customer->state,
                        'zip' => $customerAddress?->zip ?? $order->customer->zip,
                    ];
                })() : null,
                'user' => $order->user ? [
                    'id' => $order->user->id,
                    'name' => $order->user->name,
                ] : null,
                'warehouse' => $order->warehouse ? [
                    'id' => $order->warehouse->id,
                    'name' => $order->warehouse->name,
                ] : null,
                'service_fee_value' => $order->service_fee_value,
                'service_fee_unit' => $order->service_fee_unit,
                'items' => $order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'sku' => $item->sku,
                    'title' => $item->title,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'discount' => $item->discount,
                    'tax' => $item->tax,
                    'line_total' => $item->line_total,
                    'category' => $item->product?->category?->name,
                    'product' => $item->product ? [
                        'id' => $item->product->id,
                        'title' => $item->product->title,
                        'images' => $item->product->images->map(fn ($image) => [
                            'url' => $image->url,
                        ]),
                    ] : null,
                ]),
                'payments' => $order->payments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'status' => $payment->status,
                    'reference' => $payment->reference,
                    'paid_at' => $payment->paid_at?->toISOString(),
                ]),
            ],
            'store' => [
                'name' => $store->business_name ?? $store->name,
                'logo' => $store->logo ? Storage::disk('do_spaces')->url($store->logo) : null,
                'address' => $store->address,
                'address2' => $store->address2,
                'city' => $store->city,
                'state' => $store->state,
                'zip' => $store->zip,
                'phone' => $store->phone,
                'email' => $store->email,
            ],
            'barcode' => $barcode,
        ]);
    }

    public function createWizard(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        // Get store users for the employee dropdown
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

        // Get categories for filtering products (simple format for product search)
        $categories = Category::where('store_id', $store->id)
            ->orderBy('name')
            ->get()
            ->map(fn ($category) => [
                'value' => $category->id,
                'label' => $category->name,
            ]);

        // Get categories with full tree structure for AddItemModal (used in trade-ins)
        $tradeInCategories = Category::where('store_id', $store->id)
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

        // Get warehouses for the warehouse dropdown
        $warehouses = Warehouse::where('store_id', $store->id)
            ->orderBy('name')
            ->get()
            ->map(fn ($warehouse) => [
                'value' => $warehouse->id,
                'label' => $warehouse->name,
                'tax_rate' => $warehouse->tax_rate,
            ]);

        // Get the current user's default warehouse ID
        $currentStoreUser = auth()->user()?->currentStoreUser();
        $defaultWarehouseId = $currentStoreUser?->default_warehouse_id;

        return Inertia::render('orders/CreateWizard', [
            'storeUsers' => $storeUsers,
            'currentStoreUserId' => $currentStoreUserId,
            'categories' => $categories,
            'tradeInCategories' => $tradeInCategories,
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $defaultWarehouseId,
            'defaultTaxRate' => $store->default_tax_rate ?? 0,
            'preciousMetals' => $this->getPreciousMetals(),
            'itemConditions' => $this->getItemConditions(),
        ]);
    }

    public function storeFromWizard(CreateOrderFromWizardRequest $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $data = $request->validated();

        $order = $this->orderCreationService->createFromWizard($data, $store);

        return redirect()->route('web.orders.show', $order)
            ->with('success', 'Order created successfully.');
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        $validated = $request->validate([
            'notes' => 'nullable|string|max:5000',
            'shipping_cost' => 'nullable|numeric|min:0',
            'discount_cost' => 'nullable|numeric|min:0',
            'date_of_purchase' => 'nullable|date',
        ]);

        $order->update($validated);
        $order->calculateTotals();

        return back()->with('success', 'Order updated successfully.');
    }

    public function updateCustomer(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
        ]);

        // Verify customer belongs to the same store
        $customer = Customer::where('id', $validated['customer_id'])
            ->where('store_id', $order->store_id)
            ->first();

        if (! $customer) {
            return back()->with('error', 'Customer not found.');
        }

        $order->update(['customer_id' => $customer->id]);

        return back()->with('success', 'Customer updated successfully.');
    }

    public function destroy(Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        if (! $order->isPending() && $order->status !== Order::STATUS_DRAFT) {
            return back()->with('error', 'Only pending or draft orders can be deleted.');
        }

        // Restore stock for all items before deleting
        foreach ($order->items as $item) {
            if ($item->product_variant_id) {
                $this->restoreStock($item->product_variant_id, $item->quantity);
            }
        }

        $order->delete();

        return redirect()->route('web.orders.index')
            ->with('success', 'Order deleted successfully.');
    }

    // Status Transition Methods

    public function confirm(Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        if (! $order->isPending() && $order->status !== Order::STATUS_PARTIAL_PAYMENT) {
            return back()->with('error', 'Order cannot be confirmed in its current state.');
        }

        $order->confirm();

        return back()->with('success', 'Order confirmed.');
    }

    public function syncFromMarketplace(Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        $order->load(['platformOrder.marketplace', 'salesChannel.storeMarketplace']);

        // If there's already a platform order, use it
        if ($order->platformOrder) {
            $marketplace = $order->platformOrder->marketplace;

            if (! $marketplace) {
                return back()->with('error', 'Marketplace connection not found.');
            }

            try {
                $platformService = match ($marketplace->platform->value) {
                    'shopify' => app(\App\Services\Platforms\Shopify\ShopifyService::class),
                    default => throw new \Exception("Platform '{$marketplace->platform->value}' sync not supported."),
                };

                $platformService->refreshOrder($order->platformOrder);

                // Reload the platform order to get updated platform_data
                $order->platformOrder->refresh();

                // Update order and customer from the synced platform data
                if ($order->platformOrder->platform_data) {
                    $this->updateOrderFromPlatformData($order, $order->platformOrder->platform_data);
                }

                return back()->with('success', 'Order synced from '.$marketplace->platform->label().'.');
            } catch (\Throwable $e) {
                return back()->with('error', 'Failed to sync order: '.$e->getMessage());
            }
        }

        // Try to fetch and create platform order from external_marketplace_id
        if (! $order->external_marketplace_id) {
            return back()->with('error', 'This order is not linked to an external platform.');
        }

        // Get marketplace from sales channel or find by platform
        $marketplace = $order->salesChannel?->storeMarketplace;

        if (! $marketplace && $order->source_platform) {
            $marketplace = StoreMarketplace::where('store_id', $order->store_id)
                ->where('platform', $order->source_platform)
                ->where('status', 'active')
                ->first();
        }

        if (! $marketplace) {
            return back()->with('error', 'No marketplace connection found for this order.');
        }

        try {
            $platformService = match ($marketplace->platform->value) {
                'shopify' => app(\App\Services\Platforms\Shopify\ShopifyService::class),
                default => throw new \Exception("Platform '{$marketplace->platform->value}' sync not supported."),
            };

            // Fetch the order from Shopify and create platform order
            $platformOrder = $this->fetchAndCreatePlatformOrder(
                $order,
                $marketplace,
                $platformService
            );

            return back()->with('success', 'Order synced from '.$marketplace->platform->label().'.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to sync order: '.$e->getMessage());
        }
    }

    /**
     * Fetch order from platform and create/update platform order record.
     */
    protected function fetchAndCreatePlatformOrder(
        Order $order,
        StoreMarketplace $marketplace,
        \App\Services\Platforms\Shopify\ShopifyService $platformService
    ): PlatformOrder {
        // Use the external_marketplace_id to fetch from Shopify
        $shopifyOrder = $this->fetchShopifyOrderById($marketplace, $order->external_marketplace_id);

        if (! $shopifyOrder) {
            throw new \Exception('Order not found on the platform.');
        }

        // Create or update platform order
        $platformOrder = PlatformOrder::updateOrCreate(
            [
                'store_marketplace_id' => $marketplace->id,
                'external_order_id' => $shopifyOrder['id'],
            ],
            [
                'order_id' => $order->id,
                'external_order_number' => $shopifyOrder['order_number'] ?? $shopifyOrder['name'] ?? null,
                'status' => $shopifyOrder['financial_status'] ?? null,
                'fulfillment_status' => $shopifyOrder['fulfillment_status'] ?? null,
                'payment_status' => $shopifyOrder['financial_status'] ?? null,
                'total' => $shopifyOrder['total_price'] ?? 0,
                'subtotal' => $shopifyOrder['subtotal_price'] ?? 0,
                'shipping_cost' => collect($shopifyOrder['shipping_lines'] ?? [])->sum('price'),
                'tax' => $shopifyOrder['total_tax'] ?? 0,
                'discount' => collect($shopifyOrder['discount_codes'] ?? [])->sum('amount'),
                'currency' => $shopifyOrder['currency'] ?? 'USD',
                'customer_data' => $shopifyOrder['customer'] ?? null,
                'shipping_address' => $shopifyOrder['shipping_address'] ?? null,
                'billing_address' => $shopifyOrder['billing_address'] ?? null,
                'line_items' => $shopifyOrder['line_items'] ?? [],
                'platform_data' => $shopifyOrder,
                'ordered_at' => isset($shopifyOrder['created_at']) ? \Carbon\Carbon::parse($shopifyOrder['created_at']) : null,
                'last_synced_at' => now(),
            ]
        );

        // Update order fields from platform data
        $this->updateOrderFromPlatformData($order, $shopifyOrder);

        return $platformOrder;
    }

    /**
     * Fetch a Shopify order by ID.
     */
    protected function fetchShopifyOrderById(StoreMarketplace $marketplace, string $orderId): ?array
    {
        $apiVersion = '2024-01';
        $url = "https://{$marketplace->shop_domain}/admin/api/{$apiVersion}/orders/{$orderId}.json";

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'X-Shopify-Access-Token' => $marketplace->access_token,
            'Content-Type' => 'application/json',
        ])->get($url);

        if ($response->failed()) {
            return null;
        }

        return $response->json()['order'] ?? null;
    }

    /**
     * Update local order fields from platform data.
     */
    protected function updateOrderFromPlatformData(Order $order, array $platformData): void
    {
        $updates = [];

        // Update shipping address if we have it from platform
        if (! empty($platformData['shipping_address'])) {
            $shippingAddr = $platformData['shipping_address'];
            $updates['shipping_address'] = [
                'name' => trim(($shippingAddr['first_name'] ?? '').' '.($shippingAddr['last_name'] ?? '')),
                'company' => $shippingAddr['company'] ?? null,
                'address_line_1' => $shippingAddr['address1'] ?? null,
                'address_line_2' => $shippingAddr['address2'] ?? null,
                'city' => $shippingAddr['city'] ?? null,
                'state' => $shippingAddr['province_code'] ?? $shippingAddr['province'] ?? null,
                'postal_code' => $shippingAddr['zip'] ?? null,
                'country' => $shippingAddr['country_code'] ?? $shippingAddr['country'] ?? null,
                'phone' => $shippingAddr['phone'] ?? null,
            ];
        }

        // Update billing address if we have it
        if (! empty($platformData['billing_address'])) {
            $billingAddr = $platformData['billing_address'];
            $updates['billing_address'] = [
                'name' => trim(($billingAddr['first_name'] ?? '').' '.($billingAddr['last_name'] ?? '')),
                'company' => $billingAddr['company'] ?? null,
                'address_line_1' => $billingAddr['address1'] ?? null,
                'address_line_2' => $billingAddr['address2'] ?? null,
                'city' => $billingAddr['city'] ?? null,
                'state' => $billingAddr['province_code'] ?? $billingAddr['province'] ?? null,
                'postal_code' => $billingAddr['zip'] ?? null,
                'country' => $billingAddr['country_code'] ?? $billingAddr['country'] ?? null,
                'phone' => $billingAddr['phone'] ?? null,
            ];
        }

        // Update fulfillment/shipping info from fulfillments
        if (! empty($platformData['fulfillments'])) {
            $latestFulfillment = collect($platformData['fulfillments'])->last();
            if ($latestFulfillment) {
                // Update tracking number
                if (! empty($latestFulfillment['tracking_number'])) {
                    $updates['tracking_number'] = $latestFulfillment['tracking_number'];
                }
                // Update shipping carrier
                if (! empty($latestFulfillment['tracking_company'])) {
                    $carrier = strtolower($latestFulfillment['tracking_company']);
                    $updates['shipping_carrier'] = match (true) {
                        str_contains($carrier, 'fedex') => 'fedex',
                        str_contains($carrier, 'ups') => 'ups',
                        str_contains($carrier, 'usps') => 'usps',
                        str_contains($carrier, 'dhl') => 'dhl',
                        default => 'other',
                    };
                }
                // Update shipped_at timestamp
                if ($latestFulfillment['status'] === 'success' && ! $order->shipped_at) {
                    $updates['shipped_at'] = isset($latestFulfillment['created_at'])
                        ? \Carbon\Carbon::parse($latestFulfillment['created_at'])
                        : now();
                }
            }
        }

        // Sync order status from Shopify
        $newStatus = $this->mapShopifyStatusToLocal($platformData, $order);
        if ($newStatus && $newStatus !== $order->status) {
            $updates['status'] = $newStatus;
        }

        if (! empty($updates)) {
            $order->update($updates);
        }

        // Update customer with address information from platform
        $this->updateCustomerFromPlatformData($order, $platformData);
    }

    /**
     * Update customer record with data from platform.
     */
    protected function updateCustomerFromPlatformData(Order $order, array $platformData): void
    {
        if (! $order->customer_id) {
            return;
        }

        $customer = $order->customer;
        if (! $customer) {
            return;
        }

        $customerUpdates = [];

        // Get customer data from platform
        $platformCustomer = $platformData['customer'] ?? null;

        // Use shipping address as the primary source for customer address
        $addressSource = $platformData['shipping_address'] ?? $platformCustomer['default_address'] ?? null;

        // Create address record in addresses table if we have address data
        if ($addressSource && ! empty($addressSource['address1'])) {
            $this->createOrUpdateCustomerAddress($customer, $addressSource, $order->store_id);
        }

        // Update basic customer info (phone, email) if missing
        if (empty($customer->phone_number)) {
            $phone = $addressSource['phone'] ?? $platformCustomer['phone'] ?? $platformData['phone'] ?? null;
            if ($phone) {
                $customerUpdates['phone_number'] = $phone;
            }
        }

        if (empty($customer->company_name) && ! empty($addressSource['company'])) {
            $customerUpdates['company_name'] = $addressSource['company'];
        }

        // Update email if missing
        if (empty($customer->email)) {
            $email = $platformCustomer['email'] ?? $platformData['email'] ?? $platformData['contact_email'] ?? null;
            if ($email) {
                $customerUpdates['email'] = $email;
            }
        }

        if (! empty($customerUpdates)) {
            $customer->update($customerUpdates);
        }
    }

    /**
     * Create or update customer address from platform data.
     */
    protected function createOrUpdateCustomerAddress(Customer $customer, array $addressSource, int $storeId): void
    {
        $address1 = $addressSource['address1'] ?? null;
        $city = $addressSource['city'] ?? null;
        $zip = $addressSource['zip'] ?? null;

        if (! $address1 || ! $city) {
            return;
        }

        // Check if this address already exists for the customer
        $existingAddress = $customer->addresses()
            ->where('address', $address1)
            ->where('city', $city)
            ->where('zip', $zip)
            ->first();

        if ($existingAddress) {
            // Address already exists, no need to create
            return;
        }

        $firstName = $addressSource['first_name'] ?? null;
        $lastName = $addressSource['last_name'] ?? null;

        // Look up state ID from abbreviation or name
        $stateCode = $addressSource['province_code'] ?? null;
        $stateName = $addressSource['province'] ?? null;
        $countryCode = $addressSource['country_code'] ?? 'US';
        $stateId = null;

        if ($stateCode) {
            $stateId = State::where('abbreviation', $stateCode)
                ->where('country_code', $countryCode)
                ->value('id');
        }

        if (! $stateId && $stateName) {
            $stateId = State::where('name', $stateName)
                ->where('country_code', $countryCode)
                ->value('id');
        }

        // Determine if this should be the default address
        $isDefault = $customer->addresses()->count() === 0;

        $customer->addresses()->create([
            'store_id' => $storeId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company' => $addressSource['company'] ?? null,
            'address' => $address1,
            'address2' => $addressSource['address2'] ?? null,
            'city' => $city,
            'state_id' => $stateId,
            'zip' => $zip,
            'phone' => $addressSource['phone'] ?? null,
            'type' => 'shipping',
            'is_default' => $isDefault,
            'is_shipping' => true,
            'is_billing' => false,
        ]);
    }

    /**
     * Map Shopify order status to local order status.
     */
    protected function mapShopifyStatusToLocal(array $platformData, Order $order): ?string
    {
        $financialStatus = $platformData['financial_status'] ?? null;
        $fulfillmentStatus = $platformData['fulfillment_status'] ?? null;
        $cancelledAt = $platformData['cancelled_at'] ?? null;
        $closedAt = $platformData['closed_at'] ?? null;

        // Check if shipment has been delivered
        $isDelivered = $this->isShipmentDelivered($platformData);

        // Handle cancelled orders
        if ($cancelledAt) {
            return Order::STATUS_CANCELLED;
        }

        // Handle refunded orders
        if ($financialStatus === 'refunded') {
            return Order::STATUS_REFUNDED;
        }

        // Handle delivered orders - mark as completed
        if ($isDelivered) {
            return Order::STATUS_COMPLETED;
        }

        // Handle closed/completed orders (fulfilled and closed in Shopify)
        if ($closedAt && $fulfillmentStatus === 'fulfilled') {
            return Order::STATUS_COMPLETED;
        }

        // Handle fulfilled orders (shipped but not yet delivered)
        if ($fulfillmentStatus === 'fulfilled') {
            // If already completed, don't downgrade
            if ($order->status === Order::STATUS_COMPLETED) {
                return null;
            }

            return Order::STATUS_SHIPPED;
        }

        // Handle partially fulfilled orders
        if ($fulfillmentStatus === 'partial') {
            // Mark as processing if not already further along
            if (in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_CONFIRMED])) {
                return Order::STATUS_PROCESSING;
            }

            return null;
        }

        // Handle payment status
        if ($financialStatus === 'paid') {
            // If order is pending or draft, mark as confirmed
            if (in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_DRAFT])) {
                return Order::STATUS_CONFIRMED;
            }
        }

        if ($financialStatus === 'partially_paid') {
            if (in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_DRAFT])) {
                return Order::STATUS_PARTIAL_PAYMENT;
            }
        }

        // No status change needed
        return null;
    }

    /**
     * Check if shipment has been delivered based on fulfillment data.
     */
    protected function isShipmentDelivered(array $platformData): bool
    {
        $fulfillments = $platformData['fulfillments'] ?? [];

        foreach ($fulfillments as $fulfillment) {
            $shipmentStatus = $fulfillment['shipment_status'] ?? null;
            if ($shipmentStatus === 'delivered') {
                return true;
            }
        }

        return false;
    }

    public function ship(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $this->authorizeOrder($order);

        if (! $order->isConfirmed() && $order->status !== Order::STATUS_PROCESSING) {
            if ($request->wantsJson()) {
                return response()->json(['error' => 'Order cannot be shipped in its current state.'], 422);
            }

            return back()->with('error', 'Order cannot be shipped in its current state.');
        }

        $validated = $request->validate([
            'tracking_number' => 'nullable|string|max:255',
            'carrier' => 'nullable|string|in:fedex,ups,usps,dhl,other',
        ]);

        $trackingNumber = $validated['tracking_number'] ?? null;
        $carrier = $validated['carrier'] ?? null;

        // Create a ShippingLabel record for manual shipments with tracking
        if ($trackingNumber) {
            $order->shippingLabels()->create([
                'store_id' => $order->store_id,
                'type' => ShippingLabel::TYPE_OUTBOUND,
                'carrier' => $carrier,
                'tracking_number' => $trackingNumber,
                'status' => ShippingLabel::STATUS_CREATED,
                'shipped_at' => now(),
                'recipient_address' => $order->shipping_address,
            ]);
        }

        $order->markAsShipped($trackingNumber, $carrier);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Order marked as shipped.',
                'order' => $order->fresh(),
            ]);
        }

        return back()->with('success', 'Order marked as shipped.');
    }

    public function createShippingLabel(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($order);

        if (! $order->isConfirmed() && $order->status !== Order::STATUS_PROCESSING) {
            return response()->json(['error' => 'Order cannot be shipped in its current state.'], 422);
        }

        $validated = $request->validate([
            'carrier' => 'required|string|in:fedex',
            'service_type' => 'required|string',
            'packaging_type' => 'required|string',
            'weight' => 'required|numeric|min:0.1',
            'length' => 'required|numeric|min:1',
            'width' => 'required|numeric|min:1',
            'height' => 'required|numeric|min:1',
        ]);

        // Check if order has shipping address
        if (empty($order->shipping_address)) {
            return response()->json(['error' => 'Order does not have a shipping address.'], 422);
        }

        try {
            $shippingService = app(\App\Services\Shipping\ShippingLabelService::class);

            if (! $shippingService->isConfigured($order->store)) {
                return response()->json(['error' => 'FedEx is not configured for this store.'], 422);
            }

            // Create the shipping label for the order
            $label = $this->createOrderShippingLabel($order, $validated);

            // Mark the order as shipped with the tracking number
            $order->markAsShipped($label->tracking_number, 'fedex');

            return response()->json([
                'success' => true,
                'message' => 'Shipping label created successfully.',
                'tracking_number' => $label->tracking_number,
                'label_id' => $label->id,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    protected function createOrderShippingLabel(Order $order, array $options): \App\Models\ShippingLabel
    {
        $store = $order->store;
        $shippingAddress = $order->shipping_address;

        $fedExService = \App\Services\Shipping\FedExService::forStore($store);

        if (! $fedExService->isConfigured()) {
            throw new \InvalidArgumentException('FedEx service is not configured.');
        }

        // Build sender address from store
        $senderAddress = $this->buildStoreShippingAddress($store);

        // Build recipient address from order shipping address
        $recipientAddress = [
            'name' => $shippingAddress['name'] ?? '',
            'company' => $shippingAddress['company'] ?? '',
            'street' => $shippingAddress['street1'] ?? $shippingAddress['address_line1'] ?? '',
            'street2' => $shippingAddress['street2'] ?? $shippingAddress['address_line2'] ?? '',
            'city' => $shippingAddress['city'] ?? '',
            'state' => $shippingAddress['state'] ?? '',
            'postal_code' => $shippingAddress['postal_code'] ?? $shippingAddress['zip'] ?? '',
            'country' => $shippingAddress['country'] ?? 'US',
            'phone' => $shippingAddress['phone'] ?? '',
        ];

        $packageDetails = [
            'weight' => (float) $options['weight'],
            'length' => (float) $options['length'],
            'width' => (float) $options['width'],
            'height' => (float) $options['height'],
        ];

        $result = $fedExService->createShipment(
            $senderAddress,
            $recipientAddress,
            $packageDetails,
            $options['service_type'],
            'PDF',
            $options['packaging_type']
        );

        if (! $result->success) {
            throw new \InvalidArgumentException($result->errorMessage ?? 'Failed to create shipping label');
        }

        // Store the label PDF
        $labelPath = sprintf(
            'shipping-labels/orders/%s/outbound-%s.pdf',
            $order->order_id ?? $order->id,
            now()->timestamp
        );
        \Illuminate\Support\Facades\Storage::put($labelPath, base64_decode($result->labelPdf));

        return $order->shippingLabels()->create([
            'store_id' => $store->id,
            'type' => \App\Models\ShippingLabel::TYPE_OUTBOUND,
            'carrier' => \App\Models\ShippingLabel::CARRIER_FEDEX,
            'tracking_number' => $result->trackingNumber,
            'service_type' => $options['service_type'],
            'label_format' => 'PDF',
            'label_path' => $labelPath,
            'label_zpl' => $result->labelZpl,
            'shipping_cost' => $result->shippingCost,
            'sender_address' => $senderAddress,
            'recipient_address' => $recipientAddress,
            'shipment_details' => array_merge($packageDetails, ['packaging_type' => $options['packaging_type']]),
            'fedex_shipment_id' => $result->shipmentId,
            'status' => \App\Models\ShippingLabel::STATUS_CREATED,
            'shipped_at' => now(),
        ]);
    }

    protected function buildStoreShippingAddress(\App\Models\Store $store): array
    {
        // Check for store's primary shipping address
        $primaryAddress = $store->getPrimaryShippingAddress();
        if ($primaryAddress && $primaryAddress->isValidForShipping()) {
            return $primaryAddress->toShippingFormat();
        }

        // Fall back to store's direct fields
        return [
            'name' => $store->business_name ?? $store->name,
            'company' => $store->business_name ?? $store->name,
            'street' => $store->address ?? '',
            'street2' => $store->address2 ?? '',
            'city' => $store->city ?? '',
            'state' => $store->state ?? '',
            'postal_code' => $store->zip ?? '',
            'country' => 'US',
            'phone' => $store->phone ?? '',
        ];
    }

    public function pushToShipStation(Order $order): JsonResponse
    {
        $this->authorizeOrder($order);

        if ($order->shipstation_store) {
            return response()->json(['error' => 'Order has already been pushed to ShipStation.'], 422);
        }

        try {
            $shipStationService = \App\Services\ShipStation\ShipStationService::forStore($order->store_id);

            if (! $shipStationService->isConfigured()) {
                return response()->json(['error' => 'ShipStation is not configured for this store.'], 422);
            }

            $result = $shipStationService->createOrder($order);

            if (! $result['success']) {
                return response()->json(['error' => $result['error'] ?? 'Failed to push to ShipStation.'], 422);
            }

            // Store the ShipStation order ID
            $order->update(['shipstation_store' => $result['order_id']]);

            return response()->json([
                'success' => true,
                'message' => 'Order pushed to ShipStation successfully.',
                'shipstation_order_id' => $result['order_id'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function deliver(Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        if ($order->status !== Order::STATUS_SHIPPED) {
            return back()->with('error', 'Order cannot be marked as delivered in its current state.');
        }

        $order->markAsDelivered();

        return back()->with('success', 'Order marked as delivered.');
    }

    public function complete(Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        if (! in_array($order->status, [Order::STATUS_DELIVERED, Order::STATUS_CONFIRMED, Order::STATUS_SHIPPED])) {
            return back()->with('error', 'Order cannot be completed in its current state.');
        }

        $order->markAsCompleted();

        return back()->with('success', 'Order completed.');
    }

    public function receivePayment(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        if ($order->isFullyPaid()) {
            return back()->with('error', 'Order is already fully paid.');
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:cash,credit_card,debit_card,check,wire,ach,store_credit',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $this->orderCreationService->addPaymentToOrder($order, [
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'user_id' => auth()->id(),
        ]);

        return back()->with('success', 'Payment received.');
    }

    public function cancel(Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        if ($order->isCancelled()) {
            return back()->with('error', 'Order is already cancelled.');
        }

        if ($order->status === Order::STATUS_COMPLETED) {
            return back()->with('error', 'Completed orders cannot be cancelled.');
        }

        $this->orderCreationService->cancelOrder($order);

        return back()->with('success', 'Order cancelled. Stock has been restored.');
    }

    public function updateItem(Request $request, Order $order, OrderItem $item): RedirectResponse
    {
        $this->authorizeOrder($order);

        if ($item->order_id !== $order->id) {
            return back()->with('error', 'Item does not belong to this order.');
        }

        if (! $order->isPending() && $order->status !== Order::STATUS_DRAFT) {
            return back()->with('error', 'Items can only be modified on pending orders.');
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $item->update($validated);
        $order->calculateTotals();

        return back()->with('success', 'Item updated.');
    }

    public function removeItem(Order $order, OrderItem $item): RedirectResponse
    {
        $this->authorizeOrder($order);

        if ($item->order_id !== $order->id) {
            return back()->with('error', 'Item does not belong to this order.');
        }

        if (! $order->isPending() && $order->status !== Order::STATUS_DRAFT) {
            return back()->with('error', 'Items can only be removed from pending orders.');
        }

        // Restore stock if item has a variant
        if ($item->product_variant_id) {
            $this->restoreStock($item->product_variant_id, $item->quantity);
        }

        $item->delete();
        $order->calculateTotals();

        return back()->with('success', 'Item removed from order.');
    }

    public function bulkAction(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'action' => 'required|string|in:delete,cancel',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:orders,id',
        ]);

        $orders = Order::where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->get();

        $count = $orders->count();

        match ($validated['action']) {
            'delete' => $orders->each(function ($order) {
                if ($order->isPending() || $order->status === Order::STATUS_DRAFT) {
                    foreach ($order->items as $item) {
                        if ($item->product_variant_id) {
                            $this->restoreStock($item->product_variant_id, $item->quantity);
                        }
                    }
                    $order->delete();
                }
            }),
            'cancel' => $orders->each(function ($order) {
                if (! $order->isCancelled() && $order->status !== Order::STATUS_COMPLETED) {
                    $this->orderCreationService->cancelOrder($order);
                }
            }),
        };

        $actionLabel = match ($validated['action']) {
            'delete' => 'deleted',
            'cancel' => 'cancelled',
        };

        return redirect()->route('web.orders.index')
            ->with('success', "{$count} order(s) {$actionLabel} successfully.");
    }

    // Search Products API for Wizard

    public function searchProducts(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['products' => []], 200);
        }

        $query = $request->get('query', '');
        $categoryId = $request->get('category_id');

        $products = Product::where('store_id', $store->id)
            ->when($query, function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('title', 'like', "%{$query}%")
                        ->orWhere('description', 'like', "%{$query}%")
                        ->orWhereHas('variants', function ($variantQuery) use ($query) {
                            $variantQuery->where('sku', 'like', "%{$query}%")
                                ->orWhere('barcode', 'like', "%{$query}%");
                        });
                });
            })
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->with(['category', 'images', 'variants'])
            ->limit(50) // Get more initially to filter
            ->get()
            ->filter(function ($product) {
                // Include if sell_out_of_stock is enabled OR has stock available
                return $product->sell_out_of_stock || $product->total_quantity > 0;
            })
            ->take(20)
            ->map(function ($product) {
                $variant = $product->variants->first();
                $totalQuantity = $product->total_quantity;

                return [
                    'id' => $product->id,
                    'variant_id' => $variant?->id,
                    'title' => $product->title,
                    'sku' => $variant?->sku,
                    'description' => $product->description,
                    'price' => $variant?->price ?? 0,
                    'cost' => $variant?->cost ?? 0,
                    'quantity' => $totalQuantity,
                    'category' => $product->category?->name,
                    'image' => $product->images->first()?->url,
                ];
            })
            ->values();

        return response()->json(['products' => $products]);
    }

    /**
     * Lookup a product by exact barcode or SKU match.
     * Used for barcode scanner functionality.
     */
    public function lookupBarcode(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['found' => false, 'product' => null], 200);
        }

        $barcode = $request->get('barcode', '');

        if (empty($barcode)) {
            return response()->json(['found' => false, 'product' => null, 'error' => 'No barcode provided'], 400);
        }

        // First try exact match on barcode
        $variant = ProductVariant::whereHas('product', function ($q) use ($store) {
            $q->where('store_id', $store->id);
        })
            ->where(function ($q) use ($barcode) {
                $q->where('barcode', $barcode)
                    ->orWhere('sku', $barcode);
            })
            ->with(['product.category', 'product.images'])
            ->first();

        if (! $variant) {
            return response()->json([
                'found' => false,
                'product' => null,
                'message' => 'Product not found',
            ]);
        }

        $product = $variant->product;
        $totalQuantity = $product->total_quantity;

        // Check if product can be sold (has stock or sell_out_of_stock is enabled)
        if (! $product->sell_out_of_stock && $totalQuantity <= 0) {
            return response()->json([
                'found' => false,
                'product' => null,
                'message' => 'Product is out of stock',
            ]);
        }

        return response()->json([
            'found' => true,
            'product' => [
                'id' => $product->id,
                'variant_id' => $variant->id,
                'title' => $product->title,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'description' => $product->description,
                'price' => $variant->price ?? 0,
                'cost' => $variant->cost ?? 0,
                'quantity' => $totalQuantity,
                'category' => $product->category?->name,
                'image' => $product->images->first()?->url,
            ],
        ]);
    }

    // Search Bucket Items API for Wizard

    public function searchBucketItems(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['buckets' => []], 200);
        }

        // Get all buckets with their active (unsold) items
        $buckets = Bucket::where('store_id', $store->id)
            ->with(['activeItems' => fn ($q) => $q->orderBy('created_at', 'desc')])
            ->get()
            ->filter(fn ($bucket) => $bucket->activeItems->isNotEmpty())
            ->map(fn ($bucket) => [
                'id' => $bucket->id,
                'name' => $bucket->name,
                'total_value' => $bucket->total_value,
                'items' => $bucket->activeItems->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'value' => $item->value,
                    'created_at' => $item->created_at->toISOString(),
                ]),
            ])
            ->values();

        return response()->json(['buckets' => $buckets]);
    }

    // Search Customers API for Wizard

    public function searchCustomers(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['customers' => []], 200);
        }

        $query = $request->get('query', '');

        $customers = Customer::where('store_id', $store->id)
            ->when($query, function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('first_name', 'like', "%{$query}%")
                        ->orWhere('last_name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%")
                        ->orWhere('phone_number', 'like', "%{$query}%");
                });
            })
            ->limit(20)
            ->get()
            ->map(fn ($customer) => [
                'id' => $customer->id,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'full_name' => $customer->full_name,
                'email' => $customer->email,
                'phone' => $customer->phone_number,
            ]);

        return response()->json(['customers' => $customers]);
    }

    public function storeQuickProduct(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'No store selected'], 400);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'sku' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
        ]);

        // Get the category to inherit charge_taxes setting
        $category = isset($validated['category_id']) && $validated['category_id']
            ? Category::find($validated['category_id'])
            : null;

        // Inherit charge_taxes from category (defaults to true if no category)
        $chargeTaxes = $category ? $category->getEffectiveChargeTaxes() : true;

        $product = Product::create([
            'store_id' => $store->id,
            'title' => $validated['title'],
            'handle' => $this->generateUniqueHandle($store->id, $validated['title']),
            'category_id' => $validated['category_id'] ?? null,
            'quantity' => 1,
            'is_published' => true,
            'is_draft' => false,
            'has_variants' => false,
            'track_quantity' => true,
            'charge_taxes' => $chargeTaxes,
        ]);

        $variant = $product->variants()->create([
            'sku' => ! empty($validated['sku']) ? $validated['sku'] : $this->generateSku(),
            'price' => $validated['price'],
            'cost' => $validated['cost'] ?? null,
            'quantity' => 1,
        ]);

        return response()->json([
            'product' => [
                'id' => $product->id,
                'variant_id' => $variant->id,
                'title' => $product->title,
                'sku' => $variant->sku,
                'price' => $variant->price,
                'cost' => $variant->cost,
                'quantity' => $product->quantity,
                'category' => $product->category?->name,
                'image' => null,
            ],
        ], 201);
    }

    protected function generateSku(): string
    {
        return 'SKU-'.strtoupper(Str::random(8));
    }

    protected function generateUniqueHandle(int $storeId, string $title): string
    {
        $baseHandle = Str::slug($title);
        $handle = $baseHandle;
        $counter = 1;

        while (Product::where('store_id', $storeId)->where('handle', $handle)->exists()) {
            $handle = $baseHandle.'-'.$counter;
            $counter++;
        }

        return $handle;
    }

    protected function restoreStock(int $variantId, int $quantity): void
    {
        $inventory = \App\Models\Inventory::where('product_variant_id', $variantId)->first();

        if ($inventory) {
            $inventory->increment('quantity', $quantity);
        }
    }

    protected function authorizeOrder(Order $order): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $order->store_id !== $store->id) {
            abort(404);
        }
    }

    /**
     * Format order for frontend.
     *
     * @return array<string, mixed>
     */
    protected function formatOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'invoice_number' => $order->invoice_number,
            'status' => $order->status,
            'sub_total' => $order->sub_total,
            'sales_tax' => $order->sales_tax,
            'tax_rate' => $order->tax_rate,
            'shipping_cost' => $order->shipping_cost,
            'discount_cost' => $order->discount_cost,
            'trade_in_credit' => $order->trade_in_credit ?? 0,
            'total' => $order->total,
            'total_paid' => $order->total_paid,
            'balance_due' => $order->balance_due,

            // Payment adjustment fields for CollectPaymentModal
            ...$order->getPaymentAdjustments(),

            'notes' => $order->notes,
            'billing_address' => $order->billing_address,
            'shipping_address' => $order->shipping_address,
            'tracking_number' => $order->tracking_number,
            'shipping_carrier' => $order->shipping_carrier,
            'shipped_at' => $order->shipped_at?->toISOString(),
            'tracking_url' => $order->getTrackingUrl(),
            'order_id' => $order->order_id,
            'date_of_purchase' => $order->date_of_purchase?->toISOString(),
            'source_platform' => $order->source_platform,
            'external_marketplace_id' => $order->external_marketplace_id,
            'created_at' => $order->created_at->toISOString(),
            'updated_at' => $order->updated_at->toISOString(),

            // Status helpers
            'is_draft' => $order->status === Order::STATUS_DRAFT,
            'is_pending' => $order->isPending(),
            'is_confirmed' => $order->isConfirmed(),
            'is_paid' => $order->isPaid(),
            'is_cancelled' => $order->isCancelled(),
            'is_fully_paid' => $order->isFullyPaid(),
            'is_from_external_platform' => $order->isFromExternalPlatform(),

            // Action helpers
            'can_be_confirmed' => $order->isPending() || $order->status === Order::STATUS_PARTIAL_PAYMENT,
            'can_be_shipped' => $order->isConfirmed() || $order->status === Order::STATUS_PROCESSING,
            'can_be_delivered' => $order->status === Order::STATUS_SHIPPED,
            'can_be_completed' => in_array($order->status, [Order::STATUS_DELIVERED, Order::STATUS_CONFIRMED, Order::STATUS_SHIPPED]),
            'can_be_cancelled' => ! $order->isCancelled() && $order->status !== Order::STATUS_COMPLETED,
            'can_receive_payment' => ! $order->isFullyPaid(),
            'can_be_deleted' => $order->isPending() || $order->status === Order::STATUS_DRAFT,
            'has_trade_in' => $order->hasTradeIn(),
            'can_sync_from_platform' => $order->platformOrder !== null
                || (! empty($order->external_marketplace_id) && (
                    $order->salesChannel?->storeMarketplace !== null
                    || ! empty($order->source_platform)
                )),

            // Relationships
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'first_name' => $order->customer->first_name,
                'last_name' => $order->customer->last_name,
                'full_name' => $order->customer->full_name,
                'email' => $order->customer->email,
                'phone' => $order->customer->phone_number,
            ] : null,
            'user' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
            ] : null,
            'warehouse' => $order->warehouse ? [
                'id' => $order->warehouse->id,
                'name' => $order->warehouse->name,
            ] : null,
            'sales_channel' => $order->salesChannel ? [
                'id' => $order->salesChannel->id,
                'name' => $order->salesChannel->name,
                'type' => $order->salesChannel->type,
                'type_label' => $order->salesChannel->type_label,
                'is_local' => $order->salesChannel->isLocal(),
                'color' => $order->salesChannel->color,
                'marketplace' => $order->salesChannel->storeMarketplace ? [
                    'id' => $order->salesChannel->storeMarketplace->id,
                    'name' => $order->salesChannel->storeMarketplace->name,
                    'shop_domain' => $order->salesChannel->storeMarketplace->shop_domain,
                ] : null,
            ] : null,
            'platform_order' => $order->platformOrder ? [
                'id' => $order->platformOrder->id,
                'external_order_id' => $order->platformOrder->external_order_id,
                'external_order_number' => $order->platformOrder->external_order_number,
                'status' => $order->platformOrder->status,
                'fulfillment_status' => $order->platformOrder->fulfillment_status,
                'payment_status' => $order->platformOrder->payment_status,
                'last_synced_at' => $order->platformOrder->last_synced_at?->toISOString(),
                'marketplace' => $order->platformOrder->marketplace ? [
                    'id' => $order->platformOrder->marketplace->id,
                    'platform' => $order->platformOrder->marketplace->platform->value,
                    'name' => $order->platformOrder->marketplace->name,
                    'shop_domain' => $order->platformOrder->marketplace->shop_domain,
                ] : null,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'sku' => $item->sku,
                'title' => $item->title,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'cost' => $item->cost,
                'discount' => $item->discount,
                'tax' => $item->tax,
                'line_total' => $item->line_total,
                'line_profit' => $item->line_profit,
                'notes' => $item->notes,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'title' => $item->product->title,
                    'image' => $item->product->images->first()?->url ?? null,
                ] : null,
            ]),
            'item_count' => $order->item_count,
            'invoice' => $order->invoice ? [
                'id' => $order->invoice->id,
                'invoice_number' => $order->invoice->invoice_number,
                'status' => $order->invoice->status,
                'total' => $order->invoice->total,
                'balance_due' => $order->invoice->balance_due,
            ] : null,
            'payments' => $order->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method,
                'status' => $payment->status,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'paid_at' => $payment->paid_at?->toISOString(),
                'user' => $payment->user ? [
                    'id' => $payment->user->id,
                    'name' => $payment->user->name,
                ] : null,
            ]),
            'trade_in_transaction' => $order->tradeInTransaction ? [
                'id' => $order->tradeInTransaction->id,
                'transaction_number' => $order->tradeInTransaction->transaction_number,
                'final_offer' => $order->tradeInTransaction->final_offer,
                'status' => $order->tradeInTransaction->status,
                'items' => $order->tradeInTransaction->items->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'buy_price' => $item->buy_price,
                    'precious_metal' => $item->precious_metal,
                    'condition' => $item->condition,
                    'dwt' => $item->dwt,
                ]),
            ] : null,
            'note_entries' => ($order->getRelation('notes') ?? collect())->map(fn ($note) => [
                'id' => $note->id,
                'content' => $note->content,
                'user' => $note->user ? [
                    'id' => $note->user->id,
                    'name' => $note->user->name,
                ] : null,
                'created_at' => $note->created_at->toISOString(),
                'updated_at' => $note->updated_at->toISOString(),
            ]),
        ];
    }

    /**
     * Get available statuses.
     *
     * @return array<array<string, string>>
     */
    protected function getStatuses(): array
    {
        return [
            ['value' => Order::STATUS_DRAFT, 'label' => 'Draft'],
            ['value' => Order::STATUS_PENDING, 'label' => 'Pending'],
            ['value' => Order::STATUS_CONFIRMED, 'label' => 'Confirmed'],
            ['value' => Order::STATUS_PROCESSING, 'label' => 'Processing'],
            ['value' => Order::STATUS_SHIPPED, 'label' => 'Shipped'],
            ['value' => Order::STATUS_DELIVERED, 'label' => 'Delivered'],
            ['value' => Order::STATUS_COMPLETED, 'label' => 'Completed'],
            ['value' => Order::STATUS_CANCELLED, 'label' => 'Cancelled'],
            ['value' => Order::STATUS_REFUNDED, 'label' => 'Refunded'],
            ['value' => Order::STATUS_PARTIAL_PAYMENT, 'label' => 'Partial Payment'],
        ];
    }

    /**
     * Get available payment methods.
     *
     * @return array<array<string, string>>
     */
    protected function getPaymentMethods(): array
    {
        return [
            ['value' => 'cash', 'label' => 'Cash'],
            ['value' => 'credit_card', 'label' => 'Credit Card'],
            ['value' => 'debit_card', 'label' => 'Debit Card'],
            ['value' => 'check', 'label' => 'Check'],
            ['value' => 'wire', 'label' => 'Wire Transfer'],
            ['value' => 'ach', 'label' => 'ACH Transfer'],
            ['value' => 'store_credit', 'label' => 'Store Credit'],
        ];
    }

    /**
     * Get available precious metals for trade-in items.
     *
     * @return array<array<string, string>>
     */
    protected function getPreciousMetals(): array
    {
        return [
            ['value' => TransactionItem::METAL_GOLD_10K, 'label' => 'Gold 10K'],
            ['value' => TransactionItem::METAL_GOLD_14K, 'label' => 'Gold 14K'],
            ['value' => TransactionItem::METAL_GOLD_18K, 'label' => 'Gold 18K'],
            ['value' => TransactionItem::METAL_GOLD_22K, 'label' => 'Gold 22K'],
            ['value' => TransactionItem::METAL_GOLD_24K, 'label' => 'Gold 24K'],
            ['value' => TransactionItem::METAL_SILVER, 'label' => 'Silver'],
            ['value' => TransactionItem::METAL_PLATINUM, 'label' => 'Platinum'],
            ['value' => TransactionItem::METAL_PALLADIUM, 'label' => 'Palladium'],
        ];
    }

    /**
     * Get available conditions for trade-in items.
     *
     * @return array<array<string, string>>
     */
    protected function getItemConditions(): array
    {
        return [
            ['value' => TransactionItem::CONDITION_NEW, 'label' => 'New'],
            ['value' => TransactionItem::CONDITION_LIKE_NEW, 'label' => 'Like New'],
            ['value' => TransactionItem::CONDITION_USED, 'label' => 'Used'],
            ['value' => TransactionItem::CONDITION_DAMAGED, 'label' => 'Damaged'],
        ];
    }
}
