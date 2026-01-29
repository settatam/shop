<?php

namespace App\Http\Middleware;

use App\Services\StoreContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandlePortalInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $store = app(StoreContext::class)->getCurrentStore();

        return [
            ...parent::share($request),
            'name' => $store?->name ?? config('app.name'),
            'isPortal' => true,
            'auth' => [
                'customer' => auth('customer')->user(),
            ],
            'currentStore' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'logo' => $store->logo,
                'logo_url' => $store->logo ? \Illuminate\Support\Facades\Storage::disk('public')->url($store->logo) : null,
            ] : null,
        ];
    }
}
