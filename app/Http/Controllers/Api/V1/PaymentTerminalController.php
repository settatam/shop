<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentTerminalResource;
use App\Models\PaymentTerminal;
use App\Services\Gateways\PaymentGatewayFactory;
use App\Services\StoreContext;
use App\Services\Terminals\TerminalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentTerminalController extends Controller
{
    public function __construct(
        protected TerminalService $terminalService,
        protected StoreContext $storeContext,
        protected PaymentGatewayFactory $gatewayFactory,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PaymentTerminal::query()->latest();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('gateway')) {
            $query->where('gateway', $request->input('gateway'));
        }

        $terminals = $query->paginate($request->input('per_page', 15));

        return PaymentTerminalResource::collection($terminals);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gateway' => ['required', 'string', 'in:square,dejavoo'],
            'device_code' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'string', 'max:255'],
        ]);

        $store = $this->storeContext->getCurrentStore();

        $terminal = $this->terminalService->pairTerminal(
            $store,
            $validated['gateway'],
            $validated['device_code'],
            [
                'name' => $validated['name'] ?? null,
                'location_id' => $validated['location_id'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Terminal paired successfully.',
            'data' => new PaymentTerminalResource($terminal),
        ], 201);
    }

    public function show(PaymentTerminal $terminal): PaymentTerminalResource
    {
        return new PaymentTerminalResource($terminal->load('checkouts'));
    }

    public function update(Request $request, PaymentTerminal $terminal): PaymentTerminalResource
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $terminal->update(array_filter($validated));

        return new PaymentTerminalResource($terminal);
    }

    public function destroy(PaymentTerminal $terminal): JsonResponse
    {
        $terminal->disconnect();

        return response()->json([
            'message' => 'Terminal disconnected successfully.',
        ]);
    }

    public function testConnection(PaymentTerminal $terminal): JsonResponse
    {
        // Update last seen timestamp to indicate successful communication
        $terminal->updateLastSeen();

        return response()->json([
            'message' => 'Connection test successful.',
            'data' => new PaymentTerminalResource($terminal),
        ]);
    }

    public function availableGateways(): JsonResponse
    {
        return response()->json([
            'data' => $this->gatewayFactory->availableTerminalGateways(),
        ]);
    }
}
