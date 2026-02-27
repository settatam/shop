<?php

use App\Http\Controllers\Settings\EbayAccountController;
use App\Http\Controllers\Settings\EmailSettingsController;
use App\Http\Controllers\Settings\JobLogsController;
use App\Http\Controllers\Settings\MaintenanceController;
use App\Http\Controllers\Settings\MarketplaceController;
use App\Http\Controllers\Settings\MarketplaceSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PaymentTerminalController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\RolesController;
use App\Http\Controllers\Settings\StatusesController;
use App\Http\Controllers\Settings\StoreSettingsController;
use App\Http\Controllers\Settings\TeamController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Http\Controllers\Settings\WarehouseController;
use App\Http\Controllers\Web\LeadSourceController;
use App\Http\Controllers\Web\NotificationSettingsController;
use App\Http\Controllers\Web\PrinterSettingsController;
use App\Http\Controllers\Web\SalesChannelController;
use App\Http\Controllers\Web\ScheduledReportController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'store'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance.edit');

    // Store Settings
    Route::get('settings/store', [StoreSettingsController::class, 'edit'])
        ->middleware(['store', 'onboarding'])
        ->name('store-settings.edit');
    Route::patch('settings/store', [StoreSettingsController::class, 'update'])
        ->middleware(['store', 'onboarding'])
        ->name('store-settings.update');
    Route::post('settings/store/logo', [StoreSettingsController::class, 'uploadLogo'])
        ->middleware(['store', 'onboarding'])
        ->name('store-settings.upload-logo');
    Route::delete('settings/store/logo', [StoreSettingsController::class, 'removeLogo'])
        ->middleware(['store', 'onboarding'])
        ->name('store-settings.remove-logo');

    // Email Settings
    Route::middleware(['store', 'onboarding'])->prefix('settings/email')->name('settings.email.')->group(function () {
        Route::get('/', [EmailSettingsController::class, 'index'])->name('index');
        Route::patch('/', [EmailSettingsController::class, 'update'])->name('update');
        Route::post('/test', [EmailSettingsController::class, 'sendTest'])->name('test');
    });

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');

    Route::get('settings/team', [TeamController::class, 'index'])
        ->middleware(['store', 'permission:team.view'])
        ->name('team.index');

    Route::get('settings/roles', [RolesController::class, 'index'])
        ->middleware(['store', 'permission:team.manage_roles'])
        ->name('roles.index');

    Route::get('settings/statuses', [StatusesController::class, 'index'])
        ->middleware(['store', 'onboarding', 'permission:store.manage_statuses'])
        ->name('statuses.index');

    // Lead Sources Settings
    Route::middleware(['store', 'onboarding'])->prefix('settings/lead-sources')->name('settings.lead-sources.')->group(function () {
        Route::get('/', [LeadSourceController::class, 'settings'])->name('index');
        Route::post('/', [LeadSourceController::class, 'store'])->name('store');
        Route::put('/{leadSource}', [LeadSourceController::class, 'update'])->name('update');
        Route::delete('/{leadSource}', [LeadSourceController::class, 'destroy'])->name('destroy');
        Route::post('/reorder', [LeadSourceController::class, 'reorder'])->name('reorder');
    });

    // Printer Settings
    Route::middleware(['store', 'onboarding'])->group(function () {
        Route::get('settings/printers', [PrinterSettingsController::class, 'index'])->name('settings.printers.index');
        Route::post('settings/printers', [PrinterSettingsController::class, 'store'])->name('settings.printers.store');
        Route::put('settings/printers/{printerSetting}', [PrinterSettingsController::class, 'update'])->name('settings.printers.update');
        Route::delete('settings/printers/{printerSetting}', [PrinterSettingsController::class, 'destroy'])->name('settings.printers.destroy');
        Route::post('settings/printers/{printerSetting}/make-default', [PrinterSettingsController::class, 'makeDefault'])->name('settings.printers.make-default');
        Route::post('settings/printers/{printerSetting}/network-print', [PrinterSettingsController::class, 'networkPrint'])->name('settings.printers.network-print');
        Route::post('settings/printers/{printerSetting}/test-connection', [PrinterSettingsController::class, 'testConnection'])->name('settings.printers.test-connection');
        Route::post('settings/printers/{printerSetting}/test-print', [PrinterSettingsController::class, 'testPrint'])->name('settings.printers.test-print');
    });

    // Payment Terminal Settings
    Route::middleware(['store', 'onboarding'])->prefix('settings/terminals')->name('settings.terminals.')->group(function () {
        Route::get('/', [PaymentTerminalController::class, 'index'])->name('index');
        Route::post('/', [PaymentTerminalController::class, 'store'])->name('store');
        Route::put('/{terminal}', [PaymentTerminalController::class, 'update'])->name('update');
        Route::delete('/{terminal}', [PaymentTerminalController::class, 'destroy'])->name('destroy');
        Route::post('/{terminal}/test', [PaymentTerminalController::class, 'test'])->name('test');
        Route::post('/{terminal}/activate', [PaymentTerminalController::class, 'activate'])->name('activate');
        Route::post('/{terminal}/deactivate', [PaymentTerminalController::class, 'deactivate'])->name('deactivate');
    });

    // Notification Settings
    Route::middleware(['store', 'onboarding'])->prefix('settings/notifications')->name('settings.notifications.')->group(function () {
        Route::get('/', [NotificationSettingsController::class, 'index'])->name('index');
        Route::get('/templates', [NotificationSettingsController::class, 'templates'])->name('templates');
        Route::get('/templates/create', [NotificationSettingsController::class, 'createTemplate'])->name('templates.create');
        Route::post('/templates', [NotificationSettingsController::class, 'storeTemplate'])->name('templates.store');
        Route::get('/templates/{template}/edit', [NotificationSettingsController::class, 'editTemplate'])->name('templates.edit');
        Route::put('/templates/{template}', [NotificationSettingsController::class, 'updateTemplate'])->name('templates.update');
        Route::delete('/templates/{template}', [NotificationSettingsController::class, 'destroyTemplate'])->name('templates.destroy');
        Route::post('/templates/{template}/duplicate', [NotificationSettingsController::class, 'duplicateTemplate'])->name('templates.duplicate');
        Route::post('/templates/create-defaults', [NotificationSettingsController::class, 'createDefaultTemplates'])->name('templates.create-defaults');
        Route::get('/layouts', [NotificationSettingsController::class, 'layouts'])->name('layouts');
        Route::get('/layouts/create', [NotificationSettingsController::class, 'createLayout'])->name('layouts.create');
        Route::post('/layouts', [NotificationSettingsController::class, 'storeLayout'])->name('layouts.store');
        Route::get('/layouts/{layout}/edit', [NotificationSettingsController::class, 'editLayout'])->name('layouts.edit');
        Route::put('/layouts/{layout}', [NotificationSettingsController::class, 'updateLayout'])->name('layouts.update');
        Route::delete('/layouts/{layout}', [NotificationSettingsController::class, 'destroyLayout'])->name('layouts.destroy');
        Route::post('/layouts/{layout}/set-default', [NotificationSettingsController::class, 'setDefaultLayout'])->name('layouts.set-default');
        Route::post('/layouts/create-defaults', [NotificationSettingsController::class, 'createDefaultLayouts'])->name('layouts.create-defaults');
        Route::get('/subscriptions', [NotificationSettingsController::class, 'subscriptions'])->name('subscriptions');
        Route::get('/channels', [NotificationSettingsController::class, 'channels'])->name('channels');
        Route::post('/channels/save', [NotificationSettingsController::class, 'saveChannel'])->name('channels.save');
        Route::post('/channels/toggle', [NotificationSettingsController::class, 'toggleChannel'])->name('channels.toggle');
        Route::get('/logs', [NotificationSettingsController::class, 'logs'])->name('logs');

        // Scheduled Reports
        Route::get('/scheduled-reports', [ScheduledReportController::class, 'index'])->name('scheduled-reports.index');
        Route::post('/scheduled-reports', [ScheduledReportController::class, 'store'])->name('scheduled-reports.store');
        Route::put('/scheduled-reports/{scheduledReport}', [ScheduledReportController::class, 'update'])->name('scheduled-reports.update');
        Route::delete('/scheduled-reports/{scheduledReport}', [ScheduledReportController::class, 'destroy'])->name('scheduled-reports.destroy');
        Route::post('/scheduled-reports/{scheduledReport}/toggle', [ScheduledReportController::class, 'toggle'])->name('scheduled-reports.toggle');
        Route::post('/scheduled-reports/{scheduledReport}/test', [ScheduledReportController::class, 'test'])->name('scheduled-reports.test');
        Route::post('/scheduled-reports/{scheduledReport}/send', [ScheduledReportController::class, 'sendNow'])->name('scheduled-reports.send');
        Route::get('/scheduled-reports/{scheduledReport}/template', [ScheduledReportController::class, 'editTemplate'])->name('scheduled-reports.template');
    });

    // Sales Channels
    Route::middleware(['store', 'onboarding'])->prefix('settings/channels')->name('settings.channels.')->group(function () {
        Route::get('/', [SalesChannelController::class, 'index'])->name('index');
        Route::post('/', [SalesChannelController::class, 'store'])->name('store');
        Route::put('/{salesChannel}', [SalesChannelController::class, 'update'])->name('update');
        Route::delete('/{salesChannel}', [SalesChannelController::class, 'destroy'])->name('destroy');
        Route::post('/reorder', [SalesChannelController::class, 'reorder'])->name('reorder');
        Route::get('/{salesChannel}/deactivate-preflight', [SalesChannelController::class, 'deactivatePreflight'])->name('deactivate-preflight');
    });

    // Maintenance
    Route::middleware(['store', 'onboarding'])->prefix('settings/maintenance')->name('settings.maintenance.')->group(function () {
        Route::get('/', [MaintenanceController::class, 'index'])->name('index');
        Route::post('/reindex-search', [MaintenanceController::class, 'reindexSearch'])->name('reindex-search');
        Route::post('/reindex-model/{model}', [MaintenanceController::class, 'reindexModel'])->name('reindex-model');
    });

    // Job Logs (Redis-based queue monitoring)
    Route::middleware(['store', 'onboarding'])->prefix('settings/job-logs')->name('settings.job-logs.')->group(function () {
        Route::get('/', [JobLogsController::class, 'index'])->name('index');
        Route::get('/logs', [JobLogsController::class, 'logs'])->name('logs');
        Route::get('/stats', [JobLogsController::class, 'stats'])->name('stats');
        Route::get('/{jobId}', [JobLogsController::class, 'show'])->name('show');
    });

    // Marketplace Integrations
    Route::middleware(['store', 'onboarding'])->prefix('settings/marketplaces')->name('settings.marketplaces.')->group(function () {
        Route::get('/', [MarketplaceController::class, 'index'])->name('index');
        Route::post('/', [MarketplaceController::class, 'store'])->name('store');
        Route::get('/connect/{platform}', [MarketplaceController::class, 'connect'])->name('connect');
        Route::get('/callback/{platform}', [MarketplaceController::class, 'callback'])->name('callback');
        Route::put('/{marketplace}', [MarketplaceController::class, 'update'])->name('update');
        Route::delete('/{marketplace}', [MarketplaceController::class, 'destroy'])->name('destroy');
        Route::post('/{marketplace}/test', [MarketplaceController::class, 'test'])->name('test');
        Route::post('/{marketplace}/sync', [MarketplaceController::class, 'sync'])->name('sync');
        Route::get('/{marketplace}/settings', [MarketplaceSettingsController::class, 'show'])->name('settings');
        Route::put('/{marketplace}/settings', [MarketplaceSettingsController::class, 'update'])->name('settings.update');
        Route::post('/{marketplace}/fetch-policies', [MarketplaceSettingsController::class, 'fetchPolicies'])->name('fetch-policies');
        Route::post('/{marketplace}/fetch-locations', [MarketplaceSettingsController::class, 'fetchLocations'])->name('fetch-locations');
        Route::post('/{marketplace}/fetch-shipping-profiles', [MarketplaceSettingsController::class, 'fetchShippingProfiles'])->name('fetch-shipping-profiles');
        Route::post('/{marketplace}/fetch-return-policies', [MarketplaceSettingsController::class, 'fetchReturnPolicies'])->name('fetch-return-policies');
        Route::post('/{marketplace}/sync-metafield-definitions', [MarketplaceSettingsController::class, 'syncMetafieldDefinitions'])->name('sync-metafield-definitions');
        Route::post('/{marketplace}/create-listings', [MarketplaceSettingsController::class, 'createListings'])->name('create-listings');

        // eBay Account Management
        Route::prefix('{marketplace}/ebay')->name('ebay.')->group(function () {
            Route::get('/return-policies', [EbayAccountController::class, 'returnPolicies'])->name('return-policies.index');
            Route::post('/return-policies', [EbayAccountController::class, 'storeReturnPolicy'])->name('return-policies.store');
            Route::put('/return-policies/{policyId}', [EbayAccountController::class, 'updateReturnPolicy'])->name('return-policies.update');
            Route::delete('/return-policies/{policyId}', [EbayAccountController::class, 'destroyReturnPolicy'])->name('return-policies.destroy');

            Route::get('/fulfillment-policies', [EbayAccountController::class, 'fulfillmentPolicies'])->name('fulfillment-policies.index');
            Route::post('/fulfillment-policies', [EbayAccountController::class, 'storeFulfillmentPolicy'])->name('fulfillment-policies.store');
            Route::put('/fulfillment-policies/{policyId}', [EbayAccountController::class, 'updateFulfillmentPolicy'])->name('fulfillment-policies.update');
            Route::delete('/fulfillment-policies/{policyId}', [EbayAccountController::class, 'destroyFulfillmentPolicy'])->name('fulfillment-policies.destroy');

            Route::get('/payment-policies', [EbayAccountController::class, 'paymentPolicies'])->name('payment-policies.index');
            Route::post('/payment-policies', [EbayAccountController::class, 'storePaymentPolicy'])->name('payment-policies.store');
            Route::put('/payment-policies/{policyId}', [EbayAccountController::class, 'updatePaymentPolicy'])->name('payment-policies.update');
            Route::delete('/payment-policies/{policyId}', [EbayAccountController::class, 'destroyPaymentPolicy'])->name('payment-policies.destroy');

            Route::get('/locations', [EbayAccountController::class, 'locations'])->name('locations.index');
            Route::post('/locations', [EbayAccountController::class, 'storeLocation'])->name('locations.store');
            Route::put('/locations/{locationKey}', [EbayAccountController::class, 'updateLocation'])->name('locations.update');
            Route::delete('/locations/{locationKey}', [EbayAccountController::class, 'destroyLocation'])->name('locations.destroy');

            Route::get('/privileges', [EbayAccountController::class, 'privileges'])->name('privileges');
            Route::get('/programs', [EbayAccountController::class, 'programs'])->name('programs.index');
            Route::post('/programs/opt-in', [EbayAccountController::class, 'optInToProgram'])->name('programs.opt-in');
            Route::post('/programs/opt-out', [EbayAccountController::class, 'optOutOfProgram'])->name('programs.opt-out');
        });
    });

    // Warehouse Settings
    Route::middleware(['store', 'onboarding'])->prefix('settings/warehouses')->name('settings.warehouses.')->group(function () {
        Route::get('/', [WarehouseController::class, 'index'])->name('index');
        Route::post('/', [WarehouseController::class, 'store'])->name('store');
        Route::put('/{warehouse}', [WarehouseController::class, 'update'])->name('update');
        Route::delete('/{warehouse}', [WarehouseController::class, 'destroy'])->name('destroy');
        Route::post('/{warehouse}/make-default', [WarehouseController::class, 'makeDefault'])->name('make-default');
    });
});
