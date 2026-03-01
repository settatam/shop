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

        // Remove any server-level X-Frame-Options that would block embedding
        $response->headers->remove('X-Frame-Options');

        $shop = $request->query('shop', '');
        $ancestors = ['https://admin.shopify.com', 'https://*.myshopify.com'];
        if ($shop) {
            $ancestors[] = "https://{$shop}";
        }

        $response->headers->set(
            'Content-Security-Policy',
            'frame-ancestors '.implode(' ', $ancestors)
        );

        return $response;
    }
}
