<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\RolesController;
use App\Http\Controllers\Settings\StatusesController;
use App\Http\Controllers\Settings\StoreSettingsController;
use App\Http\Controllers\Settings\TeamController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Http\Controllers\Web\LeadSourceController;
use App\Http\Controllers\Web\NotificationSettingsController;
use App\Http\Controllers\Web\PrinterSettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
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
    });

    // Notification Settings
    Route::middleware(['store', 'onboarding'])->prefix('settings/notifications')->name('settings.notifications.')->group(function () {
        Route::get('/', [NotificationSettingsController::class, 'index'])->name('index');
        Route::get('/templates', [NotificationSettingsController::class, 'templates'])->name('templates');
        Route::get('/templates/create', [NotificationSettingsController::class, 'createTemplate'])->name('templates.create');
        Route::get('/templates/{template}/edit', [NotificationSettingsController::class, 'editTemplate'])->name('templates.edit');
        Route::get('/subscriptions', [NotificationSettingsController::class, 'subscriptions'])->name('subscriptions');
        Route::get('/channels', [NotificationSettingsController::class, 'channels'])->name('channels');
        Route::post('/channels/save', [NotificationSettingsController::class, 'saveChannel'])->name('channels.save');
        Route::post('/channels/toggle', [NotificationSettingsController::class, 'toggleChannel'])->name('channels.toggle');
        Route::get('/logs', [NotificationSettingsController::class, 'logs'])->name('logs');
    });
});
