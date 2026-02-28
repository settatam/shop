<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ShopifyFrameAncestors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $shop = $request->query('shop', '');
        $frameAncestors = "https://admin.shopify.com https://{$shop}";

        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors {$frameAncestors}"
        );

        return $response;
    }
}
