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
use App\Http\Controllers\Web\LeadsReportController;
use App\Http\Controllers\Web\MemoController;
use App\Http\Controllers\Web\OnboardingController;
use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\PackingSlipController;
use App\Http\Controllers\Web\PaymentListController;
use App\Http\Controllers\Web\ProductController;
use App\Http\Controllers\Web\ProductTypeController;
use App\Http\Controllers\Web\PurchaseOrderController;
use App\Http\Controllers\Web\RepairController;
use App\Http\Controllers\Web\RepairVendorPaymentController;
use App\Http\Controllers\Web\SalesReportController;
use App\Http\Controllers\Web\StoreController;
use App\Http\Controllers\Web\TagController;
use App\Http\Controllers\Web\TemplateController;
use App\Http\Controllers\Web\TemplateGeneratorController;
use App\Http\Controllers\Web\TransactionsReportController;
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

    // Account dashboard (for users who are owner/admin of multiple stores)
    Route::get('account/dashboard', [\App\Http\Controllers\Web\AccountDashboardController::class, 'index'])->name('account.dashboard');
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

    // Product routes - static routes must come before dynamic routes
    Route::middleware('permission:products.create')->group(function () {
        Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::post('products/generate-sku', [ProductController::class, 'generateSku'])->name('products.generate-sku');
        Route::post('products/preview-category-sku', [ProductController::class, 'previewCategorySku'])->name('products.preview-category-sku');
    });
    Route::middleware('permission:products.view')->group(function () {
        Route::get('products/lookup-barcode', [ProductController::class, 'lookupBarcode'])->name('products.lookup-barcode');
        Route::get('products/advanced-search', [\App\Http\Controllers\Web\AdvancedProductSearchController::class, 'index'])->name('products.advanced-search.index');
        Route::get('products/advanced-search/results', [\App\Http\Controllers\Web\AdvancedProductSearchController::class, 'searchPaginated'])->name('products.advanced-search.results');
        Route::get('products/advanced-search/modal', [\App\Http\Controllers\Web\AdvancedProductSearchController::class, 'search'])->name('products.advanced-search');
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::get('products/{product}/print-barcode', [ProductController::class, 'printBarcode'])->name('products.print-barcode');
    });
    Route::middleware('permission:products.update')->group(function () {
        Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::patch('products/{product}', [ProductController::class, 'update']);
        Route::post('products/bulk-update', [ProductController::class, 'bulkUpdate'])->name('products.bulk-update');
        Route::post('products/bulk-inline-update', [ProductController::class, 'bulkInlineUpdate'])->name('products.bulk-inline-update');
        Route::post('products/get-for-inline-edit', [ProductController::class, 'getForInlineEdit'])->name('products.get-for-inline-edit');
        // AI generation routes
        Route::post('products/{product}/generate-title', [ProductController::class, 'generateTitle'])->name('products.generate-title');
        Route::post('products/{product}/generate-description', [ProductController::class, 'generateDescription'])->name('products.generate-description');
    });
    Route::middleware('permission:products.delete')->group(function () {
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::post('products/bulk-action', [ProductController::class, 'bulkAction'])->name('products.bulk-action');
    });
    Route::middleware('permission:products.manage_images')->group(function () {
        Route::delete('products/{product}/images/{image}', [ProductController::class, 'deleteImage'])->name('products.delete-image');
        Route::post('products/{product}/images/{image}/primary', [ProductController::class, 'setPrimaryImage'])->name('products.set-primary-image');
    });

    // Custom Product routes (for stores with has_custom_product_module enabled)
    Route::middleware('permission:products.view')->group(function () {
        Route::get('products-custom', [CustomProductController::class, 'index'])->name('products-custom.index');
        Route::get('products-custom/{product}', [CustomProductController::class, 'show'])->name('products-custom.show');
        Route::get('products-custom/{product}/edit', [CustomProductController::class, 'edit'])->name('products-custom.edit');
    });

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

    // Templates - static routes must come before dynamic routes
    Route::middleware('permission:templates.create')->group(function () {
        Route::get('templates/create', [TemplateController::class, 'create'])->name('templates.create');
        Route::post('templates', [TemplateController::class, 'store'])->name('templates.store');
        Route::post('templates/{template}/duplicate', [TemplateController::class, 'duplicate'])->name('templates.duplicate');
        Route::post('templates-generator', [TemplateGeneratorController::class, 'store'])->name('templates.generator.store');
    });
    Route::middleware('permission:templates.view')->group(function () {
        Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::get('templates-generator', [TemplateGeneratorController::class, 'index'])->name('templates.generator');
        Route::post('templates-generator/preview', [TemplateGeneratorController::class, 'preview'])->name('templates.generator.preview');
        Route::post('templates-generator/preview-ebay', [TemplateGeneratorController::class, 'previewFromEbayCategory'])->name('templates.generator.preview-ebay');
        Route::get('templates/{template}', [TemplateController::class, 'show'])->name('templates.show');
    });
    Route::middleware('permission:templates.update')->group(function () {
        Route::get('templates/{template}/edit', [TemplateController::class, 'edit'])->name('templates.edit');
        Route::put('templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
        Route::patch('templates/{template}', [TemplateController::class, 'update']);
    });
    Route::delete('templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy')->middleware('permission:templates.delete');

    // Categories
    Route::middleware('permission:categories.view')->group(function () {
        Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('categories/{category}/template-fields', [CategoryController::class, 'templateFields'])->name('categories.template-fields');
        Route::get('categories/{category}/settings', [CategoryController::class, 'settings'])->name('categories.settings');
    });
    Route::middleware('permission:categories.create')->group(function () {
        Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    });
    Route::middleware('permission:categories.update')->group(function () {
        Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::post('categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
        Route::put('categories/{category}/settings', [CategoryController::class, 'updateSettings'])->name('categories.settings.update');
        Route::post('categories/{category}/preview-sku', [CategoryController::class, 'previewSku'])->name('categories.preview-sku');
        Route::post('categories/{category}/reset-sequence', [CategoryController::class, 'resetSequence'])->name('categories.reset-sequence');
    });
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy')->middleware('permission:categories.delete');

    // Product Types (leaf categories with additional settings)
    Route::middleware('permission:categories.view')->group(function () {
        Route::get('product-types', [ProductTypeController::class, 'index'])->name('product-types.index');
        Route::get('product-types/{category}/settings', [ProductTypeController::class, 'settings'])->name('product-types.settings');
        Route::get('product-types/{category}/attributes', [ProductTypeController::class, 'getAvailableAttributes'])->name('product-types.attributes');
    });
    Route::put('product-types/{category}/settings', [ProductTypeController::class, 'updateSettings'])->name('product-types.update-settings')->middleware('permission:categories.update');

    // Orders (Sales) - static routes must come before dynamic routes
    Route::middleware('permission:orders.create')->group(function () {
        Route::get('orders/create', [OrderController::class, 'createWizard'])->name('web.orders.create-wizard');
        Route::post('orders', [OrderController::class, 'storeFromWizard'])->name('web.orders.store');
        Route::post('orders/create-product', [OrderController::class, 'storeQuickProduct'])->name('web.orders.create-product');
    });
    Route::middleware('permission:orders.view')->group(function () {
        Route::get('orders', [OrderController::class, 'index'])->name('web.orders.index');
        Route::get('orders/search-products', [OrderController::class, 'searchProducts'])->name('web.orders.search-products');
        Route::get('orders/search-customers', [OrderController::class, 'searchCustomers'])->name('web.orders.search-customers');
        Route::get('orders/search-bucket-items', [OrderController::class, 'searchBucketItems'])->name('web.orders.search-bucket-items');
        Route::get('orders/lookup-barcode', [OrderController::class, 'lookupBarcode'])->name('web.orders.lookup-barcode');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('web.orders.show');
        Route::get('orders/{order}/print-invoice', [OrderController::class, 'printInvoice'])->name('web.orders.print-invoice');
    });
    Route::middleware('permission:orders.update')->group(function () {
        Route::patch('orders/{order}', [OrderController::class, 'update'])->name('web.orders.update');
        Route::patch('orders/{order}/customer', [OrderController::class, 'updateCustomer'])->name('web.orders.update-customer');
        Route::patch('orders/{order}/items/{item}', [OrderController::class, 'updateItem'])->name('web.orders.update-item');
        Route::delete('orders/{order}/items/{item}', [OrderController::class, 'removeItem'])->name('web.orders.remove-item');
        Route::post('orders/{order}/confirm', [OrderController::class, 'confirm'])->name('web.orders.confirm');
        Route::post('orders/{order}/sync-from-marketplace', [OrderController::class, 'syncFromMarketplace'])->name('web.orders.sync-from-marketplace');
        Route::post('orders/{order}/receive-payment', [OrderController::class, 'receivePayment'])->name('web.orders.receive-payment');
        Route::post('orders/{order}/complete', [OrderController::class, 'complete'])->name('web.orders.complete');
    });
    Route::middleware('permission:orders.fulfill')->group(function () {
        Route::post('orders/{order}/ship', [OrderController::class, 'ship'])->name('web.orders.ship');
        Route::post('orders/{order}/deliver', [OrderController::class, 'deliver'])->name('web.orders.deliver');
    });
    Route::middleware('permission:orders.manage_shipping')->group(function () {
        Route::post('orders/{order}/create-shipping-label', [OrderController::class, 'createShippingLabel'])->name('web.orders.create-shipping-label');
        Route::post('orders/{order}/push-to-shipstation', [OrderController::class, 'pushToShipStation'])->name('web.orders.push-to-shipstation');
    });
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('web.orders.cancel')->middleware('permission:orders.cancel');
    Route::delete('orders/{order}', [OrderController::class, 'destroy'])->name('web.orders.destroy')->middleware('permission:orders.delete');
    Route::post('orders/bulk-action', [OrderController::class, 'bulkAction'])->name('web.orders.bulk-action')->middleware('permission:orders.update');

    // Shipments
    Route::middleware('permission:orders.manage_shipping')->group(function () {
        Route::get('shipments', [\App\Http\Controllers\Web\ShipmentController::class, 'index'])->name('web.shipments.index');
        Route::get('shipments/{shippingLabel}/track', [\App\Http\Controllers\Web\ShipmentController::class, 'track'])->name('web.shipments.track');
        Route::get('shipments/{shippingLabel}/download', [\App\Http\Controllers\Web\ShipmentController::class, 'download'])->name('web.shipments.download');
        Route::post('shipments/{shippingLabel}/void', [\App\Http\Controllers\Web\ShipmentController::class, 'void'])->name('web.shipments.void');
        Route::post('shipments/bulk-action', [\App\Http\Controllers\Web\ShipmentController::class, 'bulkAction'])->name('web.shipments.bulk-action');
    });

    // Returns
    Route::middleware('permission:orders.refund')->group(function () {
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
    });

    // Transactions (Buys) - static routes must come before dynamic routes
    Route::middleware('permission:transactions.create')->group(function () {
        Route::get('transactions/buy', [\App\Http\Controllers\Web\TransactionController::class, 'createWizard'])->name('web.transactions.create-wizard');
        Route::post('transactions/buy', [\App\Http\Controllers\Web\TransactionController::class, 'storeFromWizard'])->name('web.transactions.store-wizard');
        Route::get('transactions/create', [\App\Http\Controllers\Web\TransactionController::class, 'create'])->name('web.transactions.create');
        Route::post('transactions', [\App\Http\Controllers\Web\TransactionController::class, 'store'])->name('web.transactions.store');

        // Quick Evaluation routes
        Route::get('transactions/quick-evaluation', [\App\Http\Controllers\Web\QuickEvaluationController::class, 'index'])->name('web.transactions.quick-evaluation');
        Route::post('transactions/quick-evaluation', [\App\Http\Controllers\Web\QuickEvaluationController::class, 'store'])->name('web.transactions.quick-evaluation.store');
        Route::put('transactions/quick-evaluation/{evaluation}', [\App\Http\Controllers\Web\QuickEvaluationController::class, 'update'])->name('web.transactions.quick-evaluation.update');
        Route::post('transactions/quick-evaluation/similar-items', [\App\Http\Controllers\Web\QuickEvaluationController::class, 'searchSimilarItems'])->name('web.transactions.quick-evaluation.similar-items');
        Route::post('transactions/quick-evaluation/ai-research', [\App\Http\Controllers\Web\QuickEvaluationController::class, 'generateAiResearch'])->name('web.transactions.quick-evaluation.ai-research');
        Route::post('transactions/quick-evaluation/web-search', [\App\Http\Controllers\Web\QuickEvaluationController::class, 'webPriceSearch'])->name('web.transactions.quick-evaluation.web-search');
        Route::post('transactions/quick-evaluation/{evaluation}/images', [\App\Http\Controllers\Web\QuickEvaluationController::class, 'uploadImages'])->name('web.transactions.quick-evaluation.upload-images');
        Route::post('transactions/quick-evaluation/{evaluation}/convert', [\App\Http\Controllers\Web\QuickEvaluationController::class, 'convertToTransaction'])->name('web.transactions.quick-evaluation.convert');
        Route::delete('transactions/quick-evaluation/{evaluation}', [\App\Http\Controllers\Web\QuickEvaluationController::class, 'destroy'])->name('web.transactions.quick-evaluation.destroy');
    });
    Route::middleware('permission:transactions.view')->group(function () {
        Route::get('transactions', [\App\Http\Controllers\Web\TransactionController::class, 'index'])->name('web.transactions.index');
        Route::post('transactions/export', [\App\Http\Controllers\Web\TransactionController::class, 'export'])->name('web.transactions.export');
        Route::get('transactions/{transaction}', [\App\Http\Controllers\Web\TransactionController::class, 'show'])->name('web.transactions.show');
        Route::get('transactions/{transaction}/print-barcode', [\App\Http\Controllers\Web\TransactionController::class, 'printBarcode'])->name('web.transactions.print-barcode');
        Route::get('transactions/{transaction}/print-invoice', [\App\Http\Controllers\Web\TransactionController::class, 'printInvoice'])->name('web.transactions.print-invoice');
    });
    Route::middleware('permission:transactions.update')->group(function () {
        Route::get('transactions/{transaction}/edit', [\App\Http\Controllers\Web\TransactionController::class, 'edit'])->name('web.transactions.edit');
        Route::put('transactions/{transaction}', [\App\Http\Controllers\Web\TransactionController::class, 'update'])->name('web.transactions.update');
        Route::patch('transactions/{transaction}', [\App\Http\Controllers\Web\TransactionController::class, 'update']);
        Route::post('transactions/{transaction}/change-status', [\App\Http\Controllers\Web\TransactionController::class, 'changeStatus'])->name('web.transactions.change-status');
        Route::post('transactions/bulk-action', [\App\Http\Controllers\Web\TransactionController::class, 'bulkAction'])->name('web.transactions.bulk-action');
    });
    Route::middleware('permission:transactions.submit_offer')->group(function () {
        Route::post('transactions/{transaction}/offer', [\App\Http\Controllers\Web\TransactionController::class, 'submitOffer'])->name('web.transactions.offer');
    });
    Route::middleware('permission:transactions.accept_offer')->group(function () {
        Route::post('transactions/{transaction}/accept', [\App\Http\Controllers\Web\TransactionController::class, 'acceptOffer'])->name('web.transactions.accept');
    });
    Route::middleware('permission:transactions.decline_offer')->group(function () {
        Route::post('transactions/{transaction}/decline', [\App\Http\Controllers\Web\TransactionController::class, 'declineOffer'])->name('web.transactions.decline');
    });
    Route::middleware('permission:transactions.process_payment')->group(function () {
        Route::post('transactions/{transaction}/process-payment', [\App\Http\Controllers\Web\TransactionController::class, 'processPayment'])->name('web.transactions.process-payment');
    });
    Route::delete('transactions/{transaction}', [\App\Http\Controllers\Web\TransactionController::class, 'destroy'])->name('web.transactions.destroy')->middleware('permission:transactions.delete');

    // Transaction Item Detail routes
    Route::prefix('transactions/{transaction}/items/{item}')->name('web.transactions.items.')->middleware('permission:transactions.view')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\TransactionItemController::class, 'show'])->name('show');
        Route::get('/similar', [\App\Http\Controllers\Web\TransactionItemController::class, 'similarItems'])->name('similar');
    });
    Route::prefix('transactions/{transaction}/items/{item}')->name('web.transactions.items.')->middleware('permission:transactions.update')->group(function () {
        Route::get('/edit', [\App\Http\Controllers\Web\TransactionItemController::class, 'edit'])->name('edit');
        Route::put('/', [\App\Http\Controllers\Web\TransactionItemController::class, 'update'])->name('update');
        Route::patch('/quick-update', [\App\Http\Controllers\Web\TransactionItemController::class, 'quickUpdate'])->name('quick-update');
        Route::post('/images', [\App\Http\Controllers\Web\TransactionItemController::class, 'uploadImages'])->name('upload-images');
        Route::delete('/images/{image}', [\App\Http\Controllers\Web\TransactionItemController::class, 'deleteImage'])->name('delete-image');
        Route::post('/move-to-inventory', [\App\Http\Controllers\Web\TransactionItemController::class, 'moveToInventory'])->name('move-to-inventory');
        Route::post('/move-to-bucket', [\App\Http\Controllers\Web\TransactionItemController::class, 'moveToBucket'])->name('move-to-bucket');
        Route::post('/review', [\App\Http\Controllers\Web\TransactionItemController::class, 'review'])->name('review');
        Route::post('/ai-research', [\App\Http\Controllers\Web\TransactionItemController::class, 'generateAiResearch'])->name('ai-research');
        Route::post('/auto-populate-fields', [\App\Http\Controllers\Web\TransactionItemController::class, 'autoPopulateFields'])->name('auto-populate-fields');
        Route::post('/web-search', [\App\Http\Controllers\Web\TransactionItemController::class, 'webPriceSearch'])->name('web-search');
        Route::post('/share', [\App\Http\Controllers\Web\TransactionItemController::class, 'shareWithTeam'])->name('share');
        Route::post('/chat', [\App\Http\Controllers\Web\TransactionItemController::class, 'chatStream'])->name('chat');
    });

    // Online transaction workflow routes
    Route::middleware('permission:transactions.status_change')->group(function () {
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
        Route::post('transactions/{transaction}/reject-kit', [\App\Http\Controllers\Web\TransactionController::class, 'rejectKit'])->name('web.transactions.reject-kit');
        Route::post('transactions/{transaction}/initiate-return', [\App\Http\Controllers\Web\TransactionController::class, 'initiateReturn'])->name('web.transactions.initiate-return');
        Route::post('transactions/{transaction}/reset-to-items-reviewed', [\App\Http\Controllers\Web\TransactionController::class, 'resetToItemsReviewed'])->name('web.transactions.reset-to-items-reviewed');
        Route::post('transactions/{transaction}/reopen-offer', [\App\Http\Controllers\Web\TransactionController::class, 'reopenOffer'])->name('web.transactions.reopen-offer');
        Route::post('transactions/{transaction}/cancel-return', [\App\Http\Controllers\Web\TransactionController::class, 'cancelReturn'])->name('web.transactions.cancel-return');
        Route::post('transactions/{transaction}/undo-payment', [\App\Http\Controllers\Web\TransactionController::class, 'undoPayment'])->name('web.transactions.undo-payment');
    });

    // Shipping Labels for transactions
    Route::middleware('permission:transactions.update')->group(function () {
        Route::post('transactions/{transaction}/create-outbound-label', [\App\Http\Controllers\Web\TransactionController::class, 'createOutboundLabel'])->name('web.transactions.create-outbound-label');
        Route::get('transactions/{transaction}/print-outbound-label', [\App\Http\Controllers\Web\TransactionController::class, 'printOutboundLabel'])->name('web.transactions.print-outbound-label');
        Route::get('transactions/{transaction}/outbound-label-zpl', [\App\Http\Controllers\Web\TransactionController::class, 'getOutboundLabelZpl'])->name('web.transactions.outbound-label-zpl');
        Route::post('transactions/{transaction}/create-return-label', [\App\Http\Controllers\Web\TransactionController::class, 'createReturnLabel'])->name('web.transactions.create-return-label');
        Route::get('transactions/{transaction}/print-return-label', [\App\Http\Controllers\Web\TransactionController::class, 'printReturnLabel'])->name('web.transactions.print-return-label');
        Route::get('transactions/{transaction}/return-label-zpl', [\App\Http\Controllers\Web\TransactionController::class, 'getReturnLabelZpl'])->name('web.transactions.return-label-zpl');
        Route::put('transactions/{transaction}/shipping-address', [\App\Http\Controllers\Web\TransactionController::class, 'updateShippingAddress'])->name('web.transactions.update-shipping-address');
    });

    // PayPal Payouts
    Route::middleware('permission:transactions.process_payment')->group(function () {
        Route::post('transactions/{transaction}/send-payout', [\App\Http\Controllers\Web\TransactionController::class, 'sendPayout'])->name('web.transactions.send-payout');
        Route::post('transactions/{transaction}/refresh-payout-status', [\App\Http\Controllers\Web\TransactionController::class, 'refreshPayoutStatus'])->name('web.transactions.refresh-payout-status');
    });

    // Transaction Attachments (ID photos, documentation)
    Route::middleware('permission:transactions.update')->group(function () {
        Route::post('transactions/{transaction}/attachments', [\App\Http\Controllers\Web\TransactionController::class, 'uploadAttachments'])->name('web.transactions.upload-attachments');
        Route::delete('transactions/{transaction}/attachments/{image}', [\App\Http\Controllers\Web\TransactionController::class, 'deleteAttachment'])->name('web.transactions.delete-attachment');
    });

    // SMS Messaging
    Route::post('transactions/{transaction}/send-sms', [\App\Http\Controllers\Web\TransactionController::class, 'sendSms'])->name('web.transactions.send-sms')->middleware('permission:transactions.update');

    // Online Buys Workflow - Bulk Operations (Stores 43/44)
    Route::middleware('permission:transactions.update')->group(function () {
        // Bulk shipping labels
        Route::post('transactions/bulk-generate-labels', [\App\Http\Controllers\Web\TransactionController::class, 'bulkGenerateLabels'])->name('web.transactions.bulk-generate-labels');
        // Bulk mark paid
        Route::post('transactions/bulk-mark-paid', [\App\Http\Controllers\Web\TransactionController::class, 'bulkMarkPaid'])->name('web.transactions.bulk-mark-paid');
        // Multiple offers
        Route::post('transactions/{transaction}/multiple-offers', [\App\Http\Controllers\Web\TransactionController::class, 'submitMultipleOffers'])->name('web.transactions.multiple-offers');
        // Custom SMS with templates
        Route::post('transactions/{transaction}/custom-sms', [\App\Http\Controllers\Web\TransactionController::class, 'sendCustomSms'])->name('web.transactions.custom-sms');
        Route::get('transactions/{transaction}/sms-templates', [\App\Http\Controllers\Web\TransactionController::class, 'getSmsTemplates'])->name('web.transactions.sms-templates');
        // Offer emails with images
        Route::post('transactions/{transaction}/offer-email', [\App\Http\Controllers\Web\TransactionController::class, 'sendOfferEmail'])->name('web.transactions.offer-email');
        Route::post('transactions/{transaction}/offer-email/preview', [\App\Http\Controllers\Web\TransactionController::class, 'previewOfferEmail'])->name('web.transactions.offer-email-preview');
        Route::get('transactions/{transaction}/offer-email/images', [\App\Http\Controllers\Web\TransactionController::class, 'getOfferEmailImages'])->name('web.transactions.offer-email-images');
    });

    // Payout Exports (Online Buys Workflow - Stores 43/44)
    Route::middleware('permission:transactions.view')->group(function () {
        Route::get('payout-exports', [\App\Http\Controllers\Web\PayoutExportController::class, 'index'])->name('web.payout-exports.index');
        Route::get('payout-exports/csv', [\App\Http\Controllers\Web\PayoutExportController::class, 'exportCsv'])->name('web.payout-exports.csv');
        Route::get('payout-exports/paypal', [\App\Http\Controllers\Web\PayoutExportController::class, 'exportPayPal'])->name('web.payout-exports.paypal');
        Route::post('payout-exports/preview', [\App\Http\Controllers\Web\PayoutExportController::class, 'preview'])->name('web.payout-exports.preview');
        Route::get('payout-exports/{export}/download', [\App\Http\Controllers\Web\PayoutExportController::class, 'download'])->name('web.payout-exports.download');
        Route::delete('payout-exports/{export}', [\App\Http\Controllers\Web\PayoutExportController::class, 'destroy'])->name('web.payout-exports.destroy');
    });

    // SMS Message Center
    Route::middleware('permission:transactions.view')->group(function () {
        Route::get('messages', [\App\Http\Controllers\Web\SmsController::class, 'index'])->name('web.sms.index');
        Route::get('messages/{id}', [\App\Http\Controllers\Web\SmsController::class, 'show'])->name('web.sms.show');
        Route::post('messages/{id}/mark-read', [\App\Http\Controllers\Web\SmsController::class, 'markAsRead'])->name('web.sms.mark-read');
        Route::post('messages/mark-read', [\App\Http\Controllers\Web\SmsController::class, 'markMultipleAsRead'])->name('web.sms.mark-multiple-read');
    });

    // Buys (Completed Transactions with Payments)
    Route::middleware('permission:transactions.view')->group(function () {
        Route::get('buys', [BuysController::class, 'index'])->name('buys.index');
        Route::get('buys/items', [BuysController::class, 'items'])->name('buys.items');
    });

    // Leads Dashboard - Tracks transactions until payment is processed, then they become "buys"
    Route::middleware('permission:transactions.view')->group(function () {
        Route::get('leads', [\App\Http\Controllers\Web\LeadsDashboardController::class, 'index'])->name('leads.index');
        Route::get('leads/status/{status}', [\App\Http\Controllers\Web\LeadsDashboardController::class, 'byStatus'])->name('leads.by-status');
        Route::get('leads/{transaction}', [\App\Http\Controllers\Web\LeadsDashboardController::class, 'show'])->name('leads.show');
    });

    // Buckets (Junk items without SKUs)
    Route::middleware('permission:buckets.view')->group(function () {
        Route::get('buckets', [BucketsController::class, 'index'])->name('buckets.index');
        Route::get('buckets/search', [BucketsController::class, 'search'])->name('buckets.search');
        Route::get('buckets/search-customers', [BucketsController::class, 'searchCustomers'])->name('buckets.search-customers');
        Route::get('buckets/{bucket}', [BucketsController::class, 'show'])->name('buckets.show');
    });
    Route::middleware('permission:buckets.create')->group(function () {
        Route::post('buckets', [BucketsController::class, 'store'])->name('buckets.store');
        Route::post('buckets/{bucket}/items', [BucketsController::class, 'addItem'])->name('buckets.add-item');
    });
    Route::middleware('permission:buckets.update')->group(function () {
        Route::put('buckets/{bucket}', [BucketsController::class, 'update'])->name('buckets.update');
        Route::post('buckets/{bucket}/create-sale', [BucketsController::class, 'createSale'])->name('buckets.create-sale');
    });
    Route::middleware('permission:buckets.delete')->group(function () {
        Route::delete('buckets/{bucket}', [BucketsController::class, 'destroy'])->name('buckets.destroy');
        Route::delete('bucket-items/{bucketItem}', [BucketsController::class, 'removeItem'])->name('bucket-items.destroy');
    });

    // Customers
    Route::middleware('permission:customers.view')->group(function () {
        Route::get('customers', [\App\Http\Controllers\Web\CustomerController::class, 'index'])->name('web.customers.index');
        Route::get('customers/{customer}', [\App\Http\Controllers\Web\CustomerController::class, 'show'])->name('web.customers.show');
    });
    Route::middleware('permission:customers.update')->group(function () {
        Route::put('customers/{customer}', [\App\Http\Controllers\Web\CustomerController::class, 'update'])->name('web.customers.update');
        Route::post('customers/{customer}/documents', [\App\Http\Controllers\Web\CustomerController::class, 'uploadDocument'])->name('web.customers.upload-document');
        Route::delete('customers/{customer}/documents/{document}', [\App\Http\Controllers\Web\CustomerController::class, 'deleteDocument'])->name('web.customers.delete-document');
        Route::post('customers/{customer}/addresses', [\App\Http\Controllers\Web\CustomerController::class, 'storeAddress'])->name('web.customers.store-address');
        Route::put('customers/{customer}/addresses/{address}', [\App\Http\Controllers\Web\CustomerController::class, 'updateAddress'])->name('web.customers.update-address');
        Route::delete('customers/{customer}/addresses/{address}', [\App\Http\Controllers\Web\CustomerController::class, 'deleteAddress'])->name('web.customers.delete-address');
    });

    // Lead Sources
    Route::get('lead-sources', [\App\Http\Controllers\Web\LeadSourceController::class, 'index'])->name('web.lead-sources.index');
    Route::post('lead-sources', [\App\Http\Controllers\Web\LeadSourceController::class, 'store'])->name('web.lead-sources.store');

    // Memos (Consignment) - static routes must come before dynamic routes
    Route::middleware('permission:memos.create')->group(function () {
        Route::get('memos/create', [MemoController::class, 'createWizard'])->name('web.memos.create-wizard');
        Route::post('memos', [MemoController::class, 'storeFromWizard'])->name('web.memos.store');
        Route::post('memos/create-product', [MemoController::class, 'storeQuickProduct'])->name('web.memos.create-product');
    });
    Route::middleware('permission:memos.view')->group(function () {
        Route::get('memos', [MemoController::class, 'index'])->name('web.memos.index');
        Route::get('memos/search-products', [MemoController::class, 'searchProducts'])->name('web.memos.search-products');
        Route::get('memos/search-vendors', [MemoController::class, 'searchVendors'])->name('web.memos.search-vendors');
        Route::get('memos/{memo}', [MemoController::class, 'show'])->name('web.memos.show');
    });
    Route::middleware('permission:memos.update')->group(function () {
        Route::patch('memos/{memo}', [MemoController::class, 'update'])->name('web.memos.update');
        Route::post('memos/{memo}/send-to-vendor', [MemoController::class, 'sendToVendor'])->name('web.memos.send-to-vendor');
        Route::post('memos/{memo}/mark-received', [MemoController::class, 'markReceived'])->name('web.memos.mark-received');
        Route::post('memos/{memo}/mark-returned', [MemoController::class, 'markReturned'])->name('web.memos.mark-returned');
        Route::post('memos/{memo}/receive-payment', [MemoController::class, 'receivePayment'])->name('web.memos.receive-payment');
        Route::post('memos/{memo}/return-item/{item}', [MemoController::class, 'returnItem'])->name('web.memos.return-item');
        Route::post('memos/{memo}/add-item', [MemoController::class, 'addItem'])->name('web.memos.add-item');
        Route::patch('memos/{memo}/items/{item}', [MemoController::class, 'updateItem'])->name('web.memos.update-item');
        Route::post('memos/{memo}/change-status', [MemoController::class, 'changeStatus'])->name('web.memos.change-status');
        Route::post('memos/bulk-action', [MemoController::class, 'bulkAction'])->name('web.memos.bulk-action');
    });
    Route::post('memos/{memo}/cancel', [MemoController::class, 'cancel'])->name('web.memos.cancel')->middleware('permission:memos.cancel');
    Route::delete('memos/{memo}', [MemoController::class, 'destroy'])->name('web.memos.destroy')->middleware('permission:memos.delete');

    // Layaways - static routes must come before dynamic routes
    Route::middleware('permission:layaways.create')->group(function () {
        Route::get('layaways/create', [LayawayController::class, 'createWizard'])->name('web.layaways.create-wizard');
        Route::post('layaways', [LayawayController::class, 'storeFromWizard'])->name('web.layaways.store');
    });
    Route::middleware('permission:layaways.view')->group(function () {
        Route::get('layaways', [LayawayController::class, 'index'])->name('web.layaways.index');
        Route::get('layaways/search-products', [LayawayController::class, 'searchProducts'])->name('web.layaways.search-products');
        Route::get('layaways/search-customers', [LayawayController::class, 'searchCustomers'])->name('web.layaways.search-customers');
        Route::get('layaways/{layaway}', [LayawayController::class, 'show'])->name('web.layaways.show');
    });
    Route::middleware('permission:layaways.update')->group(function () {
        Route::patch('layaways/{layaway}', [LayawayController::class, 'update'])->name('web.layaways.update');
        Route::post('layaways/{layaway}/activate', [LayawayController::class, 'activate'])->name('web.layaways.activate');
        Route::post('layaways/{layaway}/complete', [LayawayController::class, 'complete'])->name('web.layaways.complete');
        Route::post('layaways/{layaway}/receive-payment', [LayawayController::class, 'receivePayment'])->name('web.layaways.receive-payment');
        Route::post('layaways/{layaway}/add-item', [LayawayController::class, 'addItem'])->name('web.layaways.add-item');
        Route::delete('layaways/{layaway}/items/{item}', [LayawayController::class, 'removeItem'])->name('web.layaways.remove-item');
        Route::post('layaways/bulk-action', [LayawayController::class, 'bulkAction'])->name('web.layaways.bulk-action');
    });
    Route::post('layaways/{layaway}/cancel', [LayawayController::class, 'cancel'])->name('web.layaways.cancel')->middleware('permission:layaways.cancel');
    Route::delete('layaways/{layaway}', [LayawayController::class, 'destroy'])->name('web.layaways.destroy')->middleware('permission:layaways.delete');

    // Repair management - static routes must come before dynamic routes
    Route::middleware('permission:repairs.create')->group(function () {
        Route::get('repairs/create', [RepairController::class, 'createWizard'])->name('web.repairs.create-wizard');
        Route::post('repairs', [RepairController::class, 'storeFromWizard'])->name('web.repairs.store');
    });
    Route::middleware('permission:repairs.view')->group(function () {
        Route::get('repairs', [RepairController::class, 'index'])->name('web.repairs.index');
        Route::get('repairs/search-customers', [RepairController::class, 'searchCustomers'])->name('web.repairs.search-customers');
        Route::get('repairs/search-vendors', [RepairController::class, 'searchVendors'])->name('web.repairs.search-vendors');
        Route::get('repairs/{repair}', [RepairController::class, 'show'])->name('web.repairs.show');
    });
    Route::middleware('permission:repairs.update')->group(function () {
        Route::patch('repairs/{repair}', [RepairController::class, 'update'])->name('web.repairs.update');
        Route::post('repairs/{repair}/send-to-vendor', [RepairController::class, 'sendToVendor'])->name('web.repairs.send-to-vendor');
        Route::post('repairs/{repair}/mark-received', [RepairController::class, 'markReceived'])->name('web.repairs.mark-received');
        Route::post('repairs/{repair}/mark-completed', [RepairController::class, 'markCompleted'])->name('web.repairs.mark-completed');
        Route::post('repairs/{repair}/receive-payment', [RepairController::class, 'receivePayment'])->name('web.repairs.receive-payment');
        Route::post('repairs/{repair}/change-status', [RepairController::class, 'changeStatus'])->name('web.repairs.change-status');
        Route::patch('repairs/{repair}/items/{item}', [RepairController::class, 'updateItem'])->name('web.repairs.update-item');
        Route::delete('repairs/{repair}/items/{item}', [RepairController::class, 'removeItem'])->name('web.repairs.remove-item');
        Route::post('repairs/bulk-action', [RepairController::class, 'bulkAction'])->name('web.repairs.bulk-action');
    });
    Route::post('repairs/{repair}/cancel', [RepairController::class, 'cancel'])->name('web.repairs.cancel')->middleware('permission:repairs.cancel');
    Route::delete('repairs/{repair}', [RepairController::class, 'destroy'])->name('web.repairs.destroy')->middleware('permission:repairs.delete');

    // Repair Vendor Payments
    Route::middleware('permission:repairs.view')->group(function () {
        Route::get('repair-vendor-payments', [RepairVendorPaymentController::class, 'index'])->name('web.repair-vendor-payments.index');
    });
    Route::middleware('permission:repairs.update')->group(function () {
        Route::post('repairs/{repair}/vendor-payments', [RepairVendorPaymentController::class, 'store'])->name('web.repair-vendor-payments.store');
        Route::put('repair-vendor-payments/{payment}', [RepairVendorPaymentController::class, 'update'])->name('web.repair-vendor-payments.update');
        Route::delete('repair-vendor-payments/{payment}', [RepairVendorPaymentController::class, 'destroy'])->name('web.repair-vendor-payments.destroy');
        Route::get('repair-vendor-payments/{payment}/attachment', [RepairVendorPaymentController::class, 'downloadAttachment'])->name('web.repair-vendor-payments.attachment');
    });

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
            Route::post('terminal-checkout', [\App\Http\Controllers\PaymentController::class, 'terminalCheckout'])
                ->name("{$type}.payment.terminal-checkout")
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
    Route::middleware('permission:warehouses.view')->group(function () {
        Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
    });
    Route::middleware('permission:warehouses.create')->group(function () {
        Route::get('warehouses/create', [WarehouseController::class, 'create'])->name('warehouses.create');
        Route::post('warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
    });
    Route::middleware('permission:warehouses.update')->group(function () {
        Route::get('warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])->name('warehouses.edit');
        Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
        Route::patch('warehouses/{warehouse}', [WarehouseController::class, 'update']);
        Route::post('warehouses/{warehouse}/make-default', [WarehouseController::class, 'makeDefault'])->name('warehouses.make-default');
    });
    Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy')->middleware('permission:warehouses.delete');

    // Vendor management - use 'web.' prefix to avoid conflict with API routes
    // Static routes must come before dynamic routes
    Route::middleware('permission:vendors.view')->group(function () {
        Route::get('vendors', [VendorController::class, 'index'])->name('web.vendors.index');
        Route::get('vendors/export', [VendorController::class, 'export'])->name('web.vendors.export');
        Route::get('vendors/{vendor}', [VendorController::class, 'show'])->name('web.vendors.show');
    });
    Route::post('vendors', [VendorController::class, 'store'])->name('web.vendors.store')->middleware('permission:vendors.create');
    Route::put('vendors/{vendor}', [VendorController::class, 'update'])->name('web.vendors.update')->middleware('permission:vendors.update');
    Route::delete('vendors/{vendor}', [VendorController::class, 'destroy'])->name('web.vendors.destroy')->middleware('permission:vendors.delete');

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

    // Inventory
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index')->middleware('permission:inventory.view');
    Route::post('inventory/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust')->middleware('permission:inventory.adjust');

    // Integrations
    Route::middleware('permission:integrations.view')->group(function () {
        Route::get('integrations', [IntegrationsController::class, 'index'])->name('integrations.index');
    });
    Route::middleware('permission:integrations.manage')->group(function () {
        Route::post('integrations/fedex', [IntegrationsController::class, 'storeFedex'])->name('integrations.fedex.store');
        Route::post('integrations/twilio', [IntegrationsController::class, 'storeTwilio'])->name('integrations.twilio.store');
        Route::post('integrations/gia', [IntegrationsController::class, 'storeGia'])->name('integrations.gia.store');
        Route::post('integrations/shipstation', [IntegrationsController::class, 'storeShipStation'])->name('integrations.shipstation.store');
        Route::post('integrations/anthropic', [IntegrationsController::class, 'storeAnthropic'])->name('integrations.anthropic.store');
        Route::post('integrations/serpapi', [IntegrationsController::class, 'storeSerpApi'])->name('integrations.serpapi.store');
        Route::post('integrations/rapnet', [IntegrationsController::class, 'storeRapnet'])->name('integrations.rapnet.store');
        Route::delete('integrations/{integration}', [IntegrationsController::class, 'destroy'])->name('integrations.destroy');

        // Platform connections (Shopify, eBay, etc.)
        Route::post('integrations/platforms/shopify', [IntegrationsController::class, 'storeShopify'])->name('integrations.shopify.store');
        Route::post('integrations/platforms/{platform}/test', [IntegrationsController::class, 'testPlatform'])->name('integrations.platforms.test');
        Route::delete('integrations/platforms/{platform}', [IntegrationsController::class, 'destroyPlatform'])->name('integrations.platforms.destroy');
    });

    // Platform Listings
    Route::middleware('permission:products.view')->group(function () {
        Route::get('listings', [\App\Http\Controllers\Web\PlatformListingController::class, 'overview'])->name('listings.index');
        Route::get('products/{product}/listings', [\App\Http\Controllers\Web\PlatformListingController::class, 'index'])->name('products.listings.index');
        Route::get('products/{product}/listings/{marketplace}/preview', [\App\Http\Controllers\Web\PlatformListingController::class, 'preview'])->name('products.listings.preview');
        // Full platform listing page
        Route::get('products/{product}/platforms/{marketplace}', [\App\Http\Controllers\Web\ProductPlatformController::class, 'show'])->name('products.platforms.show');
        Route::post('products/{product}/platforms/{marketplace}/preview', [\App\Http\Controllers\Web\ProductPlatformController::class, 'preview'])->name('products.platforms.preview');
    });
    Route::middleware('permission:products.update')->group(function () {
        Route::patch('products/{product}/listings/{listing}/price', [\App\Http\Controllers\Web\PlatformListingController::class, 'updatePrice'])->name('products.listings.update-price');
        Route::post('products/{product}/channels/{channel}/price', [\App\Http\Controllers\Web\PlatformListingController::class, 'setChannelPrice'])->name('products.channels.set-price');
        Route::post('products/{product}/listings/{marketplace}/publish', [\App\Http\Controllers\Web\PlatformListingController::class, 'publish'])->name('products.listings.publish');
        Route::delete('products/{product}/listings/{marketplace}', [\App\Http\Controllers\Web\PlatformListingController::class, 'unpublish'])->name('products.listings.unpublish');
        Route::post('products/{product}/listings/{marketplace}/relist', [\App\Http\Controllers\Web\PlatformListingController::class, 'relist'])->name('products.listings.relist');
        Route::put('products/{product}/listings/{marketplace}/override', [\App\Http\Controllers\Web\PlatformListingController::class, 'updateOverride'])->name('products.listings.override');
        Route::post('products/{product}/listings/{marketplace}/sync', [\App\Http\Controllers\Web\PlatformListingController::class, 'sync'])->name('products.listings.sync');
        // Full platform listing page updates
        Route::put('products/{product}/platforms/{marketplace}', [\App\Http\Controllers\Web\ProductPlatformController::class, 'update'])->name('products.platforms.update');
        Route::post('products/{product}/platforms/{marketplace}/publish', [\App\Http\Controllers\Web\ProductPlatformController::class, 'publish'])->name('products.platforms.publish');
        Route::delete('products/{product}/platforms/{marketplace}', [\App\Http\Controllers\Web\ProductPlatformController::class, 'unpublish'])->name('products.platforms.unpublish');
        Route::post('products/{product}/platforms/{marketplace}/relist', [\App\Http\Controllers\Web\ProductPlatformController::class, 'relist'])->name('products.platforms.relist');
        Route::post('products/{product}/platforms/{marketplace}/sync', [\App\Http\Controllers\Web\ProductPlatformController::class, 'sync'])->name('products.platforms.sync');
    });

    // Sales Channel Listings (for In Store and other local channels)
    Route::middleware('permission:products.view')->group(function () {
        Route::get('products/{product}/channels/{channel}', [\App\Http\Controllers\Web\ProductChannelController::class, 'show'])->name('products.channels.show');
        Route::get('products/{product}/channel-listings', [\App\Http\Controllers\Web\ProductChannelController::class, 'listings'])->name('products.channel-listings');
    });
    Route::middleware('permission:products.update')->group(function () {
        Route::put('products/{product}/channels/{channel}', [\App\Http\Controllers\Web\ProductChannelController::class, 'update'])->name('products.channels.update');
        Route::post('products/{product}/channels/{channel}/publish', [\App\Http\Controllers\Web\ProductChannelController::class, 'publish'])->name('products.channels.publish');
        Route::delete('products/{product}/channels/{channel}', [\App\Http\Controllers\Web\ProductChannelController::class, 'unpublish'])->name('products.channels.unpublish');
        Route::post('products/{product}/channels/{channel}/toggle-not-for-sale', [\App\Http\Controllers\Web\ProductChannelController::class, 'toggleNotForSale'])->name('products.channels.toggle-not-for-sale');
        Route::post('products/{product}/channels/{channel}/sync', [\App\Http\Controllers\Web\ProductChannelController::class, 'sync'])->name('products.channels.sync');
        // Bulk actions
        Route::post('products/{product}/list-all-platforms', [\App\Http\Controllers\Web\ProductChannelController::class, 'listOnAllPlatforms'])->name('products.list-all-platforms');
        Route::post('products/{product}/sync-all-listings', [\App\Http\Controllers\Web\ProductChannelController::class, 'syncAll'])->name('products.sync-all-listings');
    });

    // Template Platform Mappings
    Route::middleware('permission:templates.view')->group(function () {
        Route::get('settings/template-mappings', [\App\Http\Controllers\Web\TemplateMappingController::class, 'index'])->name('settings.template-mappings.index');
        Route::get('settings/template-mappings/{template}/{platform}', [\App\Http\Controllers\Web\TemplateMappingController::class, 'show'])->name('settings.template-mappings.show');
        Route::get('settings/template-mappings/{template}/{platform}/fields', [\App\Http\Controllers\Web\TemplateMappingController::class, 'templatePlatformFields'])->name('settings.template-mappings.fields');
        Route::get('settings/platform-fields/{platform}', [\App\Http\Controllers\Web\TemplateMappingController::class, 'platformFields'])->name('settings.platform-fields');
    });
    Route::middleware('permission:templates.update')->group(function () {
        Route::post('settings/template-mappings/{template}/{platform}/suggest', [\App\Http\Controllers\Web\TemplateMappingController::class, 'suggest'])->name('settings.template-mappings.suggest');
        Route::put('settings/template-mappings/{template}/{platform}', [\App\Http\Controllers\Web\TemplateMappingController::class, 'update'])->name('settings.template-mappings.update');
        Route::delete('settings/template-mappings/{template}/{platform}', [\App\Http\Controllers\Web\TemplateMappingController::class, 'destroy'])->name('settings.template-mappings.destroy');
    });

    // Invoices - requires view permission for relevant categories
    Route::middleware('permission:orders.view,repairs.view,memos.view')->group(function () {
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('invoices/{invoice}/print', [InvoiceController::class, 'printInvoice'])->name('invoices.print');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'downloadPdf'])->name('invoices.pdf');
        Route::get('invoices/{invoice}/pdf/stream', [InvoiceController::class, 'streamPdf'])->name('invoices.pdf.stream');
    });

    // Payments - requires view permission for relevant categories
    Route::middleware('permission:orders.view,repairs.view,memos.view,transactions.view')->group(function () {
        Route::get('payments', [PaymentListController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}', [PaymentListController::class, 'show'])->name('payments.show');
    });

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
    Route::prefix('reports/sales')->name('reports.sales.')->middleware('permission:reports.view')->group(function () {
        Route::get('daily', [SalesReportController::class, 'daily'])->name('daily');
        Route::get('daily/export', [SalesReportController::class, 'exportDaily'])->name('daily.export');
        Route::get('daily-items', [SalesReportController::class, 'dailyItems'])->name('daily-items');
        Route::get('daily-items/export', [SalesReportController::class, 'exportDailyItems'])->name('daily-items.export');
        Route::get('monthly', [SalesReportController::class, 'monthly'])->name('monthly');
        Route::get('monthly/export', [SalesReportController::class, 'exportMonthly'])->name('monthly.export');
        Route::get('mtd', [SalesReportController::class, 'monthToDate'])->name('mtd');
        Route::get('mtd/export', [SalesReportController::class, 'exportMonthToDate'])->name('mtd.export');
    });

    // Inventory Reports
    Route::prefix('reports/inventory')->name('reports.inventory.')->middleware('permission:reports.view')->group(function () {
        Route::get('/', [InventoryReportController::class, 'index'])->name('index');
        Route::get('export', [InventoryReportController::class, 'export'])->name('export');
        Route::get('weekly', [InventoryReportController::class, 'weekly'])->name('weekly');
        Route::get('weekly/export', [InventoryReportController::class, 'exportWeekly'])->name('weekly.export');
        Route::get('monthly', [InventoryReportController::class, 'monthly'])->name('monthly');
        Route::get('monthly/export', [InventoryReportController::class, 'exportMonthly'])->name('monthly.export');
        Route::get('yearly', [InventoryReportController::class, 'yearly'])->name('yearly');
        Route::get('yearly/export', [InventoryReportController::class, 'exportYearly'])->name('yearly.export');
    });

    // Buys Reports - Unified (all sources)
    Route::prefix('reports/buys')->name('reports.buys.')->middleware('permission:reports.view')->group(function () {
        // Unified Buys Report (all sources)
        Route::get('/', [BuysReportController::class, 'index'])->name('index');
        Route::get('export', [BuysReportController::class, 'exportIndex'])->name('export');
        Route::get('monthly', [BuysReportController::class, 'monthly'])->name('monthly');
        Route::get('monthly/export', [BuysReportController::class, 'exportMonthly'])->name('monthly.export');
        Route::get('yearly', [BuysReportController::class, 'yearly'])->name('yearly');
        Route::get('yearly/export', [BuysReportController::class, 'exportYearly'])->name('yearly.export');

        // Legacy routes (kept for backwards compatibility)
        // In-Store
        Route::get('in-store', [BuysReportController::class, 'inStore'])->name('in-store');
        Route::get('in-store/export', [BuysReportController::class, 'exportInStore'])->name('in-store.export');
        Route::get('in-store/monthly', [BuysReportController::class, 'inStoreMonthly'])->name('in-store.monthly');
        Route::get('in-store/monthly/export', [BuysReportController::class, 'exportInStoreMonthly'])->name('in-store.monthly.export');
        Route::get('in-store/yearly', [BuysReportController::class, 'inStoreYearly'])->name('in-store.yearly');
        Route::get('in-store/yearly/export', [BuysReportController::class, 'exportInStoreYearly'])->name('in-store.yearly.export');

        // Online
        Route::get('online', [BuysReportController::class, 'online'])->name('online');
        Route::get('online/export', [BuysReportController::class, 'exportOnline'])->name('online.export');
        Route::get('online/monthly', [BuysReportController::class, 'onlineMonthly'])->name('onlineMonthly');
        Route::get('online/monthly/export', [BuysReportController::class, 'exportOnlineMonthly'])->name('online.monthly.export');
        Route::get('online/yearly', [BuysReportController::class, 'onlineYearly'])->name('online.yearly');
        Route::get('online/yearly/export', [BuysReportController::class, 'exportOnlineYearly'])->name('online.yearly.export');

        // Trade-In
        Route::get('trade-in', [BuysReportController::class, 'tradeIn'])->name('trade-in');
        Route::get('trade-in/export', [BuysReportController::class, 'exportTradeIn'])->name('trade-in.export');
        Route::get('trade-in/monthly', [BuysReportController::class, 'tradeInMonthly'])->name('trade-in.monthly');
        Route::get('trade-in/monthly/export', [BuysReportController::class, 'exportTradeInMonthly'])->name('trade-in.monthly.export');
        Route::get('trade-in/yearly', [BuysReportController::class, 'tradeInYearly'])->name('trade-in.yearly');
        Route::get('trade-in/yearly/export', [BuysReportController::class, 'exportTradeInYearly'])->name('trade-in.yearly.export');
    });

    // Leads Reports (online transactions)
    Route::prefix('reports/leads')->name('reports.leads.')->middleware('permission:reports.view')->group(function () {
        Route::get('/', [LeadsReportController::class, 'index'])->name('index');
        Route::get('export', [LeadsReportController::class, 'exportIndex'])->name('export');
        Route::get('monthly', [LeadsReportController::class, 'monthly'])->name('monthly');
        Route::get('monthly/export', [LeadsReportController::class, 'exportMonthly'])->name('monthly.export');
        Route::get('yearly', [LeadsReportController::class, 'yearly'])->name('yearly');
        Route::get('yearly/export', [LeadsReportController::class, 'exportYearly'])->name('yearly.export');
        Route::get('daily-kits', [LeadsReportController::class, 'dailyKits'])->name('daily-kits');
        Route::get('daily-kits/export', [LeadsReportController::class, 'exportDailyKits'])->name('daily-kits.export');
    });

    // Transactions Reports
    Route::prefix('reports/transactions')->name('reports.transactions.')->middleware('permission:reports.view')->group(function () {
        Route::get('daily', [TransactionsReportController::class, 'daily'])->name('daily');
        Route::get('daily/export', [TransactionsReportController::class, 'exportDaily'])->name('daily.export');
        Route::get('weekly', [TransactionsReportController::class, 'weekly'])->name('weekly');
        Route::get('weekly/export', [TransactionsReportController::class, 'exportWeekly'])->name('weekly.export');
        Route::get('monthly', [TransactionsReportController::class, 'monthly'])->name('monthly');
        Route::get('monthly/export', [TransactionsReportController::class, 'exportMonthly'])->name('monthly.export');
        Route::get('yearly', [TransactionsReportController::class, 'yearly'])->name('yearly');
        Route::get('yearly/export', [TransactionsReportController::class, 'exportYearly'])->name('yearly.export');
    });

    // Agent Management
    Route::prefix('agents')->name('agents.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\AgentController::class, 'index'])->name('index');
        Route::get('/runs', [\App\Http\Controllers\Web\AgentController::class, 'runs'])->name('runs');
        Route::get('/actions', [\App\Http\Controllers\Web\AgentActionController::class, 'index'])->name('actions');
        Route::post('/actions/bulk-approve', [\App\Http\Controllers\Web\AgentActionController::class, 'bulkApprove'])->name('actions.bulk-approve');
        Route::post('/actions/bulk-reject', [\App\Http\Controllers\Web\AgentActionController::class, 'bulkReject'])->name('actions.bulk-reject');
        Route::post('/actions/{action}/approve', [\App\Http\Controllers\Web\AgentActionController::class, 'approve'])->name('actions.approve');
        Route::post('/actions/{action}/reject', [\App\Http\Controllers\Web\AgentActionController::class, 'reject'])->name('actions.reject');
        Route::get('/{slug}', [\App\Http\Controllers\Web\AgentController::class, 'show'])->name('show');
        Route::put('/{slug}', [\App\Http\Controllers\Web\AgentController::class, 'update'])->name('update');
        Route::post('/{slug}/run', [\App\Http\Controllers\Web\AgentController::class, 'run'])->name('run');
    });
});

require __DIR__.'/settings.php';
