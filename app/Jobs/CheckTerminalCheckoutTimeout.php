<?php

namespace App\Jobs;

use App\Models\TerminalCheckout;
use App\Services\Terminals\TerminalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CheckTerminalCheckoutTimeout implements ShouldQueue
{
    use Queueable;

    public function handle(TerminalService $terminalService): void
    {
        $expiredCheckouts = TerminalCheckout::query()
            ->active()
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expiredCheckouts as $checkout) {
            try {
                $terminalService->handleTimeout($checkout);
                Log::info('Terminal checkout timed out', [
                    'checkout_id' => $checkout->id,
                    'external_checkout_id' => $checkout->checkout_id,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to handle terminal checkout timeout', [
                    'checkout_id' => $checkout->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
