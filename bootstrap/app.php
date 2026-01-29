<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCurrentStore;
use App\Http\Middleware\SetPortalStore;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        using: function () {
            // Portal routes must be registered before web routes so domain matching takes priority
            require base_path('routes/portal.php');

            Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));

            Route::middleware('api')->prefix('api')->group(base_path('routes/webhooks.php'));

            Route::middleware('web')->group(base_path('routes/web.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            \Laravel\Passport\Http\Middleware\CreateFreshApiToken::class,
        ]);

        $middleware->api(append: [
            SetCurrentStore::class,
        ]);

        $middleware->alias([
            'store' => SetCurrentStore::class,
            'permission' => CheckPermission::class,
            'onboarding' => \App\Http\Middleware\EnsureOnboardingComplete::class,
            'portal.store' => SetPortalStore::class,
        ]);

        $middleware->redirectGuestsTo(function ($request) {
            if ($request->route()?->named('portal.*')) {
                $storeSlug = $request->route('storeSlug');

                return route('portal.login', ['storeSlug' => $storeSlug]);
            }

            return route('login');
        });

        $middleware->redirectUsersTo(function ($request) {
            if ($request->route()?->named('portal.*')) {
                $storeSlug = $request->route('storeSlug');

                return route('portal.transactions.index', ['storeSlug' => $storeSlug]);
            }

            return '/dashboard';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
