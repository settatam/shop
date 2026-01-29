<?php

namespace App\Http\Controllers;

use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    /**
     * Show the invitation acceptance page.
     */
    public function show(string $token): Response|RedirectResponse
    {
        $storeUser = StoreUser::where('token', $token)
            ->where('status', 'invite sent')
            ->with(['store:id,name', 'role:id,name'])
            ->first();

        if (! $storeUser) {
            return redirect()->route('login')
                ->with('status', 'This invitation is no longer valid or has already been accepted.');
        }

        return Inertia::render('auth/AcceptInvitation', [
            'token' => $token,
            'email' => $storeUser->email,
            'storeName' => $storeUser->store->name,
            'roleName' => $storeUser->role?->name ?? 'Team Member',
            'firstName' => $storeUser->first_name,
            'lastName' => $storeUser->last_name,
        ]);
    }

    /**
     * Accept the invitation and create user account.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $storeUser = StoreUser::where('token', $token)
            ->where('status', 'invite sent')
            ->with('store')
            ->first();

        if (! $storeUser) {
            return redirect()->route('login')
                ->with('status', 'This invitation is no longer valid or has already been accepted.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // Check if user already exists with this email
        $user = User::where('email', $storeUser->email)->first();

        if (! $user) {
            // Create the new user account
            $user = User::create([
                'name' => $validated['name'],
                'email' => $storeUser->email,
                'password' => Hash::make($validated['password']),
                'email_verified_at' => now(),
                'current_store_id' => $storeUser->store_id,
            ]);
        }

        // Update the store user record
        $storeUser->update([
            'user_id' => $user->id,
            'status' => 'active',
            'token' => null,
            'temp_password' => null,
            'first_name' => explode(' ', $validated['name'])[0] ?? $validated['name'],
            'last_name' => explode(' ', $validated['name'], 2)[1] ?? '',
        ]);

        // Set user's current store if not set
        if (! $user->current_store_id) {
            $user->update(['current_store_id' => $storeUser->store_id]);
        }

        // Log the user in
        Auth::login($user);

        return redirect()->route('dashboard')
            ->with('status', 'Welcome! You have successfully joined '.$storeUser->store->name.'.');
    }

    /**
     * Resend invitation email.
     */
    public function resend(Request $request, StoreUser $storeUser): RedirectResponse
    {
        $currentStore = $request->user()->currentStore();

        if (! $currentStore || $storeUser->store_id !== $currentStore->id) {
            abort(404);
        }

        if ($storeUser->status !== 'invite sent') {
            return back()->with('error', 'This user has already accepted their invitation.');
        }

        // Generate new token
        $storeUser->update([
            'token' => \Illuminate\Support\Str::random(64),
        ]);

        // TODO: Resend invitation email
        // Mail::to($storeUser->email)->send(new StoreInvitation($storeUser, $currentStore));

        return back()->with('status', 'Invitation resent successfully.');
    }
}
