<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Vendor;
use App\Services\ActivityLogFormatter;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $sortField = $request->input('sort', 'company_name');
        $sortDirection = $request->input('direction', 'asc');

        // Handle sorting - company_name might be null, so use COALESCE with name
        if ($sortField === 'company_name') {
            $query->orderByRaw("COALESCE(company_name, name) {$sortDirection}");
        } else {
            $query->orderBy($sortField, $sortDirection);
        }

        $vendors = $query->paginate($request->input('per_page', 15))
            ->through(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'code' => $v->code,
                'company_name' => $v->company_name,
                'display_name' => $v->display_name,
                'email' => $v->email,
                'phone' => $v->phone,
                'contact_name' => $v->contact_name,
                'address_line1' => $v->address_line1,
                'city' => $v->city,
                'state' => $v->state,
                'country' => $v->country,
                'payment_terms' => $v->payment_terms,
                'is_active' => $v->is_active,
                'purchase_orders_count' => $v->purchase_orders_count,
                'product_variants_count' => $v->product_variants_count,
                'memo_total' => 0, // TODO: Implement when memo relationship is added
                'repair_total' => 0, // TODO: Implement when repair relationship is added
                'sales_total' => 0, // TODO: Implement when sales relationship is added
                'last_transaction_date' => null, // TODO: Implement when transaction relationship is added
            ]);

        return Inertia::render('vendors/Index', [
            'vendors' => $vendors,
            'filters' => [
                'search' => $request->input('search', ''),
                'is_active' => $request->input('is_active'),
                'per_page' => $request->input('per_page', 15),
                'sort' => $sortField,
                'direction' => $sortDirection,
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

        $vendor->loadCount(['purchaseOrders', 'productVariants', 'memos', 'repairs', 'products']);
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
                'memos_count' => $vendor->memos_count,
                'repairs_count' => $vendor->repairs_count,
                'products_count' => $vendor->products_count,
                'recent_purchase_orders' => $vendor->purchaseOrders->map(fn ($po) => [
                    'id' => $po->id,
                    'po_number' => $po->po_number,
                    'status' => $po->status,
                    'total' => $po->total,
                    'order_date' => $po->order_date?->format('M d, Y'),
                ]),
                'note_entries' => $vendor->getRelation('notes')?->map(fn ($note) => [
                    'id' => $note->id,
                    'content' => $note->content,
                    'user' => $note->user ? [
                        'id' => $note->user->id,
                        'name' => $note->user->name,
                    ] : null,
                    'created_at' => $note->created_at->toISOString(),
                    'updated_at' => $note->updated_at->toISOString(),
                ]) ?? [],
            ],
            'paymentTerms' => Vendor::PAYMENT_TERMS,
            'soldItems' => Inertia::defer(fn () => $this->getSoldItems($vendor)),
            'memos' => Inertia::defer(fn () => $this->getVendorMemos($vendor)),
            'repairs' => Inertia::defer(fn () => $this->getVendorRepairs($vendor)),
            'currentStock' => Inertia::defer(fn () => $this->getCurrentStock($vendor)),
            'activityLogs' => Inertia::defer(fn () => app(ActivityLogFormatter::class)->formatForSubject($vendor)),
        ]);
    }

    protected function getSoldItems(Vendor $vendor): array
    {
        $soldItems = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('products.vendor_id', $vendor->id)
            ->whereIn('orders.status', ['confirmed', 'completed', 'shipped', 'delivered'])
            ->select([
                'order_items.id',
                'order_items.sku',
                'order_items.title',
                'order_items.price',
                'order_items.cost',
                'order_items.wholesale_value',
                'order_items.quantity',
                'orders.id as order_id',
                'orders.invoice_number',
                'orders.date_of_purchase',
                'orders.created_at as order_date',
            ])
            ->orderByDesc('orders.created_at')
            ->get();

        $items = $soldItems->map(function ($item) {
            $cost = (float) ($item->cost ?? 0);
            $price = (float) ($item->price ?? 0);
            $wholesale = (float) ($item->wholesale_value ?? 0);
            $quantity = (int) ($item->quantity ?? 1);
            $totalCost = $cost * $quantity;
            $totalWholesale = $wholesale * $quantity;
            $totalSold = $price * $quantity;
            $profit = $totalSold - $totalCost;
            $profitPercent = $totalCost > 0 ? ($profit / $totalCost) * 100 : 0;

            return [
                'id' => $item->id,
                'sku' => $item->sku,
                'title' => $item->title,
                'order_id' => $item->order_id,
                'invoice_number' => $item->invoice_number,
                'date' => $this->formatDate($item->date_of_purchase ?? $item->order_date),
                'cost' => $totalCost,
                'wholesale' => $totalWholesale,
                'amount_sold' => $totalSold,
                'profit' => $profit,
                'profit_percent' => round($profitPercent, 1),
            ];
        });

        $totals = [
            'cost' => $items->sum('cost'),
            'wholesale' => $items->sum('wholesale'),
            'amount_sold' => $items->sum('amount_sold'),
            'profit' => $items->sum('profit'),
            'profit_percent' => $items->sum('cost') > 0
                ? round(($items->sum('profit') / $items->sum('cost')) * 100, 1)
                : 0,
        ];

        return [
            'items' => $items->values()->all(),
            'totals' => $totals,
        ];
    }

    protected function getVendorMemos(Vendor $vendor): array
    {
        return $vendor->memos()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($memo) => [
                'id' => $memo->id,
                'memo_number' => $memo->memo_number,
                'status' => $memo->status,
                'total' => $memo->total,
                'grand_total' => $memo->grand_total,
                'user' => $memo->user?->name,
                'created_at' => $memo->created_at->format('M d, Y'),
            ])
            ->all();
    }

    protected function getVendorRepairs(Vendor $vendor): array
    {
        return $vendor->repairs()
            ->with(['user:id,name', 'customer:id,first_name,last_name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($repair) => [
                'id' => $repair->id,
                'repair_number' => $repair->repair_number,
                'status' => $repair->status,
                'total' => $repair->total,
                'customer' => $repair->customer
                    ? trim("{$repair->customer->first_name} {$repair->customer->last_name}")
                    : null,
                'user' => $repair->user?->name,
                'created_at' => $repair->created_at->format('M d, Y'),
            ])
            ->all();
    }

    protected function getCurrentStock(Vendor $vendor): array
    {
        return $vendor->products()
            ->with(['variants' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
            ->where(function ($query) {
                $query->where('quantity', '>', 0)
                    ->orWhereHas('variants', fn ($q) => $q->where('quantity', '>', 0));
            })
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($product) {
                $variant = $product->variants->first();

                return [
                    'id' => $product->id,
                    'title' => $product->title,
                    'sku' => $variant?->sku,
                    'quantity' => $variant?->quantity ?? $product->quantity ?? 0,
                    'price' => $variant?->price ?? 0,
                    'cost' => $variant?->cost ?? 0,
                    'status' => $variant?->status ?? 'active',
                ];
            })
            ->all();
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

    /**
     * Export vendors to CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            abort(404);
        }

        $query = Vendor::where('store_id', $store->id)
            ->withCount(['purchaseOrders', 'memos', 'repairs']);

        if ($request->has('search') && $request->input('search')) {
            $query->search($request->input('search'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $vendors = $query->orderBy('name')->get();
        $filename = 'vendors-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($vendors) {
            $handle = fopen('php://output', 'w');

            // Header row
            fputcsv($handle, [
                'Name',
                'Code',
                'Company Name',
                'Email',
                'Phone',
                'Contact Name',
                'Contact Email',
                'Contact Phone',
                'Address',
                'City',
                'State',
                'Postal Code',
                'Country',
                'Payment Terms',
                'Lead Time (Days)',
                'Currency',
                'Tax ID',
                'Active',
                'Purchase Orders',
                'Memos',
                'Repairs',
                'Notes',
            ]);

            foreach ($vendors as $vendor) {
                fputcsv($handle, [
                    $vendor->name,
                    $vendor->code,
                    $vendor->company_name,
                    $vendor->email,
                    $vendor->phone,
                    $vendor->contact_name,
                    $vendor->contact_email,
                    $vendor->contact_phone,
                    $vendor->address_line1,
                    $vendor->city,
                    $vendor->state,
                    $vendor->postal_code,
                    $vendor->country,
                    $vendor->payment_terms,
                    $vendor->lead_time_days,
                    $vendor->currency_code,
                    $vendor->tax_id,
                    $vendor->is_active ? 'Yes' : 'No',
                    $vendor->purchase_orders_count,
                    $vendor->memos_count,
                    $vendor->repairs_count,
                    $vendor->notes,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Format a date value that may be a string or Carbon instance.
     */
    protected function formatDate(mixed $date): ?string
    {
        if (! $date) {
            return null;
        }

        if ($date instanceof \Carbon\Carbon || $date instanceof \DateTimeInterface) {
            return $date->format('M d, Y');
        }

        if (is_string($date)) {
            try {
                return \Carbon\Carbon::parse($date)->format('M d, Y');
            } catch (\Exception) {
                return $date;
            }
        }

        return null;
    }
}
