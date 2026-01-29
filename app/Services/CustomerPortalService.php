<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Support\Str;

class CustomerPortalService
{
    public function generateInviteToken(Customer $customer): string
    {
        $token = Str::random(64);

        $customer->update([
            'portal_invite_token' => $token,
            'portal_invite_sent_at' => now(),
        ]);

        return $token;
    }

    public function sendInvite(Customer $customer, Store $store): void
    {
        $token = $this->generateInviteToken($customer);

        $portalDomain = config('app.portal_domain');
        $url = "https://{$store->slug}.{$portalDomain}/invite/{$token}";

        // Send via email if customer has email
        if ($customer->email) {
            \Illuminate\Support\Facades\Mail::raw(
                "You've been invited to view your transactions. Set up your account here: {$url}",
                function ($message) use ($customer, $store) {
                    $message->to($customer->email)
                        ->subject("Your {$store->name} Portal Invite");
                }
            );
        }
    }

    public function setupAccountOnTransaction(Transaction $transaction): void
    {
        $customer = $transaction->customer;

        if (! $customer) {
            return;
        }

        if ($customer->password || $customer->portal_invite_token) {
            return;
        }

        $store = Store::withoutGlobalScopes()->find($transaction->store_id);

        if ($store) {
            $this->sendInvite($customer, $store);
        }
    }
}
