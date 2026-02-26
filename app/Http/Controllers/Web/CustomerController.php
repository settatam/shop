<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Requests\UploadCustomerDocumentRequest;
use App\Models\Address;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\LeadSource;
use App\Models\State;
use App\Services\ActivityLogFormatter;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    public function index(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $query = Customer::query()
            ->where('store_id', $store->id)
            ->with(['leadSource', 'idFront'])
            ->withCount(['orders as items_purchased_count' => function ($q) {
                $q->whereHas('items');
            }])
            ->withCount(['transactions as items_sold_count' => function ($q) {
                $q->whereHas('items');
            }])
            ->withSum('orders', 'total')
            ->withSum('transactions', 'final_offer');

        // Search
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%");
            });
        }

        // Filter by lead source
        if ($leadSourceId = $request->get('lead_source_id')) {
            $query->where('lead_source_id', $leadSourceId);
        }

        // Filter by date range (based on created_at)
        if ($fromDate = $request->get('from_date')) {
            $query->whereDate('created_at', '>=', $fromDate);
        }

        if ($toDate = $request->get('to_date')) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        $customers = $query->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        // Add last transaction date for each customer
        $customers->getCollection()->transform(function ($customer) {
            $lastTransaction = $customer->transactions()->latest()->first();
            $lastOrder = $customer->orders()->latest()->first();

            $lastTransactionDate = null;
            if ($lastTransaction && $lastOrder) {
                $lastTransactionDate = $lastTransaction->created_at->gt($lastOrder->created_at)
                    ? $lastTransaction->created_at
                    : $lastOrder->created_at;
            } elseif ($lastTransaction) {
                $lastTransactionDate = $lastTransaction->created_at;
            } elseif ($lastOrder) {
                $lastTransactionDate = $lastOrder->created_at;
            }

            $customer->last_transaction_date = $lastTransactionDate;

            return $customer;
        });

        $leadSources = LeadSource::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug']);

        return Inertia::render('customers/Index', [
            'customers' => $customers,
            'leadSources' => $leadSources,
            'filters' => [
                'search' => $request->get('search', ''),
                'lead_source_id' => $request->get('lead_source_id', ''),
                'from_date' => $request->get('from_date', ''),
                'to_date' => $request->get('to_date', ''),
            ],
        ]);
    }

    public function show(Customer $customer): Response
    {
        $store = $this->storeContext->getCurrentStore();

        // Ensure customer belongs to current store
        if ($customer->store_id !== $store->id) {
            abort(404);
        }

        $customer->load([
            'leadSource',
            'documents',
            'addresses',
            'transactions' => fn ($q) => $q->with('items')->latest()->limit(10),
            'orders' => fn ($q) => $q->with('items')->latest()->limit(10),
            'notes.user',
        ]);

        // Calculate stats
        $stats = [
            'total_buys' => $customer->transactions()->count(),
            'total_sales' => $customer->orders()->count(),
            'total_buy_value' => $customer->transactions()->sum('final_offer') ?? 0,
            'total_sales_value' => $customer->orders()->sum('total') ?? 0,
            'store_credit_balance' => (float) $customer->store_credit_balance,
            'last_activity' => $customer->transactions()->latest()->first()?->created_at
                ?? $customer->orders()->latest()->first()?->created_at,
        ];

        $leadSources = LeadSource::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug']);

        // Map notes to frontend format
        $noteEntries = $customer->notes->map(fn ($note) => [
            'id' => $note->id,
            'content' => $note->content,
            'user' => $note->user ? [
                'id' => $note->user->id,
                'name' => $note->user->name,
            ] : null,
            'created_at' => $note->created_at->toISOString(),
            'updated_at' => $note->updated_at->toISOString(),
        ]);

        return Inertia::render('customers/Show', [
            'customer' => $customer,
            'stats' => $stats,
            'leadSources' => $leadSources,
            'documentTypes' => [
                ['value' => CustomerDocument::TYPE_ID_FRONT, 'label' => 'ID Front'],
                ['value' => CustomerDocument::TYPE_ID_BACK, 'label' => 'ID Back'],
                ['value' => CustomerDocument::TYPE_OTHER, 'label' => 'Other Document'],
            ],
            'addressTypes' => collect(Address::getTypes())->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
            ])->values(),
            'activityLogs' => Inertia::defer(fn () => app(ActivityLogFormatter::class)->formatForSubject($customer)),
            'noteEntries' => $noteEntries,
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($customer->store_id !== $store->id) {
            abort(404);
        }

        $customer->update($request->validated());

        return back()->with('success', 'Customer updated successfully.');
    }

    public function uploadDocument(UploadCustomerDocumentRequest $request, Customer $customer): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($customer->store_id !== $store->id) {
            abort(404);
        }

        $file = $request->file('document');

        // Determine storage disk
        $disk = config('filesystems.default') === 's3' ? 's3' : 'public';

        // Store the file
        $path = $file->store("customers/{$customer->id}/documents", $disk);

        // Create document record
        $customer->documents()->create([
            'type' => $request->type,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'notes' => $request->notes,
            'uploaded_by' => auth()->id(),
        ]);

        return back()->with('success', 'Document uploaded successfully.');
    }

    public function deleteDocument(Customer $customer, CustomerDocument $document): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($customer->store_id !== $store->id) {
            abort(404);
        }

        if ($document->customer_id !== $customer->id) {
            abort(404);
        }

        $document->delete();

        return back()->with('success', 'Document deleted successfully.');
    }

    public function storeAddress(Request $request, Customer $customer): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($customer->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'nickname' => 'nullable|string|max:255',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'address' => 'required|string|max:255',
            'address2' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'nullable|string|max:255',
            'zip' => 'required|string|max:20',
            'phone' => 'nullable|string|max:30',
            'type' => 'required|string|in:home,work,shipping,billing,other',
            'is_default' => 'boolean',
        ]);

        // If this is being set as default, unset any existing defaults
        if ($request->boolean('is_default')) {
            $customer->addresses()->update(['is_default' => false]);
        }

        // Look up state_id from abbreviation
        $stateId = null;
        if (! empty($validated['state'])) {
            $stateId = State::findByAbbreviation($validated['state'])?->id;
        }

        $customer->addresses()->create([
            'store_id' => $store->id,
            'nickname' => $validated['nickname'] ?? null,
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'company' => $validated['company'] ?? null,
            'address' => $validated['address'],
            'address2' => $validated['address2'] ?? null,
            'city' => $validated['city'],
            'state_id' => $stateId,
            'zip' => $validated['zip'],
            'phone' => $validated['phone'] ?? null,
            'type' => $validated['type'],
            'is_default' => $request->boolean('is_default'),
            'is_shipping' => in_array($validated['type'], ['shipping', 'home']),
            'is_billing' => in_array($validated['type'], ['billing', 'home']),
        ]);

        return back()->with('success', 'Address added successfully.');
    }

    public function updateAddress(Request $request, Customer $customer, Address $address): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($customer->store_id !== $store->id) {
            abort(404);
        }

        if ($address->addressable_id !== $customer->id || $address->addressable_type !== Customer::class) {
            abort(404);
        }

        $validated = $request->validate([
            'nickname' => 'nullable|string|max:255',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'address' => 'required|string|max:255',
            'address2' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'nullable|string|max:255',
            'zip' => 'required|string|max:20',
            'phone' => 'nullable|string|max:30',
            'type' => 'required|string|in:home,work,shipping,billing,other',
            'is_default' => 'boolean',
        ]);

        // If this is being set as default, unset any existing defaults
        if ($request->boolean('is_default') && ! $address->is_default) {
            $customer->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
        }

        // Look up state_id from abbreviation
        $stateId = null;
        if (! empty($validated['state'])) {
            $stateId = State::findByAbbreviation($validated['state'])?->id;
        }

        $address->update([
            'nickname' => $validated['nickname'] ?? null,
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'company' => $validated['company'] ?? null,
            'address' => $validated['address'],
            'address2' => $validated['address2'] ?? null,
            'city' => $validated['city'],
            'state_id' => $stateId,
            'zip' => $validated['zip'],
            'phone' => $validated['phone'] ?? null,
            'type' => $validated['type'],
            'is_default' => $request->boolean('is_default'),
            'is_shipping' => in_array($validated['type'], ['shipping', 'home']),
            'is_billing' => in_array($validated['type'], ['billing', 'home']),
        ]);

        return back()->with('success', 'Address updated successfully.');
    }

    public function deleteAddress(Customer $customer, Address $address): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($customer->store_id !== $store->id) {
            abort(404);
        }

        if ($address->addressable_id !== $customer->id || $address->addressable_type !== Customer::class) {
            abort(404);
        }

        $address->delete();

        return back()->with('success', 'Address deleted successfully.');
    }
}
