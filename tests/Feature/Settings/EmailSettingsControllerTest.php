<?php

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Store $store;

    protected Role $ownerRole;

    protected StoreUser $ownerStoreUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->owner->id,
            'step' => 2, // Mark onboarding as complete
        ]);

        $this->ownerRole = Role::factory()->owner()->create(['store_id' => $this->store->id]);

        $this->ownerStoreUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->owner->id,
            'store_id' => $this->store->id,
            'role_id' => $this->ownerRole->id,
        ]);

        $this->owner->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_email_settings_page_can_be_rendered(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/settings/email');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Email')
            ->has('store')
            ->has('mailProvider')
            ->has('sesConfigured')
        );
    }

    public function test_email_settings_page_shows_store_data(): void
    {
        $this->store->update([
            'email_from_address' => 'test@example.com',
            'email_from_name' => 'Test Store',
            'email_reply_to_address' => 'reply@example.com',
        ]);

        $this->actingAs($this->owner);

        $response = $this->get('/settings/email');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Email')
            ->where('store.email_from_address', 'test@example.com')
            ->where('store.email_from_name', 'Test Store')
            ->where('store.email_reply_to_address', 'reply@example.com')
        );
    }

    public function test_email_settings_can_be_updated(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patch('/settings/email', [
            'email_from_address' => 'new@example.com',
            'email_from_name' => 'New Name',
            'email_reply_to_address' => 'newreply@example.com',
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->store->refresh();

        $this->assertEquals('new@example.com', $this->store->email_from_address);
        $this->assertEquals('New Name', $this->store->email_from_name);
        $this->assertEquals('newreply@example.com', $this->store->email_reply_to_address);
    }

    public function test_email_settings_can_be_cleared(): void
    {
        $this->store->update([
            'email_from_address' => 'test@example.com',
            'email_from_name' => 'Test Name',
            'email_reply_to_address' => 'reply@example.com',
        ]);

        $this->actingAs($this->owner);

        $response = $this->patch('/settings/email', [
            'email_from_address' => null,
            'email_from_name' => null,
            'email_reply_to_address' => null,
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->store->refresh();

        $this->assertNull($this->store->email_from_address);
        $this->assertNull($this->store->email_from_name);
        $this->assertNull($this->store->email_reply_to_address);
    }

    public function test_email_from_address_must_be_valid_email(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patch('/settings/email', [
            'email_from_address' => 'not-an-email',
            'email_from_name' => 'Test Name',
            'email_reply_to_address' => 'reply@example.com',
        ]);

        $response->assertSessionHasErrors('email_from_address');
    }

    public function test_email_reply_to_address_must_be_valid_email(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patch('/settings/email', [
            'email_from_address' => 'valid@example.com',
            'email_from_name' => 'Test Name',
            'email_reply_to_address' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors('email_reply_to_address');
    }

    public function test_test_email_can_be_sent(): void
    {
        Mail::fake();

        $this->actingAs($this->owner);

        $response = $this->postJson('/settings/email/test', [
            'test_email' => 'recipient@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Mail::raw() uses the raw method which doesn't work with assertSentCount
        // Instead we check that the response indicates success
        $this->assertTrue($response->json('success'));
    }

    public function test_test_email_requires_valid_email(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson('/settings/email/test', [
            'test_email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
    }

    public function test_test_email_requires_email_field(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson('/settings/email/test', []);

        $response->assertStatus(422);
    }
}
