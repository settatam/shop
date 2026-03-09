<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\AamvaParserService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerIdScanController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected AamvaParserService $parser,
    ) {}

    /**
     * Parse an AAMVA barcode and optionally match an existing customer.
     */
    public function parse(Request $request): JsonResponse
    {
        $request->validate([
            'barcode' => 'required|string|min:50',
        ]);

        $barcode = $request->input('barcode');

        if (! $this->parser->isAamvaBarcode($barcode)) {
            return response()->json([
                'message' => 'The provided data does not appear to be a valid ID barcode.',
            ], 422);
        }

        $parsedData = $this->parser->parse($barcode);

        $existingCustomer = null;
        if ($parsedData['id_number']) {
            $storeId = $this->storeContext->getCurrentStoreId();

            $existingCustomer = Customer::query()
                ->where('store_id', $storeId)
                ->where('id_number', $parsedData['id_number'])
                ->first();
        }

        return response()->json([
            'parsed_data' => $parsedData,
            'existing_customer' => $existingCustomer ? $this->formatCustomer($existingCustomer) : null,
        ]);
    }

    /**
     * Look up a customer by their ID number.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'id_number' => 'required|string|max:100',
        ]);

        $storeId = $this->storeContext->getCurrentStoreId();

        $customer = Customer::query()
            ->where('store_id', $storeId)
            ->where('id_number', $request->input('id_number'))
            ->first();

        return response()->json([
            'customer' => $customer ? $this->formatCustomer($customer) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCustomer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'full_name' => $customer->full_name,
            'email' => $customer->email,
            'phone_number' => $customer->phone_number,
            'address' => $customer->address,
            'address2' => $customer->address2,
            'city' => $customer->city,
            'state' => $customer->state,
            'zip' => $customer->zip,
            'id_number' => $customer->id_number,
            'is_active' => $customer->is_active,
        ];
    }
}
