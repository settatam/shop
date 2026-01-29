<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TerminalCheckoutResource;
use App\Models\TerminalCheckout;
use App\Services\Terminals\TerminalService;
use Illuminate\Http\JsonResponse;

class TerminalCheckoutController extends Controller
{
    public function __construct(
        protected TerminalService $terminalService,
    ) {}

    public function show(TerminalCheckout $checkout): TerminalCheckoutResource
    {
        // Poll the gateway for the latest status if checkout is still active
        if ($checkout->isActive()) {
            $this->terminalService->pollCheckoutStatus($checkout);
            $checkout->refresh();
        }

        $checkout->load(['terminal', 'invoice', 'payment', 'user']);

        return new TerminalCheckoutResource($checkout);
    }

    public function cancel(TerminalCheckout $checkout): JsonResponse
    {
        $this->terminalService->cancelCheckout($checkout);

        return response()->json([
            'message' => 'Checkout cancelled successfully.',
            'data' => new TerminalCheckoutResource($checkout->fresh(['terminal', 'invoice'])),
        ]);
    }
}
