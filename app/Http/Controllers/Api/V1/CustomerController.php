<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    /**
     * Display a listing of customers with search support.
     */
    public function index(Request $request): JsonResponse
    {
        $storeId = $this->storeContext->getCurrentStoreId();

        $query = Customer::where('store_id', $storeId);

        // Search support
        if ($term = $request->input('q')) {
            // Use Scout search if available, otherwise fallback to basic search
            if (config('scout.driver') !== 'collection') {
                $query = Customer::search($term)
                    ->where('store_id', $storeId);

                $customers = $query->take((int) $request->input('limit', 10))->get();
            } else {
                $query->where(function ($q) use ($term) {
                    $q->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone_number', 'like', "%{$term}%");
                });

                $customers = $query->limit($request->input('limit', 10))->get();
            }
        } else {
            $customers = $query
                ->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 15));

            return response()->json([
                'data' => $customers->map(fn ($c) => $this->formatCustomer($c)),
                'meta' => [
                    'current_page' => $customers->currentPage(),
                    'last_page' => $customers->lastPage(),
                    'per_page' => $customers->perPage(),
                    'total' => $customers->total(),
                ],
            ]);
        }

        return response()->json([
            'data' => $customers->map(fn ($c) => $this->formatCustomer($c)),
        ]);
    }

    /**
     * Store a newly created customer.
     */
    public function store(Request $request): JsonResponse
    {
        $storeId = $this->storeContext->getCurrentStoreId();

        $validated = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'address2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:100',
            'state_id' => 'nullable|integer',
            'country_id' => 'nullable|integer',
            'zip' => 'nullable|string|max:20',
            'accepts_marketing' => 'boolean',
        ]);

        // Require at least a name or email
        if (empty($validated['first_name']) && empty($validated['last_name']) && empty($validated['email'])) {
            return response()->json([
                'message' => 'At least a name or email is required.',
                'errors' => [
                    'first_name' => ['Please provide a name or email.'],
                ],
            ], 422);
        }

        $customer = Customer::create([
            ...$validated,
            'store_id' => $storeId,
            'is_active' => true,
        ]);

        return response()->json([
            'data' => $this->formatCustomer($customer),
            'message' => 'Customer created successfully.',
        ], 201);
    }

    /**
     * Display the specified customer.
     */
    public function show(Customer $customer): JsonResponse
    {
        $storeId = $this->storeContext->getCurrentStoreId();

        if ($customer->store_id !== $storeId) {
            abort(404);
        }

        $customer->loadCount(['orders', 'transactions', 'repairs']);

        return response()->json([
            'data' => $this->formatCustomer($customer, detailed: true),
        ]);
    }

    /**
     * Update the specified customer.
     */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $storeId = $this->storeContext->getCurrentStoreId();

        if ($customer->store_id !== $storeId) {
            abort(404);
        }

        $validated = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'address2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:100',
            'state_id' => 'nullable|integer',
            'country_id' => 'nullable|integer',
            'zip' => 'nullable|string|max:20',
            'accepts_marketing' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $customer->update($validated);

        return response()->json([
            'data' => $this->formatCustomer($customer),
            'message' => 'Customer updated successfully.',
        ]);
    }

    /**
     * Remove the specified customer.
     */
    public function destroy(Customer $customer): JsonResponse
    {
        $storeId = $this->storeContext->getCurrentStoreId();

        if ($customer->store_id !== $storeId) {
            abort(404);
        }

        $customer->delete();

        return response()->json([
            'message' => 'Customer deleted successfully.',
        ]);
    }

    /**
     * Format customer for API response.
     *
     * @return array<string, mixed>
     */
    protected function formatCustomer(Customer $customer, bool $detailed = false): array
    {
        // Get primary address from addresses table if customer doesn't have address on record
        $primaryAddress = null;
        if (empty($customer->address)) {
            $primaryAddress = $customer->getPrimaryShippingAddress();
            // Eager load state relationship if we have an address
            $primaryAddress?->load('state');
        }

        // Use address from addresses table if available, otherwise fall back to customer fields
        $address = $primaryAddress?->address ?? $customer->address;
        $address2 = $primaryAddress?->address2 ?? $customer->address2;
        $city = $primaryAddress?->city ?? $customer->city;
        $zip = $primaryAddress?->zip ?? $customer->zip;

        // Get state - either from customer.state, or from the address's state relationship
        $state = $customer->state;
        if (empty($state) && $primaryAddress?->state) {
            $state = $primaryAddress->state->abbreviation;
        }

        $data = [
            'id' => $customer->id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'full_name' => $customer->full_name,
            'email' => $customer->email,
            'phone_number' => $customer->phone_number,
            'address' => $address,
            'address2' => $address2,
            'city' => $city,
            'state' => $state,
            'zip' => $zip,
            'is_active' => $customer->is_active,
            'created_at' => $customer->created_at?->toISOString(),
        ];

        if ($detailed) {
            $data = array_merge($data, [
                'state_id' => $primaryAddress?->state_id ?? $customer->state_id,
                'country_id' => $primaryAddress?->country_id ?? $customer->country_id,
                'accepts_marketing' => $customer->accepts_marketing,
                'orders_count' => $customer->orders_count ?? 0,
                'transactions_count' => $customer->transactions_count ?? 0,
                'repairs_count' => $customer->repairs_count ?? 0,
                'updated_at' => $customer->updated_at?->toISOString(),
            ]);
        }

        return $data;
    }
}
