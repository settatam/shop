<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Services\ActivityLogFormatter;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VendorController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $query = Vendor::where('store_id', $store->id)
            ->withCount(['purchaseOrders', 'productVariants']);

        if ($request->has('search') && $request->input('search')) {
            $query->search($request->input('search'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sortField = $request->input('sort', 'name');
        $sortDirection = $request->input('direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        $vendors = $query->paginate($request->input('per_page', 15))
            ->through(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'code' => $v->code,
                'company_name' => $v->company_name,
                'display_name' => $v->display_name,
                'email' => $v->email,
                'phone' => $v->phone,
                'city' => $v->city,
                'state' => $v->state,
                'country' => $v->country,
                'payment_terms' => $v->payment_terms,
                'is_active' => $v->is_active,
                'purchase_orders_count' => $v->purchase_orders_count,
                'product_variants_count' => $v->product_variants_count,
            ]);

        return Inertia::render('vendors/Index', [
            'vendors' => $vendors,
            'filters' => [
                'search' => $request->input('search', ''),
                'is_active' => $request->input('is_active'),
            ],
            'paymentTerms' => Vendor::PAYMENT_TERMS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'code' => 'nullable|string|max:50|unique:vendors,code,NULL,id,store_id,'.$store->id,
            'company_name' => 'nullable|string|max:191',
            'email' => 'nullable|email|max:191',
            'phone' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:191',
            'address_line1' => 'nullable|string|max:191',
            'address_line2' => 'nullable|string|max:191',
            'city' => 'nullable|string|max:191',
            'state' => 'nullable|string|max:191',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'tax_id' => 'nullable|string|max:50',
            'payment_terms' => 'nullable|string|in:'.implode(',', Vendor::PAYMENT_TERMS),
            'lead_time_days' => 'nullable|integer|min:0|max:365',
            'currency_code' => 'nullable|string|size:3',
            'contact_name' => 'nullable|string|max:191',
            'contact_email' => 'nullable|email|max:191',
            'contact_phone' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        Vendor::create([
            ...$validated,
            'store_id' => $store->id,
        ]);

        return redirect()->route('web.vendors.index')
            ->with('success', 'Vendor created successfully.');
    }

    public function show(Vendor $vendor): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $vendor->store_id !== $store->id) {
            abort(404);
        }

        $vendor->loadCount(['purchaseOrders', 'productVariants']);
        $vendor->load([
            'purchaseOrders' => function ($query) {
                $query->latest()->take(5);
            },
            'notes.user',
        ]);

        return Inertia::render('vendors/Show', [
            'vendor' => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'code' => $vendor->code,
                'company_name' => $vendor->company_name,
                'display_name' => $vendor->display_name,
                'email' => $vendor->email,
                'phone' => $vendor->phone,
                'website' => $vendor->website,
                'address_line1' => $vendor->address_line1,
                'address_line2' => $vendor->address_line2,
                'city' => $vendor->city,
                'state' => $vendor->state,
                'postal_code' => $vendor->postal_code,
                'country' => $vendor->country,
                'full_address' => $vendor->full_address,
                'tax_id' => $vendor->tax_id,
                'payment_terms' => $vendor->payment_terms,
                'lead_time_days' => $vendor->lead_time_days,
                'currency_code' => $vendor->currency_code,
                'contact_name' => $vendor->contact_name,
                'contact_email' => $vendor->contact_email,
                'contact_phone' => $vendor->contact_phone,
                'is_active' => $vendor->is_active,
                'notes' => $vendor->notes,
                'purchase_orders_count' => $vendor->purchase_orders_count,
                'product_variants_count' => $vendor->product_variants_count,
                'recent_purchase_orders' => $vendor->purchaseOrders->map(fn ($po) => [
                    'id' => $po->id,
                    'po_number' => $po->po_number,
                    'status' => $po->status,
                    'total' => $po->total,
                    'order_date' => $po->order_date?->format('M d, Y'),
                ]),
                'note_entries' => $vendor->notes->map(fn ($note) => [
                    'id' => $note->id,
                    'content' => $note->content,
                    'user' => $note->user ? [
                        'id' => $note->user->id,
                        'name' => $note->user->name,
                    ] : null,
                    'created_at' => $note->created_at->toISOString(),
                    'updated_at' => $note->updated_at->toISOString(),
                ]),
            ],
            'paymentTerms' => Vendor::PAYMENT_TERMS,
            'activityLogs' => Inertia::defer(fn () => app(ActivityLogFormatter::class)->formatForSubject($vendor)),
        ]);
    }

    public function update(Request $request, Vendor $vendor): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $vendor->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'code' => 'nullable|string|max:50|unique:vendors,code,'.$vendor->id.',id,store_id,'.$store->id,
            'company_name' => 'nullable|string|max:191',
            'email' => 'nullable|email|max:191',
            'phone' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:191',
            'address_line1' => 'nullable|string|max:191',
            'address_line2' => 'nullable|string|max:191',
            'city' => 'nullable|string|max:191',
            'state' => 'nullable|string|max:191',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'tax_id' => 'nullable|string|max:50',
            'payment_terms' => 'nullable|string|in:'.implode(',', Vendor::PAYMENT_TERMS),
            'lead_time_days' => 'nullable|integer|min:0|max:365',
            'currency_code' => 'nullable|string|size:3',
            'contact_name' => 'nullable|string|max:191',
            'contact_email' => 'nullable|email|max:191',
            'contact_phone' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $vendor->update($validated);

        return redirect()->back()
            ->with('success', 'Vendor updated successfully.');
    }

    public function destroy(Vendor $vendor): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $vendor->store_id !== $store->id) {
            abort(404);
        }

        // Check for open purchase orders
        $hasOpenPOs = $vendor->purchaseOrders()
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->exists();

        if ($hasOpenPOs) {
            return redirect()->back()
                ->with('error', 'Cannot delete vendor with open purchase orders.');
        }

        $vendor->delete();

        return redirect()->route('web.vendors.index')
            ->with('success', 'Vendor deleted successfully.');
    }
}
