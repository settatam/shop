<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Image;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\WebhookLog;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaperformWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        $storeId = data_get($payload, 'store_id');

        $store = $storeId ? Store::withoutGlobalScopes()->find($storeId) : null;

        if (! $store) {
            return response()->json(['error' => 'Invalid store_id'], 422);
        }

        app(StoreContext::class)->setCurrentStore($store);

        $webhookLog = WebhookLog::create([
            'store_id' => $storeId,
            'platform' => Platform::Paperform,
            'event_type' => 'form_submission',
            'status' => WebhookLog::STATUS_PENDING,
            'payload' => $payload,
            'ip_address' => $request->ip(),
            'headers' => array_filter([
                'content-type' => $request->header('content-type'),
                'x-paperform-signature' => $request->header('x-paperform-signature'),
            ]),
            'signature' => $request->header('x-paperform-signature'),
        ]);

        try {
            $returnedData = $this->extractFormData($payload);

            $email = data_get($returnedData, 'email');

            if (! $email) {
                $webhookLog->markAsFailed('Missing customer email');

                return response()->json(['error' => 'Missing customer email'], 422);
            }

            DB::transaction(function () use ($returnedData, $storeId, $webhookLog) {
                $email = data_get($returnedData, 'email');

                $customer = Customer::where('email', $email)
                    ->where('store_id', $storeId)
                    ->first();

                if (! $customer) {
                    $addressParts = $this->parseAddress(data_get($returnedData, 'address', ''));

                    $customer = Customer::create([
                        'store_id' => $storeId,
                        'first_name' => data_get($returnedData, 'first_name'),
                        'last_name' => data_get($returnedData, 'last_name'),
                        'email' => $email,
                        'is_active' => true,
                        'phone_number' => data_get($returnedData, 'phone'),
                        'address' => $addressParts['address'],
                        'city' => $addressParts['city'],
                        'zip' => $addressParts['zip'],
                    ]);
                }

                $categories = data_get($returnedData, 'customer_categories', []);

                $transaction = Transaction::create([
                    'store_id' => $storeId,
                    'customer_id' => $customer->id,
                    'status' => Transaction::STATUS_PENDING_KIT_REQUEST,
                    'type' => Transaction::TYPE_MAIL_IN,
                    'customer_description' => data_get($returnedData, 'customer_description'),
                    'customer_notes' => data_get($returnedData, 'customer_description'),
                    'customer_amount' => data_get($returnedData, 'customer_amount'),
                    'customer_categories' => is_array($categories) ? implode(', ', $categories) : $categories,
                ]);

                // Store images as polymorphic Image records
                $images = data_get($returnedData, 'images', []);
                foreach ($images as $sortOrder => $image) {
                    $imageUrl = data_get($image, 'url');
                    $transaction->images()->create([
                        'store_id' => $storeId,
                        'url' => $imageUrl,
                        'path' => $imageUrl,
                        'disk' => 'external',
                        'sort_order' => $sortOrder,
                    ]);
                }

                // Store payout preference from the form
                $paymentType = data_get($returnedData, 'payment_type');
                if ($paymentType) {
                    $transaction->update([
                        'payment_method' => $this->normalizePaymentMethod($paymentType),
                    ]);
                }

                $webhookLog->markAsCompleted([
                    'transaction_id' => $transaction->id,
                    'customer_id' => $customer->id,
                ]);
            });

            return response()->json(['status' => 'success'], 200);
        } catch (\Throwable $e) {
            Log::error('Paperform webhook processing failed', [
                'webhook_log_id' => $webhookLog->id,
                'error' => $e->getMessage(),
            ]);

            $webhookLog->markAsFailed($e->getMessage());

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Extract form field data from the Paperform payload into a keyed array.
     *
     * @return array<string, mixed>
     */
    protected function extractFormData(array $payload): array
    {
        $data = data_get($payload, 'data', []);
        $result = [];

        foreach ($data as $field) {
            $key = data_get($field, 'custom_key');
            if ($key) {
                $result[$key] = data_get($field, 'value');
            }
        }

        return $result;
    }

    /**
     * Parse a comma-separated address string into components.
     *
     * @return array{address: string|null, city: string|null, state: string|null, zip: string|null}
     */
    protected function parseAddress(string $address): array
    {
        $parts = array_map('trim', explode(',', $address));

        return [
            'address' => $parts[0] ?? null,
            'city' => $parts[1] ?? null,
            'state' => $parts[2] ?? null,
            'zip' => $parts[3] ?? null,
        ];
    }

    /**
     * Normalize a Paperform payment type string to a Transaction payment method constant.
     */
    protected function normalizePaymentMethod(string $paymentType): string
    {
        $map = [
            'check' => Transaction::PAYMENT_CHECK,
            'paypal' => Transaction::PAYMENT_PAYPAL,
            'venmo' => Transaction::PAYMENT_VENMO,
            'ach' => Transaction::PAYMENT_ACH,
            'wire' => Transaction::PAYMENT_WIRE_TRANSFER,
            'wire_transfer' => Transaction::PAYMENT_WIRE_TRANSFER,
            'cash' => Transaction::PAYMENT_CASH,
            'store_credit' => Transaction::PAYMENT_STORE_CREDIT,
        ];

        return $map[strtolower(trim($paymentType))] ?? $paymentType;
    }
}
