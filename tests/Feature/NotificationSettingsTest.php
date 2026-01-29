<?php

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_view_notification_dashboard(): void
    {
        $this->actingAs($this->user);

        $response = $this->get('/settings/notifications');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('settings/notifications/Index')
                ->has('stats')
                ->has('channelTypes')
            );
    }

    public function test_can_view_templates_list(): void
    {
        $this->actingAs($this->user);

        NotificationTemplate::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->get('/settings/notifications/templates');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('settings/notifications/Templates')
                ->has('templates', 3)
                ->has('channelTypes')
                ->has('categories')
            );
    }

    public function test_can_view_template_create_page(): void
    {
        $this->actingAs($this->user);

        $response = $this->get('/settings/notifications/templates/create');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('settings/notifications/TemplateEditor')
                ->where('template', null)
                ->has('channelTypes')
                ->has('categories')
                ->has('sampleData')
            );
    }

    public function test_can_view_template_edit_page(): void
    {
        $this->actingAs($this->user);

        $template = NotificationTemplate::factory()->create(['store_id' => $this->store->id]);

        $response = $this->get("/settings/notifications/templates/{$template->id}/edit");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('settings/notifications/TemplateEditor')
                ->where('template.id', $template->id)
            );
    }

    public function test_cannot_edit_other_store_template(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $template = NotificationTemplate::factory()->create(['store_id' => $otherStore->id]);

        $response = $this->get("/settings/notifications/templates/{$template->id}/edit");

        $response->assertStatus(404);
    }

    public function test_can_view_subscriptions_list(): void
    {
        $this->actingAs($this->user);

        $template = NotificationTemplate::factory()->create(['store_id' => $this->store->id]);
        NotificationSubscription::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'notification_template_id' => $template->id,
        ]);

        $response = $this->get('/settings/notifications/subscriptions');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('settings/notifications/Subscriptions')
                ->has('subscriptions', 2)
                ->has('templates')
                ->has('activities')
            );
    }

    public function test_can_view_channels_page(): void
    {
        $this->actingAs($this->user);

        NotificationChannel::factory()->create([
            'store_id' => $this->store->id,
            'type' => 'email',
        ]);

        $response = $this->get('/settings/notifications/channels');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('settings/notifications/Channels')
                ->has('channels', 1)
                ->has('channelTypes')
            );
    }

    public function test_can_save_channel_configuration(): void
    {
        $this->actingAs($this->user);

        $response = $this->post('/settings/notifications/channels/save', [
            'type' => 'email',
            'settings' => [
                'from_name' => 'Test Store',
                'from_email' => 'test@example.com',
                'reply_to' => 'support@example.com',
            ],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('notification_channels', [
            'store_id' => $this->store->id,
            'type' => 'email',
            'is_enabled' => true,
        ]);
    }

    public function test_can_toggle_channel(): void
    {
        $this->actingAs($this->user);

        $channel = NotificationChannel::factory()->create([
            'store_id' => $this->store->id,
            'type' => 'email',
            'is_enabled' => true,
        ]);

        $response = $this->post('/settings/notifications/channels/toggle', [
            'type' => 'email',
            'is_enabled' => false,
        ]);

        $response->assertRedirect();

        $channel->refresh();
        $this->assertFalse($channel->is_enabled);
    }

    public function test_can_view_logs_page(): void
    {
        $this->actingAs($this->user);

        NotificationLog::factory()->count(5)->create(['store_id' => $this->store->id]);

        $response = $this->get('/settings/notifications/logs');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('settings/notifications/Logs')
                ->has('channelTypes')
                ->has('statusTypes')
                ->has('filters')
                ->has('pagination')
            );
    }

    public function test_can_filter_logs_by_channel(): void
    {
        $this->actingAs($this->user);

        NotificationLog::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'channel' => 'email',
        ]);
        NotificationLog::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'channel' => 'sms',
        ]);

        $response = $this->get('/settings/notifications/logs?channel=email');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('settings/notifications/Logs')
                ->where('filters.channel', 'email')
            );
    }

    public function test_can_filter_logs_by_status(): void
    {
        $this->actingAs($this->user);

        NotificationLog::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'status' => NotificationLog::STATUS_SENT,
        ]);
        NotificationLog::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'status' => NotificationLog::STATUS_FAILED,
        ]);

        $response = $this->get('/settings/notifications/logs?status=sent');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('settings/notifications/Logs')
                ->where('filters.status', 'sent')
            );
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get('/settings/notifications');

        $response->assertRedirect('/login');
    }

    public function test_requires_store_context(): void
    {
        // Clear store context from setUp
        app(StoreContext::class)->clear();

        // Create a user without a store
        $userWithoutStore = User::factory()->create();

        $this->actingAs($userWithoutStore);

        $response = $this->get('/settings/notifications');

        // Should redirect because user has no store context
        $response->assertRedirect();
    }

    public function test_dashboard_shows_correct_stats(): void
    {
        $this->actingAs($this->user);

        NotificationTemplate::factory()->count(5)->create(['store_id' => $this->store->id]);

        $template = NotificationTemplate::factory()->create(['store_id' => $this->store->id]);
        NotificationSubscription::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'notification_template_id' => $template->id,
        ]);

        NotificationLog::factory()->count(10)->create([
            'store_id' => $this->store->id,
            'created_at' => now(),
        ]);

        $response = $this->get('/settings/notifications');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('settings/notifications/Index')
                ->where('stats.templates', 6)
                ->where('stats.subscriptions', 3)
                ->where('stats.sent_today', 10)
            );
    }
}
