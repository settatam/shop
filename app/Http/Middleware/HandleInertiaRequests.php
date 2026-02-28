<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\StoreContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Portal routes use their own shared data
        if ($this->isPortalRequest($request)) {
            return $this->sharePortal($request);
        }

        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'isShopmata' => str_contains($request->getHost(), 'shopmata'),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'stores' => fn () => $request->user() ? $this->getUserStores($request->user()) : [],
            'currentStore' => fn () => $this->getCurrentStoreData(),
            'storeFeatures' => fn () => $this->getStoreFeatures(),
            'hasOnlineBuysWorkflow' => fn () => app(StoreContext::class)->getCurrentStore()?->hasOnlineBuysWorkflow() ?? false,
        ];
    }

    /**
     * Determine if this is a portal request.
     */
    protected function isPortalRequest(Request $request): bool
    {
        $portalDomain = config('app.portal_domain');

        return str_ends_with($request->getHost(), ".{$portalDomain}")
            || $request->route()?->named('portal.*');
    }

    /**
     * Share portal-specific props.
     *
     * @return array<string, mixed>
     */
    protected function sharePortal(Request $request): array
    {
        $store = app(StoreContext::class)->getCurrentStore();

        return [
            ...parent::share($request),
            'name' => $store?->name ?? config('app.name'),
            'isPortal' => true,
            'auth' => [
                'customer' => auth('customer')->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],
            'currentStore' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'logo' => $store->logo,
                'logo_url' => $store->logo ? Storage::disk('do_spaces')->url($store->logo) : null,
            ] : null,
        ];
    }

    /**
     * Get all stores the user has access to.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Get the current store data with edition info.
     *
     * @return array<string, mixed>|null
     */
    protected function getCurrentStoreData(): ?array
    {
        $store = app(StoreContext::class)->getCurrentStore();

        if (! $store) {
            return null;
        }

        $featureManager = app(FeatureManager::class);

        return [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'logo' => $store->logo,
            'logo_url' => $store->logo ? Storage::disk('do_spaces')->url($store->logo) : null,
            'edition' => $store->edition,
            'edition_name' => $featureManager->getEditionName($store),
            'edition_logo' => $featureManager->getEditionLogo($store),
        ];
    }

    /**
     * Get the current store's features.
     *
     * @return array<string>
     */
    protected function getStoreFeatures(): array
    {
        $store = app(StoreContext::class)->getCurrentStore();

        if (! $store) {
            return [];
        }

        return app(FeatureManager::class)->getFeaturesForStore($store);
    }

    /**
     * Get all stores the user has access to.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getUserStores(User $user): array
    {
        $currentStoreId = app(StoreContext::class)->getCurrentStoreId();

        return Store::withoutGlobalScopes()
            ->whereHas('storeUsers', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->orWhere('user_id', $user->id)
            ->get()
            ->map(function ($store) use ($user, $currentStoreId) {
                $storeUser = StoreUser::where('user_id', $user->id)
                    ->where('store_id', $store->id)
                    ->with('role:id,name,slug')
                    ->first();

                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'logo' => $store->logo,
                    'logo_url' => $store->logo ? Storage::disk('do_spaces')->url($store->logo) : null,
                    'initial' => strtoupper(substr($store->name, 0, 1)),
                    'is_owner' => $store->user_id === $user->id || ($storeUser?->is_owner ?? false),
                    'role' => $storeUser?->role,
                    'current' => $currentStoreId === $store->id,
                ];
            })
            ->toArray();
    }
}
