<?php

use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InventoryTransferController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\MemoController;
use App\Http\Controllers\Api\V1\MetalPriceController;
use App\Http\Controllers\Api\V1\NotificationSubscriptionController;
use App\Http\Controllers\Api\V1\NotificationTemplateController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PackingSlipController;
use App\Http\Controllers\Api\V1\PaymentTerminalController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductTemplateController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\RepairController;
use App\Http\Controllers\Api\V1\ReturnController;
use App\Http\Controllers\Api\V1\ReturnPolicyController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\StatusController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\StoreUserController;
use App\Http\Controllers\Api\V1\TerminalCheckoutController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\VendorController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\VoiceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:api');

// Public API routes (no auth required)
Route::prefix('v1')->group(function () {
    // Metal Prices - public spot price calculation
    Route::get('metal-prices/calculate', [MetalPriceController::class, 'calculate']);
});

// Store management routes (no store middleware required)
Route::prefix('v1')->middleware(['auth:api'])->group(function () {
    Route::get('stores', [StoreController::class, 'index']);
    Route::post('stores', [StoreController::class, 'store']);
    Route::post('stores/{store}/switch', [StoreController::class, 'switchStore']);
});

Route::prefix('v1')->middleware(['auth:api', 'store'])->name('api.')->group(function () {
    // Store
    Route::get('store', [StoreController::class, 'show']);
    Route::patch('store', [StoreController::class, 'update']);

    // Products
    Route::get('products/{product}/preview', [ProductController::class, 'preview']);
    Route::apiResource('products', ProductController::class);

    // Categories
    Route::get('categories/tree', [CategoryController::class, 'tree']);
    Route::get('categories/flat', [CategoryController::class, 'flat']);
    Route::get('categories/{category}/ancestors', [CategoryController::class, 'ancestors']);
    Route::get('categories/{category}/descendants', [CategoryController::class, 'descendants']);
    Route::get('categories/{category}/template', [CategoryController::class, 'template']);
    Route::apiResource('categories', CategoryController::class);

    // Product Templates
    Route::post('product-templates/{productTemplate}/duplicate', [ProductTemplateController::class, 'duplicate']);
    Route::post('product-templates/{productTemplate}/fields', [ProductTemplateController::class, 'addField']);
    Route::patch('product-templates/{productTemplate}/fields/{field}', [ProductTemplateController::class, 'updateField']);
    Route::delete('product-templates/{productTemplate}/fields/{field}', [ProductTemplateController::class, 'deleteField']);
    Route::put('product-templates/{productTemplate}/fields/{field}/options', [ProductTemplateController::class, 'updateFieldOptions']);
    Route::post('product-templates/{productTemplate}/reorder-fields', [ProductTemplateController::class, 'reorderFields']);
    Route::apiResource('product-templates', ProductTemplateController::class);

    // Brands
    Route::apiResource('brands', BrandController::class);

    // Customers
    Route::apiResource('customers', CustomerController::class);

    // Orders
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::post('orders/{order}/payment', [OrderController::class, 'addPayment']);
    Route::post('orders/{order}/confirm', [OrderController::class, 'confirm']);
    Route::post('orders/{order}/ship', [OrderController::class, 'ship']);
    Route::post('orders/{order}/deliver', [OrderController::class, 'deliver']);
    Route::post('orders/{order}/complete', [OrderController::class, 'complete']);
    Route::apiResource('orders', OrderController::class);

    // Return Policies
    Route::post('return-policies/{returnPolicy}/default', [ReturnPolicyController::class, 'setDefault']);
    Route::apiResource('return-policies', ReturnPolicyController::class);

    // Returns
    Route::post('returns/{return}/approve', [ReturnController::class, 'approve']);
    Route::post('returns/{return}/reject', [ReturnController::class, 'reject']);
    Route::post('returns/{return}/process', [ReturnController::class, 'process']);
    Route::post('returns/{return}/cancel', [ReturnController::class, 'cancel']);
    Route::post('returns/{return}/exchange', [ReturnController::class, 'exchange']);
    Route::post('returns/{return}/receive', [ReturnController::class, 'receive']);
    Route::apiResource('returns', ReturnController::class)->except(['update', 'destroy']);

    // Warehouses
    Route::post('warehouses/{warehouse}/make-default', [WarehouseController::class, 'makeDefault']);
    Route::get('warehouses/{warehouse}/inventory', [WarehouseController::class, 'inventory']);
    Route::apiResource('warehouses', WarehouseController::class);

    // Vendors
    Route::post('vendors/{vendor}/products', [VendorController::class, 'attachProduct']);
    Route::delete('vendors/{vendor}/products/{variant}', [VendorController::class, 'detachProduct']);
    Route::get('vendors/{vendor}/products', [VendorController::class, 'products']);
    Route::apiResource('vendors', VendorController::class);

    // Purchase Orders
    Route::post('purchase-orders/{purchase_order}/items', [PurchaseOrderController::class, 'addItem']);
    Route::put('purchase-orders/{purchase_order}/items/{item}', [PurchaseOrderController::class, 'updateItem']);
    Route::delete('purchase-orders/{purchase_order}/items/{item}', [PurchaseOrderController::class, 'removeItem']);
    Route::post('purchase-orders/{purchase_order}/submit', [PurchaseOrderController::class, 'submit']);
    Route::post('purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve']);
    Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel']);
    Route::post('purchase-orders/{purchase_order}/close', [PurchaseOrderController::class, 'close']);
    Route::post('purchase-orders/{purchase_order}/receive', [PurchaseOrderController::class, 'receive']);
    Route::apiResource('purchase-orders', PurchaseOrderController::class)->parameters(['purchase-orders' => 'purchase_order']);

    // Inventory
    Route::get('inventory', [InventoryController::class, 'index']);
    Route::post('inventory/adjust', [InventoryController::class, 'adjust']);
    Route::get('inventory/low-stock', [InventoryController::class, 'lowStock']);
    Route::get('inventory/needs-reorder', [InventoryController::class, 'needsReorder']);
    Route::get('inventory/by-variant/{productVariant}', [InventoryController::class, 'byVariant']);
    Route::get('inventory/by-product/{product}', [InventoryController::class, 'byProduct']);

    // Inventory Transfers
    Route::post('inventory-transfers/{inventoryTransfer}/submit', [InventoryTransferController::class, 'submit']);
    Route::post('inventory-transfers/{inventoryTransfer}/ship', [InventoryTransferController::class, 'ship']);
    Route::post('inventory-transfers/{inventoryTransfer}/receive', [InventoryTransferController::class, 'receive']);
    Route::post('inventory-transfers/{inventoryTransfer}/cancel', [InventoryTransferController::class, 'cancel']);
    Route::apiResource('inventory-transfers', InventoryTransferController::class);

    // Roles & Permissions
    Route::get('roles/permissions', [RoleController::class, 'permissions']);
    Route::get('roles/presets', [RoleController::class, 'presets']);
    Route::post('roles/{role}/sync-permissions', [RoleController::class, 'syncPermissions']);
    Route::post('roles/{role}/duplicate', [RoleController::class, 'duplicate']);
    Route::apiResource('roles', RoleController::class);

    // Team Members (Store Users)
    Route::get('team/permissions', [StoreUserController::class, 'permissions']);
    Route::post('team/{storeUser}/transfer-ownership', [StoreUserController::class, 'transferOwnership']);
    Route::post('team/{storeUser}/accept-invitation', [StoreUserController::class, 'acceptInvitation']);
    Route::apiResource('team', StoreUserController::class)->parameters(['team' => 'storeUser']);

    // Notification Templates
    Route::get('notification-templates/defaults', [NotificationTemplateController::class, 'defaults']);
    Route::post('notification-templates/create-defaults', [NotificationTemplateController::class, 'createDefaults']);
    Route::post('notification-templates/{notificationTemplate}/preview', [NotificationTemplateController::class, 'preview']);
    Route::post('notification-templates/{notificationTemplate}/duplicate', [NotificationTemplateController::class, 'duplicate']);
    Route::apiResource('notification-templates', NotificationTemplateController::class);

    // Notification Subscriptions
    Route::get('notifications/activities', [NotificationSubscriptionController::class, 'activities']);
    Route::get('notifications/stats', [NotificationSubscriptionController::class, 'stats']);
    Route::get('notifications/logs', [NotificationSubscriptionController::class, 'recentLogs']);
    Route::post('notifications/{notificationSubscription}/test', [NotificationSubscriptionController::class, 'test']);
    Route::get('notifications/{notificationSubscription}/logs', [NotificationSubscriptionController::class, 'logs']);
    Route::apiResource('notifications', NotificationSubscriptionController::class)
        ->parameters(['notifications' => 'notificationSubscription']);

    // Transactions (Buy)
    Route::post('transactions/{transaction}/items', [TransactionController::class, 'addItem']);
    Route::put('transactions/{transaction}/items/{item}', [TransactionController::class, 'updateItem']);
    Route::delete('transactions/{transaction}/items/{item}', [TransactionController::class, 'removeItem']);
    Route::post('transactions/{transaction}/offer', [TransactionController::class, 'submitOffer']);
    Route::post('transactions/{transaction}/accept', [TransactionController::class, 'acceptOffer']);
    Route::post('transactions/{transaction}/decline', [TransactionController::class, 'declineOffer']);
    Route::post('transactions/{transaction}/process', [TransactionController::class, 'processPayment']);
    Route::post('transactions/{transaction}/items/{item}/inventory', [TransactionController::class, 'moveToInventory']);
    Route::apiResource('transactions', TransactionController::class);

    // Repairs
    Route::post('repairs/{repair}/items', [RepairController::class, 'addItem']);
    Route::put('repairs/{repair}/items/{item}', [RepairController::class, 'updateItem']);
    Route::delete('repairs/{repair}/items/{item}', [RepairController::class, 'removeItem']);
    Route::post('repairs/{repair}/send', [RepairController::class, 'sendToVendor']);
    Route::post('repairs/{repair}/receive', [RepairController::class, 'markReceivedByVendor']);
    Route::post('repairs/{repair}/complete', [RepairController::class, 'markCompleted']);
    Route::post('repairs/{repair}/sale', [RepairController::class, 'createSale']);
    Route::apiResource('repairs', RepairController::class);

    // Memos (Consignment)
    Route::post('memos/{memo}/items', [MemoController::class, 'addItem']);
    Route::delete('memos/{memo}/items/{item}', [MemoController::class, 'removeItem']);
    Route::post('memos/{memo}/send', [MemoController::class, 'sendToVendor']);
    Route::post('memos/{memo}/receive', [MemoController::class, 'markVendorReceived']);
    Route::post('memos/{memo}/items/{item}/return', [MemoController::class, 'returnItem']);
    Route::post('memos/{memo}/sale', [MemoController::class, 'createSale']);
    Route::apiResource('memos', MemoController::class);

    // Invoices
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'addPayment']);
    Route::post('invoices/{invoice}/terminal-payment', [InvoiceController::class, 'initiateTerminalPayment']);
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'voidInvoice']);
    Route::post('invoices/{invoice}/payments/{payment}/refund', [InvoiceController::class, 'refundPayment']);
    Route::post('invoices/{invoice}/sync', [InvoiceController::class, 'syncTotals']);
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'downloadPdf']);
    Route::get('invoices/{invoice}/pdf/stream', [InvoiceController::class, 'streamPdf']);
    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);

    // Packing Slips
    Route::get('memos/{memo}/packing-slip', [PackingSlipController::class, 'downloadMemo']);
    Route::get('memos/{memo}/packing-slip/stream', [PackingSlipController::class, 'streamMemo']);
    Route::get('repairs/{repair}/packing-slip', [PackingSlipController::class, 'downloadRepair']);
    Route::get('repairs/{repair}/packing-slip/stream', [PackingSlipController::class, 'streamRepair']);
    Route::get('transactions/{transaction}/packing-slip', [PackingSlipController::class, 'downloadTransaction']);
    Route::get('transactions/{transaction}/packing-slip/stream', [PackingSlipController::class, 'streamTransaction']);

    // Payment Terminals
    Route::get('terminals/gateways', [PaymentTerminalController::class, 'availableGateways']);
    Route::post('terminals/{terminal}/test', [PaymentTerminalController::class, 'testConnection']);
    Route::apiResource('terminals', PaymentTerminalController::class)->parameters(['terminals' => 'terminal']);

    // Terminal Checkouts
    Route::get('terminal-checkouts/{checkout}', [TerminalCheckoutController::class, 'show']);
    Route::post('terminal-checkouts/{checkout}/cancel', [TerminalCheckoutController::class, 'cancel']);

    // AI Chat
    Route::post('chat/message', [ChatController::class, 'message']);
    Route::get('chat/sessions', [ChatController::class, 'sessions']);
    Route::get('chat/sessions/{session}', [ChatController::class, 'show']);
    Route::delete('chat/sessions/{session}', [ChatController::class, 'destroy']);

    // Voice Assistant
    Route::post('voice/query', [VoiceController::class, 'query']);
    Route::post('voice/text-query', [VoiceController::class, 'textQuery']);

    // Global Search
    Route::get('search', SearchController::class)->name('search');

    // Statuses
    Route::middleware('permission:store.manage_statuses')->group(function () {
        Route::post('statuses/reorder', [StatusController::class, 'reorder']);
        Route::get('statuses/{status}/transitions', [StatusController::class, 'transitions']);
        Route::post('statuses/{status}/transitions', [StatusController::class, 'storeTransition']);
        Route::get('statuses/{status}/automations', [StatusController::class, 'automations']);
        Route::post('statuses/{status}/automations', [StatusController::class, 'storeAutomation']);
        Route::apiResource('statuses', StatusController::class);
        Route::patch('status-transitions/{transition}', [StatusController::class, 'updateTransition']);
        Route::delete('status-transitions/{transition}', [StatusController::class, 'destroyTransition']);
        Route::patch('status-automations/{automation}', [StatusController::class, 'updateAutomation']);
        Route::delete('status-automations/{automation}', [StatusController::class, 'destroyAutomation']);
    });

    // Entity Status Operations
    Route::get('{entityType}/{entityId}/available-transitions', [StatusController::class, 'availableTransitions'])
        ->where('entityType', 'transactions|orders|repairs|memos');
    Route::post('{entityType}/{entityId}/transition', [StatusController::class, 'transitionEntity'])
        ->where('entityType', 'transactions|orders|repairs|memos');
});
