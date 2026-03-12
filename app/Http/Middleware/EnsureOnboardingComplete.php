<?php

namespace App\Http\Middleware;

use App\Services\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingComplete
{
    public function __construct(protected StoreContext $storeContext) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $store = $this->storeContext->getCurrentStore();

        if ($store && $store->needsOnboarding()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Onboarding is not complete.',
                ], 409);
            }

            return redirect()->route('onboarding.index');
        }

        return $next($request);
    }
}
