<?php

namespace Tests\Feature\Settings;

use App\Models\ChannelConfiguration;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChannelIntegrationTest extends TestCase
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
            'step' => 2,
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

    public function test_integrations_page_can_be_rendered(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/settings/integrations');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Integrations')
            ->has('configurations')
            ->has('availableChannels')
            ->has('webhookBaseUrl')
        );
    }

    public function test_integrations_page_shows_existing_configurations(): void
    {
        ChannelConfiguration::factory()->whatsapp()->create([
            'store_id' => $this->store->id,
        ]);

        $this->actingAs($this->owner);

        $response = $this->get('/settings/integrations');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Integrations')
            ->has('configurations', 1)
            ->where('configurations.0.channel', 'whatsapp')
            ->where('configurations.0.has_credentials', true)
        );
    }

    public function test_save_whatsapp_credentials_creates_configuration(): void
    {
        $this->actingAs($this->owner);

        $response = $this->post('/settings/integrations', [
            'channel' => 'whatsapp',
            'credentials' => [
                'phone_number_id' => '123456789012345',
                'access_token' => 'EAABsbCS1IBLK...',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('channel_configurations', [
            'store_id' => $this->store->id,
            'channel' => 'whatsapp',
        ]);

        $config = ChannelConfiguration::where('store_id', $this->store->id)
            ->where('channel', 'whatsapp')
            ->first();

        $this->assertEquals('123456789012345', $config->credentials['phone_number_id']);
        $this->assertEquals('EAABsbCS1IBLK...', $config->credentials['access_token']);
    }

    public function test_save_slack_credentials_creates_configuration(): void
    {
        $this->actingAs($this->owner);

        $response = $this->post('/settings/integrations', [
            'channel' => 'slack',
            'credentials' => [
                'bot_token' => 'xoxb-test-token-123',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('channel_configurations', [
            'store_id' => $this->store->id,
            'channel' => 'slack',
        ]);

        $config = ChannelConfiguration::where('store_id', $this->store->id)
            ->where('channel', 'slack')
            ->first();

        $this->assertEquals('xoxb-test-token-123', $config->credentials['bot_token']);
    }

    public function test_save_updates_existing_configuration(): void
    {
        ChannelConfiguration::factory()->whatsapp()->create([
            'store_id' => $this->store->id,
            'credentials' => [
                'phone_number_id' => 'old_id',
                'access_token' => 'old_token',
            ],
        ]);

        $this->actingAs($this->owner);

        $response = $this->post('/settings/integrations', [
            'channel' => 'whatsapp',
            'credentials' => [
                'phone_number_id' => 'new_phone_id',
                'access_token' => 'new_access_token',
            ],
        ]);

        $response->assertRedirect();

        $configs = ChannelConfiguration::where('store_id', $this->store->id)
            ->where('channel', 'whatsapp')
            ->get();

        $this->assertCount(1, $configs);
        $this->assertEquals('new_phone_id', $configs->first()->credentials['phone_number_id']);
    }

    public function test_whatsapp_validation_requires_phone_number_id(): void
    {
        $this->actingAs($this->owner);

        $response = $this->post('/settings/integrations', [
            'channel' => 'whatsapp',
            'credentials' => [
                'access_token' => 'some-token',
            ],
        ]);

        $response->assertSessionHasErrors('credentials.phone_number_id');
    }

    public function test_whatsapp_validation_requires_access_token(): void
    {
        $this->actingAs($this->owner);

        $response = $this->post('/settings/integrations', [
            'channel' => 'whatsapp',
            'credentials' => [
                'phone_number_id' => '123456',
            ],
        ]);

        $response->assertSessionHasErrors('credentials.access_token');
    }

    public function test_slack_validation_requires_bot_token(): void
    {
        $this->actingAs($this->owner);

        $response = $this->post('/settings/integrations', [
            'channel' => 'slack',
            'credentials' => [],
        ]);

        $response->assertSessionHasErrors('credentials.bot_token');
    }

    public function test_save_requires_valid_channel(): void
    {
        $this->actingAs($this->owner);

        $response = $this->post('/settings/integrations', [
            'channel' => 'invalid_channel',
            'credentials' => [],
        ]);

        $response->assertSessionHasErrors('channel');
    }

    public function test_toggle_channel_active_status(): void
    {
        $config = ChannelConfiguration::factory()->whatsapp()->create([
            'store_id' => $this->store->id,
            'is_active' => false,
        ]);

        $this->actingAs($this->owner);

        $response = $this->post('/settings/integrations/toggle', [
            'channel' => 'whatsapp',
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertTrue($config->fresh()->is_active);
    }

    public function test_toggle_channel_deactivates(): void
    {
        $config = ChannelConfiguration::factory()->whatsapp()->active()->create([
            'store_id' => $this->store->id,
        ]);

        $this->actingAs($this->owner);

        $response = $this->post('/settings/integrations/toggle', [
            'channel' => 'whatsapp',
            'is_active' => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse($config->fresh()->is_active);
    }

    public function test_delete_configuration(): void
    {
        $config = ChannelConfiguration::factory()->whatsapp()->create([
            'store_id' => $this->store->id,
        ]);

        $this->actingAs($this->owner);

        $response = $this->delete("/settings/integrations/{$config->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('channel_configurations', ['id' => $config->id]);
    }

    public function test_credentials_are_stored_encrypted(): void
    {
        $this->actingAs($this->owner);

        $this->post('/settings/integrations', [
            'channel' => 'whatsapp',
            'credentials' => [
                'phone_number_id' => '123456789',
                'access_token' => 'secret_token_value',
            ],
        ]);

        $config = ChannelConfiguration::where('store_id', $this->store->id)
            ->where('channel', 'whatsapp')
            ->first();

        // The raw database value should not contain the plaintext credentials
        $rawCredentials = $config->getRawOriginal('credentials');
        $this->assertStringNotContainsString('secret_token_value', $rawCredentials);

        // But the model accessor should decrypt it
        $this->assertEquals('secret_token_value', $config->credentials['access_token']);
    }

    public function test_cannot_access_other_stores_configurations(): void
    {
        $otherStore = Store::factory()->create();
        $otherConfig = ChannelConfiguration::factory()->whatsapp()->create([
            'store_id' => $otherStore->id,
        ]);

        $this->actingAs($this->owner);

        $response = $this->get('/settings/integrations');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Integrations')
            ->has('configurations', 0)
        );
    }

    public function test_cannot_toggle_other_stores_configuration(): void
    {
        $otherStore = Store::factory()->create();
        ChannelConfiguration::factory()->whatsapp()->create([
            'store_id' => $otherStore->id,
        ]);

        $this->actingAs($this->owner);

        $response = $this->post('/settings/integrations/toggle', [
            'channel' => 'whatsapp',
            'is_active' => true,
        ]);

        $response->assertStatus(404);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get('/settings/integrations');

        $response->assertRedirect('/login');
    }
}
