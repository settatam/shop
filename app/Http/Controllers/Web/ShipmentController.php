<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ShippingLabel;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShipmentController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Display shipments list page.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        return Inertia::render('shipments/Index', [
            'statuses' => $this->getStatuses(),
            'carriers' => $this->getCarriers(),
        ]);
    }

    /**
     * Redirect to carrier tracking URL.
     */
    public function track(ShippingLabel $shippingLabel): RedirectResponse
    {
        $this->authorizeLabel($shippingLabel);

        $trackingUrl = $shippingLabel->getTrackingUrl();

        if (! $trackingUrl) {
            return back()->with('error', 'No tracking URL available for this shipment.');
        }

        return redirect()->away($trackingUrl);
    }

    /**
     * Download shipping label PDF.
     */
    public function download(ShippingLabel $shippingLabel): StreamedResponse|RedirectResponse
    {
        $this->authorizeLabel($shippingLabel);

        if (! $shippingLabel->label_path) {
            return back()->with('error', 'No label file available for this shipment.');
        }

        if (! Storage::exists($shippingLabel->label_path)) {
            return back()->with('error', 'Label file not found.');
        }

        $filename = "label-{$shippingLabel->tracking_number}.{$shippingLabel->label_format}";

        return Storage::download($shippingLabel->label_path, $filename);
    }

    /**
     * Void a shipping label.
     */
    public function void(ShippingLabel $shippingLabel): RedirectResponse
    {
        $this->authorizeLabel($shippingLabel);

        if ($shippingLabel->isVoided()) {
            return back()->with('error', 'This label has already been voided.');
        }

        if ($shippingLabel->isDelivered()) {
            return back()->with('error', 'Cannot void a delivered shipment.');
        }

        $shippingLabel->update([
            'status' => ShippingLabel::STATUS_VOIDED,
        ]);

        return back()->with('success', 'Shipping label voided successfully.');
    }

    /**
     * Handle bulk actions for shipments.
     */
    public function bulkAction(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'action' => 'required|string|in:void',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:shipping_labels,id',
        ]);

        $labels = ShippingLabel::where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->get();

        $count = 0;

        if ($validated['action'] === 'void') {
            foreach ($labels as $label) {
                if (! $label->isVoided() && ! $label->isDelivered()) {
                    $label->update(['status' => ShippingLabel::STATUS_VOIDED]);
                    $count++;
                }
            }
        }

        return redirect()->route('web.shipments.index')
            ->with('success', "{$count} shipment(s) voided successfully.");
    }

    /**
     * Authorize access to a shipping label.
     */
    protected function authorizeLabel(ShippingLabel $shippingLabel): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $shippingLabel->store_id !== $store->id) {
            abort(404);
        }
    }

    /**
     * Get available statuses.
     *
     * @return array<array<string, string>>
     */
    protected function getStatuses(): array
    {
        return [
            ['value' => ShippingLabel::STATUS_CREATED, 'label' => 'Created'],
            ['value' => ShippingLabel::STATUS_IN_TRANSIT, 'label' => 'In Transit'],
            ['value' => ShippingLabel::STATUS_DELIVERED, 'label' => 'Delivered'],
            ['value' => ShippingLabel::STATUS_VOIDED, 'label' => 'Voided'],
        ];
    }

    /**
     * Get available carriers.
     *
     * @return array<array<string, string>>
     */
    protected function getCarriers(): array
    {
        return [
            ['value' => ShippingLabel::CARRIER_FEDEX, 'label' => 'FedEx'],
            ['value' => ShippingLabel::CARRIER_UPS, 'label' => 'UPS'],
            ['value' => ShippingLabel::CARRIER_USPS, 'label' => 'USPS'],
            ['value' => ShippingLabel::CARRIER_DHL, 'label' => 'DHL'],
        ];
    }
}
