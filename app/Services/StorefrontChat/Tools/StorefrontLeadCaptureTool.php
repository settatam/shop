<?php

namespace App\Services\StorefrontChat\Tools;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\LeadSource;
use App\Models\StorefrontChatSession;
use App\Services\Chat\Tools\ChatToolInterface;
use App\Services\StoreContext;

class StorefrontLeadCaptureTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'capture_lead';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Save a potential customer\'s contact information when they express interest in a product, want to be contacted, ask for pricing on high-value items, or voluntarily share their contact details. Only call this tool after the customer has provided at least their name and either an email address or phone number. Do not call this tool if the customer has not shared contact information or has declined to share it.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'first_name' => [
                        'type' => 'string',
                        'description' => 'Customer\'s first name',
                    ],
                    'last_name' => [
                        'type' => 'string',
                        'description' => 'Customer\'s last name (if provided)',
                    ],
                    'email' => [
                        'type' => 'string',
                        'description' => 'Customer\'s email address',
                    ],
                    'phone_number' => [
                        'type' => 'string',
                        'description' => 'Customer\'s phone number',
                    ],
                    'interest' => [
                        'type' => 'string',
                        'description' => 'Brief summary of what the customer is interested in (e.g. "14K gold engagement ring, budget around $3000")',
                    ],
                    'session_id' => [
                        'type' => 'string',
                        'description' => 'The current chat session UUID (injected automatically)',
                    ],
                ],
                'required' => ['first_name'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $firstName = $params['first_name'] ?? null;
        $lastName = $params['last_name'] ?? null;
        $email = $params['email'] ?? null;
        $phoneNumber = $params['phone_number'] ?? null;
        $interest = $params['interest'] ?? null;
        $sessionId = $params['session_id'] ?? null;

        if (! $firstName) {
            return ['error' => 'First name is required'];
        }

        if (! $email && ! $phoneNumber) {
            return ['error' => 'Either email or phone number is required'];
        }

        $leadSource = LeadSource::getStorefrontChatSource($storeId);

        // Check for existing customer by email within this store
        if ($email) {
            $existingCustomer = Customer::withoutGlobalScopes()
                ->where('store_id', $storeId)
                ->where('email', $email)
                ->first();

            if ($existingCustomer) {
                $this->linkSession($sessionId, $existingCustomer->id, $storeId);

                return [
                    'success' => true,
                    'is_existing_customer' => true,
                    'customer_name' => $existingCustomer->first_name.' '.$existingCustomer->last_name,
                    'message' => 'This customer is already in our system. Their chat session has been linked.',
                ];
            }
        }

        // Set store context so ActivityLog works (proxy requests have no auth)
        app(StoreContext::class)->setCurrentStoreId($storeId);

        $customer = Customer::withoutGlobalScopes()->create([
            'store_id' => $storeId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone_number' => $phoneNumber,
            'lead_source_id' => $leadSource->id,
            'accepts_marketing' => false,
            'is_active' => true,
            'notify' => false,
        ]);

        $this->linkSession($sessionId, $customer->id, $storeId);

        ActivityLog::log(
            Activity::CUSTOMERS_CHAT_LEAD,
            $customer,
            null,
            [
                'interest' => $interest,
                'session_id' => $sessionId,
                'lead_source' => 'Storefront Chat',
            ],
            "Chat lead captured: {$customer->first_name} {$customer->last_name}"
        );

        return [
            'success' => true,
            'is_existing_customer' => false,
            'customer_name' => $customer->first_name.' '.$customer->last_name,
            'message' => 'Contact information has been saved. The store team will follow up.',
        ];
    }

    protected function linkSession(?string $sessionId, int $customerId, int $storeId): void
    {
        if (! $sessionId) {
            return;
        }

        StorefrontChatSession::withoutGlobalScopes()
            ->where('id', $sessionId)
            ->where('store_id', $storeId)
            ->first()
            ?->linkCustomer($customerId);
    }
}
