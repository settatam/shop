<?php

namespace App\Providers;

use App\Models\Activity;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Support\Facades\Gate;
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
            return $user->hasAnyPermission([
                Activity::REPORTS_VIEW_SALES,
                Activity::REPORTS_VIEW_INVENTORY,
                Activity::REPORTS_VIEW_CUSTOMERS,
                Activity::REPORTS_VIEW_ACTIVITY,
            ]);
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
