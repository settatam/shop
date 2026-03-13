<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderFromWizardRequest;
use App\Services\Orders\OrderCreationService;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected OrderController $orderController,
        protected OrderCreationService $orderCreationService,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $props = $this->orderController->buildWizardProps($request, $store);
        $props['posMode'] = true;
        $props['completedOrder'] = session('pos_completed_order');

        return Inertia::render('orders/CreateWizard', $props);
    }

    public function store(CreateOrderFromWizardRequest $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $data = $request->validated();
        $order = $this->orderCreationService->createFromWizard($data, $store);

        $customerName = $order->customer
            ? $order->customer->full_name
            : 'Walk-in Customer';

        return redirect()->route('web.pos.show')->with('pos_completed_order', [
            'id' => $order->id,
            'total' => $order->total,
            'customer_name' => $customerName,
        ]);
    }
}
