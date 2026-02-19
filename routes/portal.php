<?php

use App\Http\Controllers\Portal\PortalAccountController;
use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalInviteController;
use App\Http\Controllers\Portal\PortalTransactionController;
use Illuminate\Support\Facades\Route;

Route::domain('{storeSlug}'.'.'.config('app.portal_domain'))
    ->prefix('p')
    ->middleware(['web', 'portal.store'])
    ->name('portal.')
    ->group(function () {
        // Guest routes
        Route::middleware('guest:customer')->group(function () {
            Route::get('/login', [PortalAuthController::class, 'showLogin'])->name('login');
            Route::post('/login', [PortalAuthController::class, 'login'])->name('login.store');
            Route::post('/otp/send', [PortalAuthController::class, 'sendOtp'])->name('otp.send');
            Route::post('/otp/verify', [PortalAuthController::class, 'verifyOtp'])->name('otp.verify');
            Route::get('/invite/{token}', [PortalInviteController::class, 'show'])->name('invite.show');
            Route::post('/invite/{token}', [PortalInviteController::class, 'accept'])->name('invite.accept');
        });

        // Authenticated routes
        Route::middleware('auth:customer')->group(function () {
            Route::get('/', [PortalTransactionController::class, 'index'])->name('transactions.index');
            Route::get('/transactions/{transaction}', [PortalTransactionController::class, 'show'])->name('transactions.show');
            Route::post('/transactions/{transaction}/accept', [PortalTransactionController::class, 'acceptOffer'])->name('transactions.accept');
            Route::post('/transactions/{transaction}/accept-offer', [PortalTransactionController::class, 'acceptSpecificOffer'])->name('transactions.accept-specific');
            Route::post('/transactions/{transaction}/decline', [PortalTransactionController::class, 'declineOffer'])->name('transactions.decline');
            Route::put('/transactions/{transaction}/payout-preference', [PortalTransactionController::class, 'updatePayoutPreference'])->name('transactions.update-payout-preference');
            Route::get('/account', [PortalAccountController::class, 'show'])->name('account.show');
            Route::put('/account', [PortalAccountController::class, 'update'])->name('account.update');
            Route::post('/logout', [PortalAuthController::class, 'logout'])->name('logout');
        });
    });
