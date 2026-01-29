<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Image;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookPaperformTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);
    }

    private function paperformPayload(array $fields = [], ?int $storeId = null): array
    {
        $defaults = [
            ['custom_key' => 'first_name', 'value' => 'Jane'],
            ['custom_key' => 'last_name', 'value' => 'Doe'],
            ['custom_key' => 'email', 'value' => 'jane@example.com'],
            ['custom_key' => 'phone', 'value' => '555-1234'],
            ['custom_key' => 'address', 'value' => '123 Main St, Springfield, IL, 62701'],
            ['custom_key' => 'customer_description', 'value' => 'Gold ring and necklace'],
            ['custom_key' => 'customer_amount', 'value' => 500],
            ['custom_key' => 'customer_categories', 'value' => ['Gold', 'Jewelry']],
            ['custom_key' => 'payment_type', 'value' => 'check'],
            ['custom_key' => 'images', 'value' => [
                ['url' => 'https://example.com/photo1.jpg'],
                ['url' => 'https://example.com/photo2.jpg'],
            ]],
        ];

        $data = array_merge($defaults, $fields);

        return [
            'store_id' => $storeId ?? $this->store->id,
            'data' => $data,
        ];
    }

    public function test_successful_submission_creates_customer_and_transaction(): void
    {
        $response = $this->postJson('/api/webhooks/paperform', $this->paperformPayload());

        $response->assertOk();
        $response->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('customers', [
            'store_id' => $this->store->id,
            'email' => 'jane@example.com',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        $this->assertDatabaseHas('transactions', [
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_PENDING_KIT_REQUEST,
            'type' => Transaction::TYPE_MAIL_IN,
            'customer_description' => 'Gold ring and necklace',
            'payment_method' => 'check',
        ]);
    }

    public function test_uses_existing_customer_when_email_matches(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'email' => 'jane@example.com',
        ]);

        $this->postJson('/api/webhooks/paperform', $this->paperformPayload());

        $this->assertEquals(1, Customer::where('email', 'jane@example.com')->where('store_id', $this->store->id)->count());

        $transaction = Transaction::where('store_id', $this->store->id)->first();
        $this->assertEquals($customer->id, $transaction->customer_id);
    }

    public function test_creates_images_from_submission(): void
    {
        $this->postJson('/api/webhooks/paperform', $this->paperformPayload());

        $transaction = Transaction::where('store_id', $this->store->id)->first();
        $images = Image::where('imageable_type', Transaction::class)
            ->where('imageable_id', $transaction->id)
            ->get();

        $this->assertCount(2, $images);
        $this->assertEquals('https://example.com/photo1.jpg', $images[0]->url);
        $this->assertEquals('https://example.com/photo2.jpg', $images[1]->url);
    }

    public function test_stores_customer_categories_as_comma_separated_string(): void
    {
        $this->postJson('/api/webhooks/paperform', $this->paperformPayload());

        $transaction = Transaction::where('store_id', $this->store->id)->first();
        $this->assertEquals('Gold, Jewelry', $transaction->customer_categories);
    }

    public function test_logs_webhook(): void
    {
        $this->postJson('/api/webhooks/paperform', $this->paperformPayload());

        $this->assertDatabaseHas('webhook_logs', [
            'store_id' => $this->store->id,
            'event_type' => 'form_submission',
            'status' => WebhookLog::STATUS_COMPLETED,
        ]);
    }

    public function test_rejects_invalid_store_id(): void
    {
        $response = $this->postJson('/api/webhooks/paperform', $this->paperformPayload(storeId: 99999));

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Invalid store_id']);
    }

    public function test_rejects_missing_store_id(): void
    {
        $payload = $this->paperformPayload();
        unset($payload['store_id']);

        $response = $this->postJson('/api/webhooks/paperform', $payload);

        $response->assertStatus(422);
    }

    public function test_rejects_missing_email(): void
    {
        $payload = [
            'store_id' => $this->store->id,
            'data' => [
                ['custom_key' => 'first_name', 'value' => 'Jane'],
                ['custom_key' => 'last_name', 'value' => 'Doe'],
            ],
        ];

        $response = $this->postJson('/api/webhooks/paperform', $payload);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Missing customer email']);

        $this->assertDatabaseHas('webhook_logs', [
            'store_id' => $this->store->id,
            'status' => WebhookLog::STATUS_FAILED,
        ]);
    }

    public function test_normalizes_payment_method(): void
    {
        $payload = $this->paperformPayload([
            ['custom_key' => 'payment_type', 'value' => 'PayPal'],
        ]);

        $this->postJson('/api/webhooks/paperform', $payload);

        $transaction = Transaction::where('store_id', $this->store->id)->first();
        $this->assertEquals('paypal', $transaction->payment_method);
    }

    public function test_handles_missing_optional_fields_gracefully(): void
    {
        $payload = [
            'store_id' => $this->store->id,
            'data' => [
                ['custom_key' => 'email', 'value' => 'minimal@example.com'],
            ],
        ];

        $response = $this->postJson('/api/webhooks/paperform', $payload);

        $response->assertOk();

        $this->assertDatabaseHas('transactions', [
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_PENDING_KIT_REQUEST,
        ]);
    }

    public function test_parses_address_into_customer_fields(): void
    {
        $this->postJson('/api/webhooks/paperform', $this->paperformPayload());

        $customer = Customer::where('email', 'jane@example.com')
            ->where('store_id', $this->store->id)
            ->first();

        $this->assertEquals('123 Main St', $customer->address);
        $this->assertEquals('Springfield', $customer->city);
        $this->assertEquals('62701', $customer->zip);
    }
}
