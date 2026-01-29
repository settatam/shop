<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Jobs\SendCustomerOtpJob;
use App\Models\Customer;
use App\Services\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class PortalAuthController extends Controller
{
    public function showLogin(): Response
    {
        return Inertia::render('portal/auth/Login');
    }

    public function login(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $storeId = app(StoreContext::class)->getCurrentStoreId();

        $customer = Customer::withoutGlobalScopes()
            ->where('email', $request->email)
            ->where('store_id', $storeId)
            ->first();

        if (! $customer || ! Hash::check($request->password, $customer->password)) {
            return back()->withErrors([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        auth('customer')->login($customer, $request->boolean('remember'));

        $request->session()->regenerate();

        return redirect()->intended(
            route('portal.transactions.index', ['storeSlug' => app(StoreContext::class)->getCurrentStore()->slug])
        );
    }

    public function sendOtp(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $storeId = app(StoreContext::class)->getCurrentStoreId();

        $customer = Customer::withoutGlobalScopes()
            ->where('phone_number', $request->phone)
            ->where('store_id', $storeId)
            ->first();

        if (! $customer) {
            return back()->withErrors([
                'phone' => 'No account found with this phone number.',
            ]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put("customer_otp:{$request->phone}", Hash::make($code), 600);

        SendCustomerOtpJob::dispatch($request->phone, $code);

        return back()->with('otpSent', true);
    }

    public function verifyOtp(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $storeId = app(StoreContext::class)->getCurrentStoreId();
        $hashedCode = Cache::get("customer_otp:{$request->phone}");

        if (! $hashedCode || ! Hash::check($request->code, $hashedCode)) {
            return back()->withErrors([
                'code' => 'Invalid or expired verification code.',
            ]);
        }

        $customer = Customer::withoutGlobalScopes()
            ->where('phone_number', $request->phone)
            ->where('store_id', $storeId)
            ->first();

        if (! $customer) {
            return back()->withErrors([
                'phone' => 'No account found with this phone number.',
            ]);
        }

        Cache::forget("customer_otp:{$request->phone}");

        if (! $customer->phone_verified_at) {
            $customer->update(['phone_verified_at' => now()]);
        }

        auth('customer')->login($customer);

        $request->session()->regenerate();

        return redirect()->intended(
            route('portal.transactions.index', ['storeSlug' => app(StoreContext::class)->getCurrentStore()->slug])
        );
    }

    public function logout(Request $request): \Illuminate\Http\RedirectResponse
    {
        $storeSlug = app(StoreContext::class)->getCurrentStore()->slug;

        auth('customer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login', ['storeSlug' => $storeSlug]);
    }
}
