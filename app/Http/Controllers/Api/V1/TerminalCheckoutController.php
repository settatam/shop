<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TerminalCheckoutResource;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\PaymentTerminal;
use App\Models\TerminalCheckout;
use App\Services\Gateways\PaymentGatewayFactory;
use App\Services\Terminals\TerminalService;
use Illuminate\Http\JsonResponse;

class TerminalCheckoutController extends Controller
{
    public function __construct(
        protected TerminalService $terminalService,
        protected PaymentGatewayFactory $gatewayFactory,
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

        $payable = $checkout->payable;
        if ($payable) {
            ActivityLog::log(
                Activity::ORDERS_TERMINAL_PAYMENT_CANCELLED,
                $payable,
                null,
                ['amount' => $checkout->amount, 'terminal' => $checkout->terminal?->name],
                "Terminal payment of \${$checkout->amount} was cancelled",
            );
        }

        return response()->json([
            'message' => 'Checkout cancelled successfully.',
            'data' => new TerminalCheckoutResource($checkout->fresh(['terminal', 'invoice'])),
        ]);
    }

    /**
     * Send a cancel signal to a terminal to interrupt a pending transaction.
     * Used when the blocking Dejavoo request is aborted from the frontend.
     */
    public function cancelTerminal(PaymentTerminal $terminal): JsonResponse
    {
        $gateway = $this->gatewayFactory->makeTerminal($terminal->gateway);
        $gateway->cancelCheckout('cancel_'.time(), $terminal);

        return response()->json([
            'message' => 'Cancel signal sent to terminal.',
        ]);
    }
}
