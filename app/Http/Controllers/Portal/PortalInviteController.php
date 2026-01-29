<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class PortalInviteController extends Controller
{
    public function show(string $storeSlug, string $token): Response
    {
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        $customer = Customer::withoutGlobalScopes()
            ->where('portal_invite_token', $token)
            ->where('store_id', $storeId)
            ->firstOrFail();

        return Inertia::render('portal/auth/AcceptInvite', [
            'token' => $token,
            'customer' => [
                'name' => $customer->full_name,
                'email' => $customer->email,
            ],
        ]);
    }

    public function accept(Request $request, string $storeSlug, string $token): RedirectResponse
    {
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        $customer = Customer::withoutGlobalScopes()
            ->where('portal_invite_token', $token)
            ->where('store_id', $storeId)
            ->firstOrFail();

        $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $customer->update([
            'password' => Hash::make($request->password),
            'portal_invite_token' => null,
        ]);

        auth('customer')->login($customer);

        $request->session()->regenerate();

        return redirect()->route('portal.transactions.index', [
            'storeSlug' => app(StoreContext::class)->getCurrentStore()->slug,
        ]);
    }
}
