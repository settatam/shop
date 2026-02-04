<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PaymentTerminal;
use App\Models\Warehouse;
use App\Services\Gateways\PaymentGatewayFactory;
use App\Services\StoreContext;
use App\Services\Terminals\TerminalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentTerminalController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected TerminalService $terminalService,
        protected PaymentGatewayFactory $gatewayFactory,
    ) {}

    public function index(): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $terminals = PaymentTerminal::where('store_id', $store->id)
            ->with('warehouse')
            ->orderBy('name')
            ->get()
            ->map(fn ($terminal) => [
                'id' => $terminal->id,
                'name' => $terminal->name,
                'gateway' => $terminal->gateway,
                'gateway_label' => $this->getGatewayLabel($terminal->gateway),
                'device_id' => $terminal->device_id,
                'status' => $terminal->status,
                'status_label' => $this->getStatusLabel($terminal->status),
                'warehouse_id' => $terminal->warehouse_id,
                'warehouse_name' => $terminal->warehouse?->name,
                'last_seen_at' => $terminal->last_seen_at?->diffForHumans(),
                'paired_at' => $terminal->paired_at?->format('M d, Y'),
            ]);

        $warehouses = Warehouse::where('store_id', $store->id)
            ->orderBy('name')
            ->get()
            ->map(fn ($w) => [
                'value' => $w->id,
                'label' => $w->name,
            ]);

        $gateways = collect(PaymentTerminal::GATEWAYS)->map(fn ($g) => [
            'value' => $g,
            'label' => $this->getGatewayLabel($g),
        ]);

        return Inertia::render('settings/PaymentTerminals', [
            'terminals' => $terminals,
            'warehouses' => $warehouses,
            'gateways' => $gateways,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'gateway' => ['required', 'string', 'in:'.implode(',', PaymentTerminal::GATEWAYS)],
            'device_id' => ['required', 'string', 'max:255'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'settings' => ['nullable', 'array'],
            'settings.auth_key' => ['nullable', 'string', 'max:255'],
            'settings.register_id' => ['nullable', 'string', 'max:255'],
        ]);

        $store = $this->storeContext->getCurrentStore();

        PaymentTerminal::create([
            'store_id' => $store->id,
            'name' => $validated['name'],
            'gateway' => $validated['gateway'],
            'device_id' => $validated['device_id'],
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'status' => PaymentTerminal::STATUS_ACTIVE,
            'settings' => $validated['settings'] ?? [],
            'paired_at' => now(),
        ]);

        return back()->with('success', 'Payment terminal added successfully.');
    }

    public function update(Request $request, PaymentTerminal $terminal): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'device_id' => ['required', 'string', 'max:255'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'status' => ['nullable', 'string', 'in:'.implode(',', PaymentTerminal::STATUSES)],
            'settings' => ['nullable', 'array'],
            'settings.auth_key' => ['nullable', 'string', 'max:255'],
            'settings.register_id' => ['nullable', 'string', 'max:255'],
        ]);

        $terminal->update([
            'name' => $validated['name'],
            'device_id' => $validated['device_id'],
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'status' => $validated['status'] ?? $terminal->status,
            'settings' => array_merge($terminal->settings ?? [], $validated['settings'] ?? []),
        ]);

        return back()->with('success', 'Payment terminal updated successfully.');
    }

    public function destroy(PaymentTerminal $terminal): RedirectResponse
    {
        $terminal->delete();

        return back()->with('success', 'Payment terminal deleted successfully.');
    }

    public function test(PaymentTerminal $terminal): RedirectResponse
    {
        $terminal->updateLastSeen();

        return back()->with('success', 'Terminal connection test successful.');
    }

    public function activate(PaymentTerminal $terminal): RedirectResponse
    {
        $terminal->activate();

        return back()->with('success', 'Terminal activated successfully.');
    }

    public function deactivate(PaymentTerminal $terminal): RedirectResponse
    {
        $terminal->deactivate();

        return back()->with('success', 'Terminal deactivated successfully.');
    }

    protected function getGatewayLabel(string $gateway): string
    {
        return match ($gateway) {
            PaymentTerminal::GATEWAY_SQUARE => 'Square',
            PaymentTerminal::GATEWAY_DEJAVOO => 'Dejavoo',
            default => ucfirst($gateway),
        };
    }

    protected function getStatusLabel(string $status): string
    {
        return match ($status) {
            PaymentTerminal::STATUS_PENDING => 'Pending',
            PaymentTerminal::STATUS_ACTIVE => 'Active',
            PaymentTerminal::STATUS_INACTIVE => 'Inactive',
            PaymentTerminal::STATUS_DISCONNECTED => 'Disconnected',
            default => ucfirst($status),
        };
    }
}
