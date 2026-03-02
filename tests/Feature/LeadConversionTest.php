<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Leads\LeadConversionService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadConversionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected LeadConversionService $conversionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->onboarded()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);

        $this->conversionService = app(LeadConversionService::class);
    }

    public function test_can_convert_lead_to_transaction(): void
    {
        $lead = Lead::factory()->offerAccepted()->create([
            'store_id' => $this->store->id,
            'final_offer' => 500.00,
            'user_id' => $this->user->id,
        ]);

        $transaction = $this->conversionService->convertToTransaction($lead);

        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertEquals($this->store->id, $transaction->store_id);
        $this->assertEquals($lead->customer_id, $transaction->customer_id);
        $this->assertEquals(500.00, (float) $transaction->final_offer);
        $this->assertEquals(Transaction::STATUS_PAYMENT_PROCESSED, $transaction->status);

        $lead->refresh();
        $this->assertEquals($transaction->id, $lead->transaction_id);
        $this->assertTrue($lead->isConverted());
    }

    public function test_conversion_copies_items(): void
    {
        $lead = Lead::factory()->offerAccepted()->create([
            'store_id' => $this->store->id,
        ]);

        LeadItem::factory()->create([
            'lead_id' => $lead->id,
            'title' => 'Gold Ring 14K',
            'buy_price' => 200.00,
            'dwt' => 5.5,
            'precious_metal' => 'gold_14k',
        ]);
        LeadItem::factory()->create([
            'lead_id' => $lead->id,
            'title' => 'Silver Bracelet',
            'buy_price' => 50.00,
            'dwt' => 8.0,
            'precious_metal' => 'silver',
        ]);

        $transaction = $this->conversionService->convertToTransaction($lead);

        $this->assertCount(2, $transaction->items);
        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $transaction->id,
            'title' => 'Gold Ring 14K',
            'buy_price' => 200.00,
        ]);
        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $transaction->id,
            'title' => 'Silver Bracelet',
            'buy_price' => 50.00,
        ]);
    }

    public function test_conversion_copies_images(): void
    {
        $lead = Lead::factory()->offerAccepted()->create([
            'store_id' => $this->store->id,
        ]);

        $lead->images()->create([
            'store_id' => $this->store->id,
            'url' => 'https://example.com/image1.jpg',
            'path' => '/images/image1.jpg',
            'alt_text' => 'Lead image',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $transaction = $this->conversionService->convertToTransaction($lead);

        $this->assertCount(1, $transaction->images);
        $this->assertDatabaseHas('images', [
            'imageable_type' => Transaction::class,
            'imageable_id' => $transaction->id,
            'url' => 'https://example.com/image1.jpg',
        ]);
    }

    public function test_conversion_copies_all_lead_fields(): void
    {
        $lead = Lead::factory()->offerAccepted()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'type' => Lead::TYPE_MAIL_IN,
            'source' => Lead::SOURCE_ONLINE,
            'preliminary_offer' => 400.00,
            'final_offer' => 500.00,
            'estimated_value' => 600.00,
            'payment_method' => Lead::PAYMENT_CHECK,
            'bin_location' => 'BIN-A1',
            'customer_notes' => 'Customer note',
            'internal_notes' => 'Internal note',
            'outbound_tracking_number' => '1Z999AA10123456784',
            'outbound_carrier' => 'ups',
        ]);

        $transaction = $this->conversionService->convertToTransaction($lead);

        $this->assertEquals($lead->user_id, $transaction->user_id);
        $this->assertEquals($lead->type, $transaction->type);
        $this->assertEquals($lead->source, $transaction->source);
        $this->assertEquals((float) $lead->preliminary_offer, (float) $transaction->preliminary_offer);
        $this->assertEquals((float) $lead->final_offer, (float) $transaction->final_offer);
        $this->assertEquals($lead->payment_method, $transaction->payment_method);
        $this->assertEquals($lead->bin_location, $transaction->bin_location);
        $this->assertEquals($lead->customer_notes, $transaction->customer_notes);
        $this->assertEquals($lead->internal_notes, $transaction->internal_notes);
        $this->assertEquals($lead->outbound_tracking_number, $transaction->outbound_tracking_number);
        $this->assertEquals($lead->outbound_carrier, $transaction->outbound_carrier);
    }

    public function test_conversion_is_idempotent(): void
    {
        $lead = Lead::factory()->offerAccepted()->create([
            'store_id' => $this->store->id,
        ]);

        $this->conversionService->convertToTransaction($lead);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been converted/');

        $lead->refresh();
        $this->conversionService->convertToTransaction($lead);
    }

    public function test_processing_payment_triggers_conversion(): void
    {
        $lead = Lead::factory()->offerAccepted()->create([
            'store_id' => $this->store->id,
            'final_offer' => 750.00,
        ]);

        $response = $this->post("/leads/{$lead->id}/process-payment", [
            'payment_method' => Lead::PAYMENT_ACH,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $lead->refresh();
        $this->assertEquals(Lead::STATUS_PAYMENT_PROCESSED, $lead->status);
        $this->assertNotNull($lead->transaction_id);
        $this->assertTrue($lead->isConverted());

        $transaction = $lead->transaction;
        $this->assertNotNull($transaction);
        $this->assertEquals(Transaction::STATUS_PAYMENT_PROCESSED, $transaction->status);
        $this->assertEquals(750.00, (float) $transaction->final_offer);
    }

    public function test_conversion_transaction_is_atomic(): void
    {
        $lead = Lead::factory()->offerAccepted()->create([
            'store_id' => $this->store->id,
        ]);
        LeadItem::factory()->count(3)->create(['lead_id' => $lead->id]);

        $transaction = $this->conversionService->convertToTransaction($lead);

        $this->assertCount(3, $transaction->items);

        $lead->refresh();
        $this->assertEquals($transaction->id, $lead->transaction_id);
    }

    public function test_two_different_stores_get_separate_leads(): void
    {
        $otherUser = User::factory()->create();
        $otherStore = Store::factory()->onboarded()->create([
            'user_id' => $otherUser->id,
            'lead_id_prefix' => 'SMJ',
        ]);
        $this->store->update(['lead_id_prefix' => 'BMG']);

        $lead1 = Lead::factory()->create(['store_id' => $this->store->id]);
        $lead2 = Lead::factory()->create(['store_id' => $otherStore->id]);

        $this->assertStringStartsWith('BMG-', $lead1->lead_number);
        $this->assertStringStartsWith('SMJ-', $lead2->lead_number);
        $this->assertNotEquals($lead1->store_id, $lead2->store_id);

        $storeLeads = Lead::where('store_id', $this->store->id)->get();
        $this->assertCount(1, $storeLeads);
        $this->assertEquals($lead1->id, $storeLeads->first()->id);
    }
}
