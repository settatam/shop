<?php

namespace Tests\Feature\StorefrontChat;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Store;
use App\Models\StorefrontChatSession;
use App\Models\StoreMarketplace;
use App\Services\StorefrontChat\Tools\StorefrontLeadCaptureTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontLeadCaptureToolTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected StorefrontLeadCaptureTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'status' => 'active',
        ]);
        $this->tool = new StorefrontLeadCaptureTool;
    }

    public function test_captures_lead_with_email_and_name(): void
    {
        $result = $this->tool->execute([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'interest' => '14K gold engagement ring',
        ], $this->store->id);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['is_existing_customer']);

        $this->assertDatabaseHas('customers', [
            'store_id' => $this->store->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);
    }

    public function test_captures_lead_with_phone_and_name(): void
    {
        $result = $this->tool->execute([
            'first_name' => 'John',
            'phone_number' => '555-123-4567',
        ], $this->store->id);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['is_existing_customer']);

        $this->assertDatabaseHas('customers', [
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'phone_number' => '555-123-4567',
        ]);
    }

    public function test_requires_first_name(): void
    {
        $result = $this->tool->execute([
            'email' => 'test@example.com',
        ], $this->store->id);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('First name', $result['error']);
    }

    public function test_requires_email_or_phone(): void
    {
        $result = $this->tool->execute([
            'first_name' => 'Jane',
        ], $this->store->id);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('email or phone', $result['error']);
    }

    public function test_deduplicates_by_email_within_same_store(): void
    {
        Customer::withoutGlobalScopes()->create([
            'store_id' => $this->store->id,
            'first_name' => 'Existing',
            'last_name' => 'Customer',
            'email' => 'existing@example.com',
            'is_active' => true,
        ]);

        $result = $this->tool->execute([
            'first_name' => 'Existing',
            'email' => 'existing@example.com',
        ], $this->store->id);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['is_existing_customer']);

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_links_session_to_customer(): void
    {
        $session = StorefrontChatSession::withoutGlobalScopes()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'visitor_id' => 'visitor-abc',
            'expires_at' => now()->addMinutes(30),
        ]);

        $result = $this->tool->execute([
            'first_name' => 'Sarah',
            'email' => 'sarah@example.com',
            'session_id' => $session->id,
        ], $this->store->id);

        $this->assertTrue($result['success']);

        $session->refresh();
        $this->assertNotNull($session->customer_id);
    }

    public function test_creates_storefront_chat_lead_source_if_missing(): void
    {
        $this->assertDatabaseMissing('lead_sources', [
            'store_id' => $this->store->id,
            'slug' => 'storefront-chat',
        ]);

        $this->tool->execute([
            'first_name' => 'Test',
            'email' => 'test@example.com',
        ], $this->store->id);

        $this->assertDatabaseHas('lead_sources', [
            'store_id' => $this->store->id,
            'slug' => 'storefront-chat',
            'name' => 'Storefront Chat',
        ]);

        $customer = Customer::withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->where('email', 'test@example.com')
            ->first();

        $this->assertNotNull($customer->lead_source_id);
    }

    public function test_logs_chat_lead_activity(): void
    {
        $this->tool->execute([
            'first_name' => 'Lead',
            'last_name' => 'Person',
            'email' => 'lead@example.com',
            'interest' => 'Diamond necklace around $2000',
        ], $this->store->id);

        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $this->store->id,
            'activity_slug' => 'customers.chat_lead',
        ]);

        $log = ActivityLog::where('activity_slug', 'customers.chat_lead')->first();
        $this->assertEquals('Diamond necklace around $2000', $log->properties['interest']);
    }

    public function test_does_not_deduplicate_across_stores(): void
    {
        $otherStore = Store::factory()->create();

        Customer::withoutGlobalScopes()->create([
            'store_id' => $otherStore->id,
            'first_name' => 'Cross',
            'last_name' => 'Store',
            'email' => 'cross@example.com',
            'is_active' => true,
        ]);

        $result = $this->tool->execute([
            'first_name' => 'Cross',
            'email' => 'cross@example.com',
        ], $this->store->id);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['is_existing_customer']);

        $this->assertDatabaseCount('customers', 2);
    }

    public function test_handles_missing_session_gracefully(): void
    {
        $result = $this->tool->execute([
            'first_name' => 'NoSession',
            'email' => 'nosession@example.com',
        ], $this->store->id);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['is_existing_customer']);
    }
}
