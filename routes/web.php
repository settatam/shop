<?php

use App\Http\Controllers\Api\TaxonomyController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\Web\BucketsController;
use App\Http\Controllers\Web\BuysController;
use App\Http\Controllers\Web\BuysReportController;
use App\Http\Controllers\Web\CategoryController;
use App\Http\Controllers\Web\CustomProductController;
use App\Http\Controllers\Web\GiaCardScannerController;
use App\Http\Controllers\Web\GiaController;
use App\Http\Controllers\Web\IntegrationsController;
use App\Http\Controllers\Web\InventoryController;
use App\Http\Controllers\Web\InventoryReportController;
use App\Http\Controllers\Web\InvoiceController;
use App\Http\Controllers\Web\LabelPrintController;
use App\Http\Controllers\Web\LabelTemplateController;
use App\Http\Controllers\Web\LayawayController;
use App\Http\Controllers\Web\MemoController;
use App\Http\Controllers\Web\OnboardingController;
use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\PackingSlipController;
use App\Http\Controllers\Web\PaymentListController;
use App\Http\Controllers\Web\ProductController;
use App\Http\Controllers\Web\ProductTypeController;
use App\Http\Controllers\Web\PurchaseOrderController;
use App\Http\Controllers\Web\RepairController;
use App\Http\Controllers\Web\SalesReportController;
use App\Http\Controllers\Web\StoreController;
use App\Http\Controllers\Web\TagController;
use App\Http\Controllers\Web\TemplateController;
use App\Http\Controllers\Web\TemplateGeneratorController;
use App\Http\Controllers\Web\VendorController;
use App\Http\Controllers\Web\WarehouseController;
use App\Http\Controllers\WidgetsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

// Invitation routes (guest users)
Route::get('invitation/{token}', [InvitationController::class, 'show'])->name('invitation.show');
Route::post('invitation/{token}', [InvitationController::class, 'accept'])->name('invitation.accept');

// Store management routes (auth only, no store context required)
Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('stores', [StoreController::class, 'store'])->name('stores.store');
    Route::post('stores/{store}/switch', [StoreController::class, 'switch'])->name('stores.switch');
});

// Onboarding routes (auth only, no store/onboarding middleware)
Route::middleware(['auth', 'verified', 'store'])->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
    Route::post('onboarding', [OnboardingController::class, 'complete'])->name('onboarding.complete');
    Route::get('onboarding/ebay-categories/{ebayId}/children', [OnboardingController::class, 'getEbayCategoryChildren'])
        ->name('onboarding.ebay-children');
});

Route::middleware(['auth', 'verified', 'store', 'onboarding'])->group(function () {
    Route::get('dashboard', [\App\Http\Controllers\Web\DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/data', [\App\Http\Controllers\Web\DashboardController::class, 'getData'])->name('dashboard.data');

    // Product routes - static routes must come before resource routes
    Route::get('products/lookup-barcode', [ProductController::class, 'lookupBarcode'])->name('products.lookup-barcode');
    Route::post('products/bulk-action', [ProductController::class, 'bulkAction'])->name('products.bulk-action');
    Route::post('products/bulk-update', [ProductController::class, 'bulkUpdate'])->name('products.bulk-update');
    Route::post('products/generate-sku', [ProductController::class, 'generateSku'])->name('products.generate-sku');
    Route::post('products/preview-category-sku', [ProductController::class, 'previewCategorySku'])->name('products.preview-category-sku');
    Route::resource('products', ProductController::class);
    Route::get('products/{product}/print-barcode', [ProductController::class, 'printBarcode'])->name('products.print-barcode');
    Route::delete('products/{product}/images/{image}', [ProductController::class, 'deleteImage'])->name('products.delete-image');
    Route::post('products/{product}/images/{image}/primary', [ProductController::class, 'setPrimaryImage'])->name('products.set-primary-image');

    // Custom Product routes (for stores with has_custom_product_module enabled)
    Route::get('products-custom', [CustomProductController::class, 'index'])->name('products-custom.index');
    Route::get('products-custom/{product}', [CustomProductController::class, 'show'])->name('products-custom.show');
    Route::get('products-custom/{product}/edit', [CustomProductController::class, 'edit'])->name('products-custom.edit');

    // GIA Card Scanner
    Route::prefix('gia-scanner')->name('gia-scanner.')->group(function () {
        Route::post('/scan', [GiaCardScannerController::class, 'scan'])->name('scan');
        Route::post('/create-product', [GiaCardScannerController::class, 'createProduct'])->name('create-product');
        Route::post('/add-to-product/{product}', [GiaCardScannerController::class, 'addToProduct'])->name('add-to-product');
        Route::get('/search-products', [GiaCardScannerController::class, 'searchProducts'])->name('search-products');
    });

    // GIA Product Entry (via GIA API)
    Route::prefix('gia')->name('gia.')->group(function () {
        Route::get('/', [GiaController::class, 'index'])->name('index');
        Route::post('/data', [GiaController::class, 'getData'])->name('data');
        Route::post('/lookup', [GiaController::class, 'lookup'])->name('lookup');
    });

    Route::resource('templates', TemplateController::class);
    Route::post('templates/{template}/duplicate', [TemplateController::class, 'duplicate'])->name('templates.duplicate');
    Route::get('templates-generator', [TemplateGeneratorController::class, 'index'])->name('templates.generator');
    Route::post('templates-generator/preview', [TemplateGeneratorController::class, 'preview'])->name('templates.generator.preview');
    Route::post('templates-generator/preview-ebay', [TemplateGeneratorController::class, 'previewFromEbayCategory'])->name('templates.generator.preview-ebay');
    Route::post('templates-generator', [TemplateGeneratorController::class, 'store'])->name('templates.generator.store');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
    Route::get('categories/{category}/template-fields', [CategoryController::class, 'templateFields'])->name('categories.template-fields');

    // Category Settings (SKU format, templates)
    Route::get('categories/{category}/settings', [CategoryController::class, 'settings'])->name('categories.settings');
    Route::put('categories/{category}/settings', [CategoryController::class, 'updateSettings'])->name('categories.settings.update');
    Route::post('categories/{category}/preview-sku', [CategoryController::class, 'previewSku'])->name('categories.preview-sku');
    Route::post('categories/{category}/reset-sequence', [CategoryController::class, 'resetSequence'])->name('categories.reset-sequence');

    // Product Types (leaf categories with additional settings)
    Route::get('product-types', [ProductTypeController::class, 'index'])->name('product-types.index');
    Route::get('product-types/{category}/settings', [ProductTypeController::class, 'settings'])->name('product-types.settings');
    Route::put('product-types/{category}/settings', [ProductTypeController::class, 'updateSettings'])->name('product-types.update-settings');
    Route::get('product-types/{category}/attributes', [ProductTypeController::class, 'getAvailableAttributes'])->name('product-types.attributes');

    // Orders (Sales)
    Route::get('orders', [OrderController::class, 'index'])->name('web.orders.index');
    Route::get('orders/create', [OrderController::class, 'createWizard'])->name('web.orders.create-wizard');
    Route::post('orders', [OrderController::class, 'storeFromWizard'])->name('web.orders.store');
    Route::get('orders/search-products', [OrderController::class, 'searchProducts'])->name('web.orders.search-products');
    Route::get('orders/search-customers', [OrderController::class, 'searchCustomers'])->name('web.orders.search-customers');
    Route::get('orders/search-bucket-items', [OrderController::class, 'searchBucketItems'])->name('web.orders.search-bucket-items');
    Route::get('orders/lookup-barcode', [OrderController::class, 'lookupBarcode'])->name('web.orders.lookup-barcode');
    Route::post('orders/create-product', [OrderController::class, 'storeQuickProduct'])->name('web.orders.create-product');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('web.orders.show');
    Route::get('orders/{order}/print-invoice', [OrderController::class, 'printInvoice'])->name('web.orders.print-invoice');
    Route::patch('orders/{order}', [OrderController::class, 'update'])->name('web.orders.update');
    Route::delete('orders/{order}', [OrderController::class, 'destroy'])->name('web.orders.destroy');
    Route::post('orders/{order}/confirm', [OrderController::class, 'confirm'])->name('web.orders.confirm');
    Route::post('orders/{order}/ship', [OrderController::class, 'ship'])->name('web.orders.ship');
    Route::post('orders/{order}/create-shipping-label', [OrderController::class, 'createShippingLabel'])->name('web.orders.create-shipping-label');
    Route::post('orders/{order}/push-to-shipstation', [OrderController::class, 'pushToShipStation'])->name('web.orders.push-to-shipstation');
    Route::post('orders/{order}/deliver', [OrderController::class, 'deliver'])->name('web.orders.deliver');
    Route::post('orders/{order}/complete', [OrderController::class, 'complete'])->name('web.orders.complete');
    Route::post('orders/{order}/receive-payment', [OrderController::class, 'receivePayment'])->name('web.orders.receive-payment');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('web.orders.cancel');
    Route::patch('orders/{order}/items/{item}', [OrderController::class, 'updateItem'])->name('web.orders.update-item');
    Route::delete('orders/{order}/items/{item}', [OrderController::class, 'removeItem'])->name('web.orders.remove-item');
    Route::post('orders/bulk-action', [OrderController::class, 'bulkAction'])->name('web.orders.bulk-action');

    // Shipments
    Route::get('shipments', [\App\Http\Controllers\Web\ShipmentController::class, 'index'])->name('web.shipments.index');
    Route::get('shipments/{shippingLabel}/track', [\App\Http\Controllers\Web\ShipmentController::class, 'track'])->name('web.shipments.track');
    Route::get('shipments/{shippingLabel}/download', [\App\Http\Controllers\Web\ShipmentController::class, 'download'])->name('web.shipments.download');
    Route::post('shipments/{shippingLabel}/void', [\App\Http\Controllers\Web\ShipmentController::class, 'void'])->name('web.shipments.void');
    Route::post('shipments/bulk-action', [\App\Http\Controllers\Web\ShipmentController::class, 'bulkAction'])->name('web.shipments.bulk-action');

    // Returns
    Route::get('returns', [\App\Http\Controllers\Web\ReturnController::class, 'index'])->name('web.returns.index');
    Route::get('returns/create', [\App\Http\Controllers\Web\ReturnController::class, 'create'])->name('web.returns.create');
    Route::post('returns', [\App\Http\Controllers\Web\ReturnController::class, 'store'])->name('web.returns.store');
    Route::get('returns/search-orders', [\App\Http\Controllers\Web\ReturnController::class, 'searchOrders'])->name('web.returns.search-orders');
    Route::get('returns/{return}', [\App\Http\Controllers\Web\ReturnController::class, 'show'])->name('web.returns.show');
    Route::post('returns/{return}/approve', [\App\Http\Controllers\Web\ReturnController::class, 'approve'])->name('web.returns.approve');
    Route::post('returns/{return}/reject', [\App\Http\Controllers\Web\ReturnController::class, 'reject'])->name('web.returns.reject');
    Route::post('returns/{return}/process', [\App\Http\Controllers\Web\ReturnController::class, 'process'])->name('web.returns.process');
    Route::post('returns/{return}/receive', [\App\Http\Controllers\Web\ReturnController::class, 'receive'])->name('web.returns.receive');
    Route::post('returns/{return}/complete', [\App\Http\Controllers\Web\ReturnController::class, 'complete'])->name('web.returns.complete');
    Route::post('returns/{return}/items/{item}/restock', [\App\Http\Controllers\Web\ReturnController::class, 'restockItem'])->name('web.returns.restock-item');
    Route::post('returns/{return}/create-label', [\App\Http\Controllers\Web\ReturnController::class, 'createLabel'])->name('web.returns.create-label');
    Route::post('returns/{return}/cancel', [\App\Http\Controllers\Web\ReturnController::class, 'cancel'])->name('web.returns.cancel');
    Route::post('returns/bulk-action', [\App\Http\Controllers\Web\ReturnController::class, 'bulkAction'])->name('web.returns.bulk-action');

    // Transactions (Buys) - use 'web.' prefix to avoid conflict with API routes
    // Buy Wizard routes (must be before resource routes to avoid conflicts)
    Route::get('transactions/buy', [\App\Http\Controllers\Web\TransactionController::class, 'createWizard'])->name('web.transactions.create-wizard');
    Route::post('transactions/buy', [\App\Http\Controllers\Web\TransactionController::class, 'storeFromWizard'])->name('web.transactions.store-wizard');

    Route::resource('transactions', \App\Http\Controllers\Web\TransactionController::class)->names([
        'index' => 'web.transactions.index',
        'create' => 'web.transactions.create',
        'store' => 'web.transactions.store',
        'show' => 'web.transactions.show',
        'edit' => 'web.transactions.edit',
        'update' => 'web.transactions.update',
        'destroy' => 'web.transactions.destroy',
    ]);
    Route::post('transactions/{transaction}/offer', [\App\Http\Controllers\Web\TransactionController::class, 'submitOffer'])->name('web.transactions.offer');
    Route::post('transactions/{transaction}/accept', [\App\Http\Controllers\Web\TransactionController::class, 'acceptOffer'])->name('web.transactions.accept');
    Route::post('transactions/{transaction}/decline', [\App\Http\Controllers\Web\TransactionController::class, 'declineOffer'])->name('web.transactions.decline');
    Route::post('transactions/{transaction}/process-payment', [\App\Http\Controllers\Web\TransactionController::class, 'processPayment'])->name('web.transactions.process-payment');
    Route::post('transactions/{transaction}/change-status', [\App\Http\Controllers\Web\TransactionController::class, 'changeStatus'])->name('web.transactions.change-status');
    Route::get('transactions/{transaction}/print-barcode', [\App\Http\Controllers\Web\TransactionController::class, 'printBarcode'])->name('web.transactions.print-barcode');
    Route::get('transactions/{transaction}/print-invoice', [\App\Http\Controllers\Web\TransactionController::class, 'printInvoice'])->name('web.transactions.print-invoice');
    Route::post('transactions/bulk-action', [\App\Http\Controllers\Web\TransactionController::class, 'bulkAction'])->name('web.transactions.bulk-action');
    Route::post('transactions/export', [\App\Http\Controllers\Web\TransactionController::class, 'export'])->name('web.transactions.export');

    // Transaction Item Detail routes
    Route::prefix('transactions/{transaction}/items/{item}')->name('web.transactions.items.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\TransactionItemController::class, 'show'])->name('show');
        Route::get('/edit', [\App\Http\Controllers\Web\TransactionItemController::class, 'edit'])->name('edit');
        Route::put('/', [\App\Http\Controllers\Web\TransactionItemController::class, 'update'])->name('update');
        Route::post('/images', [\App\Http\Controllers\Web\TransactionItemController::class, 'uploadImages'])->name('upload-images');
        Route::delete('/images/{image}', [\App\Http\Controllers\Web\TransactionItemController::class, 'deleteImage'])->name('delete-image');
        Route::post('/move-to-inventory', [\App\Http\Controllers\Web\TransactionItemController::class, 'moveToInventory'])->name('move-to-inventory');
        Route::post('/move-to-bucket', [\App\Http\Controllers\Web\TransactionItemController::class, 'moveToBucket'])->name('move-to-bucket');
        Route::post('/review', [\App\Http\Controllers\Web\TransactionItemController::class, 'review'])->name('review');
        Route::get('/similar', [\App\Http\Controllers\Web\TransactionItemController::class, 'similarItems'])->name('similar');
        Route::post('/ai-research', [\App\Http\Controllers\Web\TransactionItemController::class, 'generateAiResearch'])->name('ai-research');
        Route::post('/chat', [\App\Http\Controllers\Web\TransactionItemController::class, 'chatStream'])->name('chat');
    });

    // Online transaction workflow routes
    Route::post('transactions/{transaction}/confirm-kit-request', [\App\Http\Controllers\Web\TransactionController::class, 'confirmKitRequest'])->name('web.transactions.confirm-kit-request');
    Route::post('transactions/{transaction}/reject-kit-request', [\App\Http\Controllers\Web\TransactionController::class, 'rejectKitRequest'])->name('web.transactions.reject-kit-request');
    Route::post('transactions/{transaction}/hold-kit-request', [\App\Http\Controllers\Web\TransactionController::class, 'holdKitRequest'])->name('web.transactions.hold-kit-request');
    Route::post('transactions/{transaction}/mark-kit-sent', [\App\Http\Controllers\Web\TransactionController::class, 'markKitSent'])->name('web.transactions.mark-kit-sent');
    Route::post('transactions/{transaction}/mark-kit-delivered', [\App\Http\Controllers\Web\TransactionController::class, 'markKitDelivered'])->name('web.transactions.mark-kit-delivered');
    Route::post('transactions/{transaction}/mark-items-received', [\App\Http\Controllers\Web\TransactionController::class, 'markItemsReceived'])->name('web.transactions.mark-items-received');
    Route::post('transactions/{transaction}/mark-items-reviewed', [\App\Http\Controllers\Web\TransactionController::class, 'markItemsReviewed'])->name('web.transactions.mark-items-reviewed');
    Route::post('transactions/{transaction}/request-return', [\App\Http\Controllers\Web\TransactionController::class, 'requestReturn'])->name('web.transactions.request-return');
    Route::post('transactions/{transaction}/mark-return-shipped', [\App\Http\Controllers\Web\TransactionController::class, 'markReturnShipped'])->name('web.transactions.mark-return-shipped');
    Route::post('transactions/{transaction}/mark-items-returned', [\App\Http\Controllers\Web\TransactionController::class, 'markItemsReturned'])->name('web.transactions.mark-items-returned');
    Route::post('transactions/{transaction}/assign', [\App\Http\Controllers\Web\TransactionController::class, 'assignTransaction'])->name('web.transactions.assign');

    // Shipping Labels
    Route::post('transactions/{transaction}/create-outbound-label', [\App\Http\Controllers\Web\TransactionController::class, 'createOutboundLabel'])->name('web.transactions.create-outbound-label');
    Route::get('transactions/{transaction}/print-outbound-label', [\App\Http\Controllers\Web\TransactionController::class, 'printOutboundLabel'])->name('web.transactions.print-outbound-label');
    Route::get('transactions/{transaction}/outbound-label-zpl', [\App\Http\Controllers\Web\TransactionController::class, 'getOutboundLabelZpl'])->name('web.transactions.outbound-label-zpl');
    Route::post('transactions/{transaction}/create-return-label', [\App\Http\Controllers\Web\TransactionController::class, 'createReturnLabel'])->name('web.transactions.create-return-label');
    Route::get('transactions/{transaction}/print-return-label', [\App\Http\Controllers\Web\TransactionController::class, 'printReturnLabel'])->name('web.transactions.print-return-label');
    Route::get('transactions/{transaction}/return-label-zpl', [\App\Http\Controllers\Web\TransactionController::class, 'getReturnLabelZpl'])->name('web.transactions.return-label-zpl');
    Route::put('transactions/{transaction}/shipping-address', [\App\Http\Controllers\Web\TransactionController::class, 'updateShippingAddress'])->name('web.transactions.update-shipping-address');

    // Kit rejection and return
    Route::post('transactions/{transaction}/reject-kit', [\App\Http\Controllers\Web\TransactionController::class, 'rejectKit'])->name('web.transactions.reject-kit');
    Route::post('transactions/{transaction}/initiate-return', [\App\Http\Controllers\Web\TransactionController::class, 'initiateReturn'])->name('web.transactions.initiate-return');

    // Transaction rollback/reset actions
    Route::post('transactions/{transaction}/reset-to-items-reviewed', [\App\Http\Controllers\Web\TransactionController::class, 'resetToItemsReviewed'])->name('web.transactions.reset-to-items-reviewed');
    Route::post('transactions/{transaction}/reopen-offer', [\App\Http\Controllers\Web\TransactionController::class, 'reopenOffer'])->name('web.transactions.reopen-offer');
    Route::post('transactions/{transaction}/cancel-return', [\App\Http\Controllers\Web\TransactionController::class, 'cancelReturn'])->name('web.transactions.cancel-return');
    Route::post('transactions/{transaction}/undo-payment', [\App\Http\Controllers\Web\TransactionController::class, 'undoPayment'])->name('web.transactions.undo-payment');

    // PayPal Payouts
    Route::post('transactions/{transaction}/send-payout', [\App\Http\Controllers\Web\TransactionController::class, 'sendPayout'])->name('web.transactions.send-payout');
    Route::post('transactions/{transaction}/refresh-payout-status', [\App\Http\Controllers\Web\TransactionController::class, 'refreshPayoutStatus'])->name('web.transactions.refresh-payout-status');

    // SMS Messaging
    Route::post('transactions/{transaction}/send-sms', [\App\Http\Controllers\Web\TransactionController::class, 'sendSms'])->name('web.transactions.send-sms');

    // SMS Message Center
    Route::get('messages', [\App\Http\Controllers\Web\SmsController::class, 'index'])->name('web.sms.index');
    Route::get('messages/{id}', [\App\Http\Controllers\Web\SmsController::class, 'show'])->name('web.sms.show');
    Route::post('messages/{id}/mark-read', [\App\Http\Controllers\Web\SmsController::class, 'markAsRead'])->name('web.sms.mark-read');
    Route::post('messages/mark-read', [\App\Http\Controllers\Web\SmsController::class, 'markMultipleAsRead'])->name('web.sms.mark-multiple-read');

    // Buys (Completed Transactions with Payments)
    Route::get('buys', [BuysController::class, 'index'])->name('buys.index');
    Route::get('buys/items', [BuysController::class, 'items'])->name('buys.items');

    // Buckets (Junk items without SKUs)
    Route::get('buckets', [BucketsController::class, 'index'])->name('buckets.index');
    Route::post('buckets', [BucketsController::class, 'store'])->name('buckets.store');
    Route::get('buckets/search', [BucketsController::class, 'search'])->name('buckets.search');
    Route::get('buckets/search-customers', [BucketsController::class, 'searchCustomers'])->name('buckets.search-customers');
    Route::get('buckets/{bucket}', [BucketsController::class, 'show'])->name('buckets.show');
    Route::put('buckets/{bucket}', [BucketsController::class, 'update'])->name('buckets.update');
    Route::delete('buckets/{bucket}', [BucketsController::class, 'destroy'])->name('buckets.destroy');
    Route::post('buckets/{bucket}/items', [BucketsController::class, 'addItem'])->name('buckets.add-item');
    Route::post('buckets/{bucket}/create-sale', [BucketsController::class, 'createSale'])->name('buckets.create-sale');
    Route::delete('bucket-items/{bucketItem}', [BucketsController::class, 'removeItem'])->name('bucket-items.destroy');

    // Customers
    Route::get('customers', [\App\Http\Controllers\Web\CustomerController::class, 'index'])->name('web.customers.index');
    Route::get('customers/{customer}', [\App\Http\Controllers\Web\CustomerController::class, 'show'])->name('web.customers.show');
    Route::put('customers/{customer}', [\App\Http\Controllers\Web\CustomerController::class, 'update'])->name('web.customers.update');
    Route::post('customers/{customer}/documents', [\App\Http\Controllers\Web\CustomerController::class, 'uploadDocument'])->name('web.customers.upload-document');
    Route::delete('customers/{customer}/documents/{document}', [\App\Http\Controllers\Web\CustomerController::class, 'deleteDocument'])->name('web.customers.delete-document');
    Route::post('customers/{customer}/addresses', [\App\Http\Controllers\Web\CustomerController::class, 'storeAddress'])->name('web.customers.store-address');
    Route::put('customers/{customer}/addresses/{address}', [\App\Http\Controllers\Web\CustomerController::class, 'updateAddress'])->name('web.customers.update-address');
    Route::delete('customers/{customer}/addresses/{address}', [\App\Http\Controllers\Web\CustomerController::class, 'deleteAddress'])->name('web.customers.delete-address');

    // Lead Sources
    Route::redirect('leads', '/settings/lead-sources');
    Route::get('lead-sources', [\App\Http\Controllers\Web\LeadSourceController::class, 'index'])->name('web.lead-sources.index');
    Route::post('lead-sources', [\App\Http\Controllers\Web\LeadSourceController::class, 'store'])->name('web.lead-sources.store');

    // Memos (Consignment)
    Route::get('memos', [MemoController::class, 'index'])->name('web.memos.index');
    Route::get('memos/create', [MemoController::class, 'createWizard'])->name('web.memos.create-wizard');
    Route::post('memos', [MemoController::class, 'storeFromWizard'])->name('web.memos.store');
    Route::get('memos/search-products', [MemoController::class, 'searchProducts'])->name('web.memos.search-products');
    Route::get('memos/search-vendors', [MemoController::class, 'searchVendors'])->name('web.memos.search-vendors');
    Route::post('memos/create-product', [MemoController::class, 'storeQuickProduct'])->name('web.memos.create-product');
    Route::get('memos/{memo}', [MemoController::class, 'show'])->name('web.memos.show');
    Route::patch('memos/{memo}', [MemoController::class, 'update'])->name('web.memos.update');
    Route::delete('memos/{memo}', [MemoController::class, 'destroy'])->name('web.memos.destroy');
    Route::post('memos/{memo}/send-to-vendor', [MemoController::class, 'sendToVendor'])->name('web.memos.send-to-vendor');
    Route::post('memos/{memo}/mark-received', [MemoController::class, 'markReceived'])->name('web.memos.mark-received');
    Route::post('memos/{memo}/mark-returned', [MemoController::class, 'markReturned'])->name('web.memos.mark-returned');
    Route::post('memos/{memo}/receive-payment', [MemoController::class, 'receivePayment'])->name('web.memos.receive-payment');
    Route::post('memos/{memo}/return-item/{item}', [MemoController::class, 'returnItem'])->name('web.memos.return-item');
    Route::post('memos/{memo}/add-item', [MemoController::class, 'addItem'])->name('web.memos.add-item');
    Route::patch('memos/{memo}/items/{item}', [MemoController::class, 'updateItem'])->name('web.memos.update-item');
    Route::post('memos/{memo}/cancel', [MemoController::class, 'cancel'])->name('web.memos.cancel');
    Route::post('memos/{memo}/change-status', [MemoController::class, 'changeStatus'])->name('web.memos.change-status');
    Route::post('memos/bulk-action', [MemoController::class, 'bulkAction'])->name('web.memos.bulk-action');

    // Layaways
    Route::get('layaways', [LayawayController::class, 'index'])->name('web.layaways.index');
    Route::get('layaways/create', [LayawayController::class, 'createWizard'])->name('web.layaways.create-wizard');
    Route::post('layaways', [LayawayController::class, 'storeFromWizard'])->name('web.layaways.store');
    Route::get('layaways/search-products', [LayawayController::class, 'searchProducts'])->name('web.layaways.search-products');
    Route::get('layaways/search-customers', [LayawayController::class, 'searchCustomers'])->name('web.layaways.search-customers');
    Route::get('layaways/{layaway}', [LayawayController::class, 'show'])->name('web.layaways.show');
    Route::patch('layaways/{layaway}', [LayawayController::class, 'update'])->name('web.layaways.update');
    Route::delete('layaways/{layaway}', [LayawayController::class, 'destroy'])->name('web.layaways.destroy');
    Route::post('layaways/{layaway}/activate', [LayawayController::class, 'activate'])->name('web.layaways.activate');
    Route::post('layaways/{layaway}/complete', [LayawayController::class, 'complete'])->name('web.layaways.complete');
    Route::post('layaways/{layaway}/cancel', [LayawayController::class, 'cancel'])->name('web.layaways.cancel');
    Route::post('layaways/{layaway}/receive-payment', [LayawayController::class, 'receivePayment'])->name('web.layaways.receive-payment');
    Route::post('layaways/{layaway}/add-item', [LayawayController::class, 'addItem'])->name('web.layaways.add-item');
    Route::delete('layaways/{layaway}/items/{item}', [LayawayController::class, 'removeItem'])->name('web.layaways.remove-item');
    Route::post('layaways/bulk-action', [LayawayController::class, 'bulkAction'])->name('web.layaways.bulk-action');

    // Repair management
    Route::get('repairs', [RepairController::class, 'index'])->name('web.repairs.index');
    Route::get('repairs/create', [RepairController::class, 'createWizard'])->name('web.repairs.create-wizard');
    Route::post('repairs', [RepairController::class, 'storeFromWizard'])->name('web.repairs.store');
    Route::get('repairs/search-customers', [RepairController::class, 'searchCustomers'])->name('web.repairs.search-customers');
    Route::get('repairs/search-vendors', [RepairController::class, 'searchVendors'])->name('web.repairs.search-vendors');
    Route::get('repairs/{repair}', [RepairController::class, 'show'])->name('web.repairs.show');
    Route::patch('repairs/{repair}', [RepairController::class, 'update'])->name('web.repairs.update');
    Route::delete('repairs/{repair}', [RepairController::class, 'destroy'])->name('web.repairs.destroy');
    Route::post('repairs/{repair}/send-to-vendor', [RepairController::class, 'sendToVendor'])->name('web.repairs.send-to-vendor');
    Route::post('repairs/{repair}/mark-received', [RepairController::class, 'markReceived'])->name('web.repairs.mark-received');
    Route::post('repairs/{repair}/mark-completed', [RepairController::class, 'markCompleted'])->name('web.repairs.mark-completed');
    Route::post('repairs/{repair}/receive-payment', [RepairController::class, 'receivePayment'])->name('web.repairs.receive-payment');
    Route::post('repairs/{repair}/cancel', [RepairController::class, 'cancel'])->name('web.repairs.cancel');
    Route::post('repairs/{repair}/change-status', [RepairController::class, 'changeStatus'])->name('web.repairs.change-status');
    Route::patch('repairs/{repair}/items/{item}', [RepairController::class, 'updateItem'])->name('web.repairs.update-item');
    Route::delete('repairs/{repair}/items/{item}', [RepairController::class, 'removeItem'])->name('web.repairs.remove-item');
    Route::post('repairs/bulk-action', [RepairController::class, 'bulkAction'])->name('web.repairs.bulk-action');

    // Generic Payment Routes - defined explicitly for each model type to avoid route conflicts
    foreach (['memos', 'repairs', 'orders', 'layaways'] as $type) {
        Route::prefix("{$type}/{id}/payment")->where(['id' => '[0-9]+'])->group(function () use ($type) {
            Route::get('summary', [\App\Http\Controllers\PaymentController::class, 'summary'])
                ->name("{$type}.payment.summary")
                ->defaults('type', $type);
            Route::post('adjustments', [\App\Http\Controllers\PaymentController::class, 'updateAdjustments'])
                ->name("{$type}.payment.adjustments")
                ->defaults('type', $type);
            Route::post('process', [\App\Http\Controllers\PaymentController::class, 'processPayment'])
                ->name("{$type}.payment.process")
                ->defaults('type', $type);
            Route::get('history', [\App\Http\Controllers\PaymentController::class, 'paymentHistory'])
                ->name("{$type}.payment.history")
                ->defaults('type', $type);
            Route::post('{paymentId}/void', [\App\Http\Controllers\PaymentController::class, 'voidPayment'])
                ->name("{$type}.payment.void")
                ->defaults('type', $type);
        });
    }

    // Warehouse management
    Route::resource('warehouses', WarehouseController::class)->except(['show']);
    Route::post('warehouses/{warehouse}/make-default', [WarehouseController::class, 'makeDefault'])->name('warehouses.make-default');

    // Vendor management - use 'web.' prefix to avoid conflict with API routes
    Route::get('vendors/export', [VendorController::class, 'export'])->name('web.vendors.export');
    Route::resource('vendors', VendorController::class)->except(['create', 'edit'])->names([
        'index' => 'web.vendors.index',
        'store' => 'web.vendors.store',
        'show' => 'web.vendors.show',
        'update' => 'web.vendors.update',
        'destroy' => 'web.vendors.destroy',
    ]);

    // Tag management
    Route::get('tags/search', [TagController::class, 'search'])->name('web.tags.search');
    Route::resource('tags', TagController::class)->except(['create', 'edit', 'show'])->names([
        'index' => 'web.tags.index',
        'store' => 'web.tags.store',
        'update' => 'web.tags.update',
        'destroy' => 'web.tags.destroy',
    ]);

    // Purchase Orders - use 'web.' prefix to avoid conflict with API routes
    Route::resource('purchase-orders', PurchaseOrderController::class)->names([
        'index' => 'web.purchase-orders.index',
        'create' => 'web.purchase-orders.create',
        'store' => 'web.purchase-orders.store',
        'show' => 'web.purchase-orders.show',
        'edit' => 'web.purchase-orders.edit',
        'update' => 'web.purchase-orders.update',
        'destroy' => 'web.purchase-orders.destroy',
    ])->parameters(['purchase-orders' => 'purchaseOrder']);
    Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->name('web.purchase-orders.submit');
    Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->name('web.purchase-orders.approve');
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('web.purchase-orders.cancel');
    Route::post('purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])->name('web.purchase-orders.close');
    Route::get('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'showReceive'])->name('web.purchase-orders.receive');
    Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('web.purchase-orders.receive.store');

    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('inventory/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');

    // Integrations
    Route::get('integrations', [IntegrationsController::class, 'index'])->name('integrations.index');
    Route::post('integrations/fedex', [IntegrationsController::class, 'storeFedex'])->name('integrations.fedex.store');
    Route::post('integrations/twilio', [IntegrationsController::class, 'storeTwilio'])->name('integrations.twilio.store');
    Route::post('integrations/gia', [IntegrationsController::class, 'storeGia'])->name('integrations.gia.store');
    Route::post('integrations/shipstation', [IntegrationsController::class, 'storeShipStation'])->name('integrations.shipstation.store');
    Route::delete('integrations/{integration}', [IntegrationsController::class, 'destroy'])->name('integrations.destroy');

    // Invoices
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'downloadPdf'])->name('invoices.pdf');
    Route::get('invoices/{invoice}/pdf/stream', [InvoiceController::class, 'streamPdf'])->name('invoices.pdf.stream');

    // Payments
    Route::get('payments', [PaymentListController::class, 'index'])->name('payments.index');
    Route::get('payments/{payment}', [PaymentListController::class, 'show'])->name('payments.show');

    // Notes
    Route::post('notes', [\App\Http\Controllers\NoteController::class, 'store'])->name('notes.store');
    Route::put('notes/{note}', [\App\Http\Controllers\NoteController::class, 'update'])->name('notes.update');
    Route::delete('notes/{note}', [\App\Http\Controllers\NoteController::class, 'destroy'])->name('notes.destroy');

    // Packing Slips
    Route::get('memos/{memo}/packing-slip', [PackingSlipController::class, 'downloadMemo'])->name('memos.packing-slip');
    Route::get('memos/{memo}/packing-slip/stream', [PackingSlipController::class, 'streamMemo'])->name('memos.packing-slip.stream');
    Route::get('repairs/{repair}/packing-slip', [PackingSlipController::class, 'downloadRepair'])->name('repairs.packing-slip');
    Route::get('repairs/{repair}/packing-slip/stream', [PackingSlipController::class, 'streamRepair'])->name('repairs.packing-slip.stream');
    Route::get('transactions/{transaction}/packing-slip', [PackingSlipController::class, 'downloadTransaction'])->name('transactions.packing-slip');
    Route::get('transactions/{transaction}/packing-slip/stream', [PackingSlipController::class, 'streamTransaction'])->name('transactions.packing-slip.stream');

    // Label Templates
    Route::prefix('labels')->name('labels.')->group(function () {
        Route::get('/', [LabelTemplateController::class, 'index'])->name('index');
        Route::get('/create', [LabelTemplateController::class, 'create'])->name('create');
        Route::post('/', [LabelTemplateController::class, 'store'])->name('store');
        Route::get('/{label}/edit', [LabelTemplateController::class, 'edit'])->name('edit');
        Route::put('/{label}', [LabelTemplateController::class, 'update'])->name('update');
        Route::delete('/{label}', [LabelTemplateController::class, 'destroy'])->name('destroy');
        Route::post('/{label}/duplicate', [LabelTemplateController::class, 'duplicate'])->name('duplicate');
    });

    // Print Labels
    Route::prefix('print-labels')->name('print-labels.')->group(function () {
        Route::get('/products', [LabelPrintController::class, 'products'])->name('products');
        Route::post('/products/zpl', [LabelPrintController::class, 'generateProductZpl'])->name('products.zpl');
        Route::get('/transactions', [LabelPrintController::class, 'transactions'])->name('transactions');
        Route::post('/transactions/zpl', [LabelPrintController::class, 'generateTransactionZpl'])->name('transactions.zpl');
        Route::get('/shipping', [LabelPrintController::class, 'shippingLabels'])->name('shipping');
        Route::post('/shipping', [LabelPrintController::class, 'createBulkShippingLabels'])->name('shipping.create');
    });

    // Widget routes
    Route::prefix('widgets')->name('widgets.')->group(function () {
        Route::get('view', [WidgetsController::class, 'view'])->name('view');
        Route::post('process/{id?}', [WidgetsController::class, 'process'])->name('process');
        Route::get('export/{id?}', [WidgetsController::class, 'export'])->name('export');
    });

    // Taxonomy API routes
    Route::prefix('api/taxonomy')->name('api.taxonomy.')->group(function () {
        Route::get('ebay/categories', [TaxonomyController::class, 'searchEbayCategories'])->name('ebay.categories');
        Route::get('ebay/categories/{id}', [TaxonomyController::class, 'getEbayCategoryDetails'])->name('ebay.category');
        Route::get('ebay/categories/{id}/fields', [TaxonomyController::class, 'generateTemplateFields'])->name('ebay.fields');
        Route::get('google/categories', [TaxonomyController::class, 'searchGoogleCategories'])->name('google.categories');
        Route::get('etsy/categories', [TaxonomyController::class, 'searchEtsyCategories'])->name('etsy.categories');
    });

    // Sales Reports
    Route::prefix('reports/sales')->name('reports.sales.')->group(function () {
        Route::get('daily', [SalesReportController::class, 'daily'])->name('daily');
        Route::get('daily/export', [SalesReportController::class, 'exportDaily'])->name('daily.export');
        Route::get('monthly', [SalesReportController::class, 'monthly'])->name('monthly');
        Route::get('monthly/export', [SalesReportController::class, 'exportMonthly'])->name('monthly.export');
        Route::get('mtd', [SalesReportController::class, 'monthToDate'])->name('mtd');
        Route::get('mtd/export', [SalesReportController::class, 'exportMonthToDate'])->name('mtd.export');
    });

    // Inventory Reports
    Route::prefix('reports/inventory')->name('reports.inventory.')->group(function () {
        Route::get('/', [InventoryReportController::class, 'index'])->name('index');
        Route::get('export', [InventoryReportController::class, 'export'])->name('export');
        Route::get('weekly', [InventoryReportController::class, 'weekly'])->name('weekly');
        Route::get('weekly/export', [InventoryReportController::class, 'exportWeekly'])->name('weekly.export');
        Route::get('monthly', [InventoryReportController::class, 'monthly'])->name('monthly');
        Route::get('monthly/export', [InventoryReportController::class, 'exportMonthly'])->name('monthly.export');
        Route::get('yearly', [InventoryReportController::class, 'yearly'])->name('yearly');
        Route::get('yearly/export', [InventoryReportController::class, 'exportYearly'])->name('yearly.export');
    });

    // Buys Reports
    Route::prefix('reports/buys')->name('reports.buys.')->group(function () {
        // In-Store
        Route::get('in-store', [BuysReportController::class, 'inStore'])->name('in-store');
        Route::get('in-store/export', [BuysReportController::class, 'exportInStore'])->name('in-store.export');
        Route::get('in-store/monthly', [BuysReportController::class, 'inStoreMonthly'])->name('in-store.monthly');
        Route::get('in-store/monthly/export', [BuysReportController::class, 'exportInStoreMonthly'])->name('in-store.monthly.export');

        // Online
        Route::get('online', [BuysReportController::class, 'online'])->name('online');
        Route::get('online/export', [BuysReportController::class, 'exportOnline'])->name('online.export');
        Route::get('online/monthly', [BuysReportController::class, 'onlineMonthly'])->name('onlineMonthly');
        Route::get('online/monthly/export', [BuysReportController::class, 'exportOnlineMonthly'])->name('online.monthly.export');

        // Trade-In
        Route::get('trade-in', [BuysReportController::class, 'tradeIn'])->name('trade-in');
        Route::get('trade-in/export', [BuysReportController::class, 'exportTradeIn'])->name('trade-in.export');
        Route::get('trade-in/monthly', [BuysReportController::class, 'tradeInMonthly'])->name('trade-in.monthly');
        Route::get('trade-in/monthly/export', [BuysReportController::class, 'exportTradeInMonthly'])->name('trade-in.monthly.export');
    });
});

require __DIR__.'/settings.php';
