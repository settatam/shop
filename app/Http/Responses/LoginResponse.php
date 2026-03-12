<?php

namespace App\Http\Responses;

use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        $home = config('fortify.home', '/dashboard');

        if ($request->header('X-Inertia')) {
            return Inertia::location($home);
        }

        return redirect()->intended($home);
    }
}
