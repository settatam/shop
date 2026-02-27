<?php

namespace App\Providers;

use App\Facades\Channel;
use App\Models\Activity;
use App\Models\User;
use App\Services\Platforms\ChannelService;
use App\Services\StoreContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StoreContext::class, function () {
            return new StoreContext;
        });

        $this->app->singleton(ChannelService::class, function () {
            return new ChannelService;
        });

        // Register Channel facade alias
        AliasLoader::getInstance()->alias('Channel', Channel::class);

        $this->app->singleton(
            \Laravel\Fortify\Contracts\RegisterResponse::class,
            \App\Http\Responses\RegisterResponse::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerGates();
        $this->configureRateLimiting();
    }

    /**
     * Configure rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('storefront-chat', function (Request $request) {
            $shop = $request->query('shop', 'unknown');
            $visitorId = $request->input('visitor_id', $request->ip());

            return [
                Limit::perMinute(20)->by($shop.'|'.$visitorId),
                Limit::perHour(500)->by($shop),
            ];
        });
    }

    /**
     * Register authorization gates based on Activity permissions.
     */
    protected function registerGates(): void
    {
        // Register a gate for each permission defined in Activity
        foreach (Activity::getAllSlugs() as $permission) {
            Gate::define($permission, function (User $user) use ($permission) {
                return $user->hasPermission($permission);
            });
        }

        // Super admin gate - checks for wildcard permission
        Gate::define('super-admin', function (User $user) {
            return $user->hasPermission('*');
        });

        // Convenience gates for common checks
        Gate::define('view-reports', function (User $user) {
            return $user->hasPermission(Activity::REPORTS_VIEW);
        });

        Gate::define('manage-team', function (User $user) {
            return $user->hasAnyPermission([
                Activity::TEAM_INVITE,
                Activity::TEAM_UPDATE,
                Activity::TEAM_REMOVE,
                Activity::TEAM_MANAGE_ROLES,
            ]);
        });

        Gate::define('manage-products', function (User $user) {
            return $user->hasAnyPermission([
                Activity::PRODUCTS_CREATE,
                Activity::PRODUCTS_UPDATE,
                Activity::PRODUCTS_DELETE,
            ]);
        });

        Gate::define('manage-orders', function (User $user) {
            return $user->hasAnyPermission([
                Activity::ORDERS_CREATE,
                Activity::ORDERS_UPDATE,
                Activity::ORDERS_FULFILL,
                Activity::ORDERS_CANCEL,
            ]);
        });

        // Before callback - owners/super-admins can do everything
        Gate::before(function (User $user, string $ability) {
            if ($user->hasPermission('*')) {
                return true;
            }

            return null; // Fall through to specific gate check
        });
    }
}
