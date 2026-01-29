<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$permissions  Permissions to check (user needs ANY of these)
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->unauthorized($request, 'Authentication required');
        }

        if (empty($permissions)) {
            return $next($request);
        }

        // Check if user has any of the required permissions
        if (! $user->hasAnyPermission($permissions)) {
            return $this->unauthorized(
                $request,
                'You do not have permission to perform this action',
                ['required_permissions' => $permissions]
            );
        }

        return $next($request);
    }

    protected function unauthorized(Request $request, string $message, array $data = []): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                ...$data,
            ], 403);
        }

        abort(403, $message);
    }
}
