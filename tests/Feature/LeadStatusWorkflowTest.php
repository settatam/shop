<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadStatusWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

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
    }

    // --- Kit Request Phase ---

    public function test_can_confirm_kit_request(): void
    {
        $lead = Lead::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/confirm-kit-request");

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_KIT_REQUEST_CONFIRMED, $lead->status);
    }

    public function test_can_reject_kit_request(): void
    {
        $lead = Lead::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/reject-kit-request");

        $response->assertRedirect();
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_KIT_REQUEST_REJECTED, $lead->status);
    }

    public function test_can_hold_kit_request(): void
    {
        $lead = Lead::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/hold-kit-request");

        $response->assertRedirect();
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_KIT_REQUEST_ON_HOLD, $lead->status);
    }

    // --- Kit Shipping Phase ---

    public function test_can_mark_kit_sent(): void
    {
        $lead = Lead::factory()->kitRequestConfirmed()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/mark-kit-sent", [
            'tracking_number' => '1Z999AA10123456784',
            'carrier' => 'ups',
        ]);

        $response->assertRedirect();
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_KIT_SENT, $lead->status);
        $this->assertEquals('1Z999AA10123456784', $lead->outbound_tracking_number);
        $this->assertEquals('ups', $lead->outbound_carrier);
        $this->assertNotNull($lead->kit_sent_at);
    }

    public function test_mark_kit_sent_requires_tracking_number(): void
    {
        $lead = Lead::factory()->kitRequestConfirmed()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/mark-kit-sent", [
            'carrier' => 'fedex',
        ]);

        $response->assertSessionHasErrors('tracking_number');
    }

    public function test_can_mark_kit_delivered(): void
    {
        $lead = Lead::factory()->create([
            'store_id' => $this->store->id,
            'status' => Lead::STATUS_KIT_SENT,
            'outbound_tracking_number' => '1Z999AA10123456784',
            'kit_sent_at' => now()->subDays(3),
        ]);

        $response = $this->post("/leads/{$lead->id}/mark-kit-delivered");

        $response->assertRedirect();
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_KIT_DELIVERED, $lead->status);
        $this->assertNotNull($lead->kit_delivered_at);
    }

    // --- Items Phase ---

    public function test_can_mark_items_received(): void
    {
        $lead = Lead::factory()->create([
            'store_id' => $this->store->id,
            'status' => Lead::STATUS_KIT_DELIVERED,
        ]);

        $response = $this->post("/leads/{$lead->id}/mark-items-received");

        $response->assertRedirect();
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_ITEMS_RECEIVED, $lead->status);
        $this->assertNotNull($lead->items_received_at);
    }

    public function test_can_mark_items_reviewed(): void
    {
        $lead = Lead::factory()->itemsReceived()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/mark-items-reviewed");

        $response->assertRedirect();
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_ITEMS_REVIEWED, $lead->status);
        $this->assertNotNull($lead->items_reviewed_at);
    }

    // --- Offer Phase ---

    public function test_can_submit_offer(): void
    {
        $lead = Lead::factory()->itemsReviewed()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/offer", [
            'offer' => 350.00,
            'notes' => 'Fair market value offer',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_OFFER_GIVEN, $lead->status);
        $this->assertEquals(350.00, (float) $lead->final_offer);
        $this->assertNotNull($lead->offer_given_at);
    }

    public function test_cannot_submit_offer_on_pending_lead(): void
    {
        $lead = Lead::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/offer", [
            'offer' => 350.00,
        ]);

        $response->assertSessionHas('error');
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_PENDING_KIT_REQUEST, $lead->status);
    }

    public function test_can_accept_offer(): void
    {
        $lead = Lead::factory()->offerGiven()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/accept");

        $response->assertRedirect();
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_OFFER_ACCEPTED, $lead->status);
        $this->assertNotNull($lead->offer_accepted_at);
    }

    public function test_cannot_accept_offer_when_not_in_offer_given_status(): void
    {
        $lead = Lead::factory()->itemsReceived()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/accept");

        $response->assertSessionHas('error');
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_ITEMS_RECEIVED, $lead->status);
    }

    public function test_can_decline_offer(): void
    {
        $lead = Lead::factory()->offerGiven()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/decline", [
            'reason' => 'Too low',
        ]);

        $response->assertRedirect();
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_CUSTOMER_DECLINED_OFFER, $lead->status);
    }

    // --- Payment Phase ---

    public function test_can_process_payment(): void
    {
        $lead = Lead::factory()->offerAccepted()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/process-payment", [
            'payment_method' => Lead::PAYMENT_CASH,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_PAYMENT_PROCESSED, $lead->status);
        $this->assertEquals(Lead::PAYMENT_CASH, $lead->payment_method);
        $this->assertNotNull($lead->payment_processed_at);
    }

    public function test_cannot_process_payment_without_accepted_offer(): void
    {
        $lead = Lead::factory()->offerGiven()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/process-payment", [
            'payment_method' => Lead::PAYMENT_CASH,
        ]);

        $response->assertSessionHas('error');
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_OFFER_GIVEN, $lead->status);
    }

    // --- Return Phase ---

    public function test_can_mark_items_returned(): void
    {
        $lead = Lead::factory()->customerDeclined()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/mark-items-returned");

        $response->assertRedirect();
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_ITEMS_RETURNED, $lead->status);
    }

    // --- Generic Status Change ---

    public function test_can_change_status(): void
    {
        $lead = Lead::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/change-status", [
            'status' => Lead::STATUS_KIT_REQUEST_CONFIRMED,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_KIT_REQUEST_CONFIRMED, $lead->status);
    }

    public function test_cannot_change_to_invalid_status(): void
    {
        $lead = Lead::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/change-status", [
            'status' => 'nonexistent_status',
        ]);

        $response->assertSessionHasErrors('status');
    }

    public function test_cannot_change_status_of_payment_processed_lead(): void
    {
        $lead = Lead::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);

        $response = $this->post("/leads/{$lead->id}/change-status", [
            'status' => Lead::STATUS_PENDING_KIT_REQUEST,
        ]);

        $response->assertSessionHas('error');
        $lead->refresh();
        $this->assertEquals(Lead::STATUS_PAYMENT_PROCESSED, $lead->status);
    }

    // --- Model Status Helpers ---

    public function test_model_status_helpers(): void
    {
        $lead = Lead::factory()->create([
            'store_id' => $this->store->id,
            'status' => Lead::STATUS_PENDING_KIT_REQUEST,
        ]);

        $this->assertTrue($lead->isPendingKitRequest());
        $this->assertFalse($lead->isOfferGiven());
        $this->assertFalse($lead->isPaymentProcessed());
        $this->assertFalse($lead->isConverted());
    }

    public function test_can_submit_offer_check(): void
    {
        $lead = Lead::factory()->itemsReviewed()->create(['store_id' => $this->store->id]);
        $this->assertTrue($lead->canSubmitOffer());

        $pending = Lead::factory()->pending()->create(['store_id' => $this->store->id]);
        $this->assertFalse($pending->canSubmitOffer());
    }

    public function test_can_be_cancelled_check(): void
    {
        $pending = Lead::factory()->pending()->create(['store_id' => $this->store->id]);
        $this->assertTrue($pending->canBeCancelled());

        $payment = Lead::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $this->assertFalse($payment->canBeCancelled());
    }
}
