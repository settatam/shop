<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Lead;
use App\Services\ActivityLogFormatter;
use App\Services\Image\ImageService;
use App\Services\Leads\LeadConversionService;
use App\Services\Shipping\ShippingLabelService;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected ShippingLabelService $shippingLabelService,
        protected ImageService $imageService,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        return Inertia::render('leads/Index', [
            'statuses' => Lead::getAvailableStatuses(),
        ]);
    }

    public function create(): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $lead = Lead::create([
            'store_id' => $store->id,
            'user_id' => auth()->id(),
            'status' => Lead::STATUS_PENDING_KIT_REQUEST,
            'type' => Lead::TYPE_MAIL_IN,
        ]);

        return redirect()->route('web.leads.show', $lead);
    }

    public function show(Lead $lead): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $lead->store_id !== $store->id) {
            abort(404);
        }

        $lead->load([
            'customer.addresses',
            'shippingAddress',
            'user',
            'assignedUser',
            'items.images',
            'items.category',
            'items.reviewer',
            'statusHistories.user',
            'outboundLabel',
            'returnLabel',
            'notes.user',
            'images',
            'transaction',
        ]);

        $statuses = Lead::getAvailableStatuses();

        // Get team members for assignment
        $teamMembers = $store->storeUsers()
            ->with('user')
            ->get()
            ->map(fn ($storeUser) => [
                'id' => $storeUser->user_id,
                'name' => $storeUser->user?->name ?? 'Unknown',
            ])
            ->filter(fn ($member) => $member['id'] !== null)
            ->values();

        $categories = Category::where('store_id', $store->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'full_path' => $category->full_path,
                'parent_id' => $category->parent_id,
                'level' => $category->level,
                'template_id' => $category->template_id,
            ]);

        // Customer addresses
        $customerAddresses = [];
        if ($lead->customer) {
            $customerAddresses = $lead->customer->addresses
                ->map(fn ($address) => [
                    'id' => $address->id,
                    'full_name' => $address->full_name,
                    'company' => $address->company,
                    'address' => $address->address,
                    'address2' => $address->address2,
                    'city' => $address->city,
                    'state_id' => $address->state_id,
                    'country_id' => $address->country_id,
                    'zip' => $address->zip,
                    'phone' => $address->phone,
                    'is_default' => $address->is_default,
                    'is_shipping' => $address->is_shipping,
                    'one_line_address' => $address->one_line_address,
                    'is_valid_for_shipping' => $address->isValidForShipping(),
                ]);
        }

        return Inertia::render('leads/Show', [
            'lead' => $this->formatLead($lead),
            'attachments' => $lead->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url,
                'thumbnail_url' => $image->thumbnail_url,
                'alt_text' => $image->alt_text,
                'file_name' => $image->original_filename ?? null,
                'file_type' => $image->mime_type ?? null,
            ]),
            'statuses' => $statuses,
            'paymentMethods' => $this->getPaymentMethods(),
            'teamMembers' => $teamMembers,
            'shippingOptions' => [
                'service_types' => ShippingLabelService::getServiceTypes(),
                'packaging_types' => ShippingLabelService::getPackagingTypes(),
                'default_package' => ShippingLabelService::getDefaultPackageDimensions(),
                'is_configured' => $this->shippingLabelService->isConfigured(),
            ],
            'customerAddresses' => $customerAddresses,
            'categories' => $categories,
            'preciousMetals' => $this->getPreciousMetals(),
            'conditions' => $this->getConditions(),
            'activityLogs' => Inertia::defer(fn () => app(ActivityLogFormatter::class)->formatForSubject($lead)),
        ]);
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'customer_notes' => 'nullable|string|max:1000',
            'internal_notes' => 'nullable|string|max:1000',
            'bin_location' => 'nullable|string|max:255',
            'preliminary_offer' => 'nullable|numeric|min:0',
        ]);

        $lead->update($validated);

        return redirect()->route('web.leads.show', $lead)
            ->with('success', 'Lead updated successfully.');
    }

    public function destroy(Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);

        $lead->delete();

        return redirect()->route('web.leads.index')
            ->with('success', 'Lead deleted successfully.');
    }

    public function changeStatus(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);

        $validated = $request->validate([
            'status' => 'required|string|in:'.implode(',', array_keys(Lead::getAvailableStatuses())),
        ]);

        if (! $lead->canChangeStatusTo($validated['status'])) {
            return back()->with('error', 'Cannot change to this status.');
        }

        $lead->update(['status' => $validated['status']]);

        $statusLabel = Lead::getAvailableStatuses()[$validated['status']];

        return back()->with('success', "Status changed to {$statusLabel}.");
    }

    public function submitOffer(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);

        if (! $lead->canSubmitOffer()) {
            return back()->with('error', 'Cannot submit offer for this lead.');
        }

        $validated = $request->validate([
            'offer' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $lead->submitOffer((float) $validated['offer']);

        if (! empty($validated['notes'])) {
            $lead->update(['internal_notes' => $validated['notes']]);
        }

        return back()->with('success', 'Offer submitted.');
    }

    public function acceptOffer(Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);

        if (! $lead->canAcceptOffer()) {
            return back()->with('error', 'Cannot accept offer for this lead.');
        }

        $lead->acceptOffer();

        return back()->with('success', 'Offer accepted.');
    }

    public function declineOffer(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $lead->declineOffer($validated['reason'] ?? null);

        return back()->with('success', 'Offer declined.');
    }

    public function processPayment(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);

        if (! $lead->canProcessPayment()) {
            return back()->with('error', 'Cannot process payment for this lead.');
        }

        $validated = $request->validate([
            'payment_method' => 'required|string|in:cash,check,store_credit,ach,paypal,venmo,wire_transfer',
            'payment_details' => 'nullable|array',
        ]);

        $lead->processPayment($validated['payment_method']);

        if (! empty($validated['payment_details'])) {
            $lead->update(['payment_details' => $validated['payment_details']]);
        }

        // Convert lead to transaction (buy)
        try {
            $conversionService = app(LeadConversionService::class);
            $transaction = $conversionService->convertToTransaction($lead);

            return back()->with('success', "Payment processed. Buy #{$transaction->transaction_number} created.");
        } catch (\RuntimeException $e) {
            return back()->with('success', 'Payment processed.');
        }
    }

    public function confirmKitRequest(Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);
        $lead->confirmKitRequest();

        return back()->with('success', 'Kit request confirmed.');
    }

    public function rejectKitRequest(Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);
        $lead->rejectKitRequest();

        return back()->with('success', 'Kit request rejected.');
    }

    public function holdKitRequest(Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);
        $lead->holdKitRequest();

        return back()->with('success', 'Kit request placed on hold.');
    }

    public function markKitSent(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);

        $validated = $request->validate([
            'tracking_number' => ['required', 'string', 'max:100'],
            'carrier' => ['nullable', 'string', 'max:50'],
        ]);

        $lead->markKitSent(
            $validated['tracking_number'],
            $validated['carrier'] ?? 'fedex'
        );

        return back()->with('success', 'Kit marked as sent.');
    }

    public function markKitDelivered(Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);
        $lead->markKitDelivered();

        return back()->with('success', 'Kit marked as delivered.');
    }

    public function markItemsReceived(Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);
        $lead->markItemsReceived();

        return back()->with('success', 'Items marked as received.');
    }

    public function markItemsReviewed(Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);
        $lead->markItemsReviewed();

        return back()->with('success', 'Items marked as reviewed.');
    }

    public function markItemsReturned(Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);
        $lead->markItemsReturned();

        return back()->with('success', 'Items marked as returned.');
    }

    public function assignLead(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($lead);

        $validated = $request->validate([
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);

        $lead->update(['assigned_to' => $validated['assigned_to']]);

        return back()->with('success', 'Lead assigned successfully.');
    }

    public function bulkAction(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'action' => 'required|string',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:leads,id',
        ]);

        $leads = Lead::where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->get();

        $count = $leads->count();

        if ($validated['action'] === 'delete') {
            $leads->each->delete();

            return redirect()->route('web.leads.index')
                ->with('success', "{$count} lead(s) deleted.");
        }

        return redirect()->route('web.leads.index')
            ->with('success', "{$count} lead(s) processed.");
    }

    protected function authorizeLead(Lead $lead): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $lead->store_id !== $store->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatLead(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'lead_number' => $lead->lead_number,
            'status' => $lead->status,
            'status_label' => $lead->statusModel?->name ?? ucfirst(str_replace('_', ' ', $lead->status ?? '')),
            'status_color' => $lead->statusModel?->color ?? '#6b7280',
            'type' => $lead->type,
            'source' => $lead->source,
            'preliminary_offer' => $lead->preliminary_offer,
            'final_offer' => $lead->final_offer,
            'estimated_value' => $lead->estimated_value,
            'payment_method' => $lead->payment_method,
            'payment_details' => $lead->payment_details,
            'bin_location' => $lead->bin_location,
            'customer_notes' => $lead->customer_notes,
            'internal_notes' => $lead->internal_notes,
            'customer_description' => $lead->customer_description,
            'customer_amount' => $lead->customer_amount,
            'customer_categories' => $lead->customer_categories,
            'outbound_tracking_number' => $lead->outbound_tracking_number,
            'outbound_carrier' => $lead->outbound_carrier,
            'return_tracking_number' => $lead->return_tracking_number,
            'return_carrier' => $lead->return_carrier,
            'total_value' => $lead->total_value,
            'total_buy_price' => $lead->total_buy_price,
            'total_dwt' => $lead->total_dwt,
            'item_count' => $lead->item_count,
            'is_converted' => $lead->isConverted(),
            'transaction_id' => $lead->transaction_id,
            'transaction_number' => $lead->transaction?->transaction_number,
            'offer_given_at' => $lead->offer_given_at?->toISOString(),
            'offer_accepted_at' => $lead->offer_accepted_at?->toISOString(),
            'payment_processed_at' => $lead->payment_processed_at?->toISOString(),
            'kit_sent_at' => $lead->kit_sent_at?->toISOString(),
            'kit_delivered_at' => $lead->kit_delivered_at?->toISOString(),
            'items_received_at' => $lead->items_received_at?->toISOString(),
            'items_reviewed_at' => $lead->items_reviewed_at?->toISOString(),
            'created_at' => $lead->created_at->toISOString(),
            'updated_at' => $lead->updated_at->toISOString(),
            'customer' => $lead->customer ? [
                'id' => $lead->customer->id,
                'full_name' => $lead->customer->full_name,
                'first_name' => $lead->customer->first_name,
                'last_name' => $lead->customer->last_name,
                'email' => $lead->customer->email,
                'phone' => $lead->customer->phone,
                'addresses' => $lead->customer->addresses->map(fn ($a) => [
                    'id' => $a->id,
                    'one_line_address' => $a->one_line_address,
                    'is_default' => $a->is_default,
                ]),
            ] : null,
            'shipping_address' => $lead->shippingAddress ? [
                'id' => $lead->shippingAddress->id,
                'full_name' => $lead->shippingAddress->full_name,
                'one_line_address' => $lead->shippingAddress->one_line_address,
            ] : null,
            'user' => $lead->user ? [
                'id' => $lead->user->id,
                'name' => $lead->user->name,
            ] : null,
            'assigned_user' => $lead->assignedUser ? [
                'id' => $lead->assignedUser->id,
                'name' => $lead->assignedUser->name,
            ] : null,
            'outbound_label' => $lead->outboundLabel ? [
                'id' => $lead->outboundLabel->id,
                'tracking_number' => $lead->outboundLabel->tracking_number,
                'carrier' => $lead->outboundLabel->carrier,
                'status' => $lead->outboundLabel->status,
            ] : null,
            'return_label' => $lead->returnLabel ? [
                'id' => $lead->returnLabel->id,
                'tracking_number' => $lead->returnLabel->tracking_number,
                'carrier' => $lead->returnLabel->carrier,
                'status' => $lead->returnLabel->status,
            ] : null,
            'items' => $lead->items->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'sku' => $item->sku,
                'category' => $item->category ? [
                    'id' => $item->category->id,
                    'name' => $item->category->name,
                ] : null,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'buy_price' => $item->buy_price,
                'dwt' => $item->dwt,
                'precious_metal' => $item->precious_metal,
                'condition' => $item->condition,
                'attributes' => $item->attributes ?? [],
                'is_reviewed' => $item->isReviewed(),
                'reviewed_by' => $item->reviewer ? $item->reviewer->name : null,
                'images' => $item->images->map(fn ($image) => [
                    'id' => $image->id,
                    'url' => $image->url,
                    'thumbnail_url' => $image->thumbnail_url,
                ]),
            ]),
            'status_histories' => $lead->statusHistories->map(fn ($history) => [
                'id' => $history->id,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'notes' => $history->notes,
                'user' => $history->user ? [
                    'id' => $history->user->id,
                    'name' => $history->user->name,
                ] : null,
                'created_at' => $history->created_at->toISOString(),
            ]),
            'notes' => $lead->notes->map(fn ($note) => [
                'id' => $note->id,
                'content' => $note->content,
                'type' => $note->type,
                'user' => $note->user ? [
                    'id' => $note->user->id,
                    'name' => $note->user->name,
                ] : null,
                'created_at' => $note->created_at->toISOString(),
            ]),
        ];
    }

    /**
     * @return array<array{value: string, label: string}>
     */
    protected function getPaymentMethods(): array
    {
        return [
            ['value' => Lead::PAYMENT_CASH, 'label' => 'Cash'],
            ['value' => Lead::PAYMENT_CHECK, 'label' => 'Check'],
            ['value' => Lead::PAYMENT_STORE_CREDIT, 'label' => 'Store Credit'],
            ['value' => Lead::PAYMENT_ACH, 'label' => 'ACH'],
            ['value' => Lead::PAYMENT_PAYPAL, 'label' => 'PayPal'],
            ['value' => Lead::PAYMENT_VENMO, 'label' => 'Venmo'],
            ['value' => Lead::PAYMENT_WIRE_TRANSFER, 'label' => 'Wire Transfer'],
        ];
    }

    /**
     * @return array<array{value: string, label: string}>
     */
    protected function getPreciousMetals(): array
    {
        return [
            ['value' => 'gold_10k', 'label' => '10K Gold'],
            ['value' => 'gold_14k', 'label' => '14K Gold'],
            ['value' => 'gold_18k', 'label' => '18K Gold'],
            ['value' => 'gold_22k', 'label' => '22K Gold'],
            ['value' => 'gold_24k', 'label' => '24K Gold'],
            ['value' => 'silver', 'label' => 'Silver'],
            ['value' => 'platinum', 'label' => 'Platinum'],
            ['value' => 'palladium', 'label' => 'Palladium'],
        ];
    }

    /**
     * @return array<array{value: string, label: string}>
     */
    protected function getConditions(): array
    {
        return [
            ['value' => 'new', 'label' => 'New'],
            ['value' => 'like_new', 'label' => 'Like New'],
            ['value' => 'used', 'label' => 'Used'],
            ['value' => 'damaged', 'label' => 'Damaged'],
        ];
    }
}
