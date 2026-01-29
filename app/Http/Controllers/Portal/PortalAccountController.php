<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PortalAccountController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('portal/Account', [
            'customer' => auth('customer')->user(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $customer = auth('customer')->user();

        $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', Password::min(8), 'confirmed'],
        ]);

        $data = $request->only('first_name', 'last_name', 'email', 'phone_number');

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $customer->update($data);

        return back()->with('success', 'Account updated successfully.');
    }
}
