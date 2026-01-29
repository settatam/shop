<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Notifications\NotificationManager;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);

        // Create store user
        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_render_twig_template(): void
    {
        $template = NotificationTemplate::create([
            'store_id' => $this->store->id,
            'name' => 'Test Template',
            'slug' => 'test-template',
            'channel' => NotificationChannel::TYPE_EMAIL,
            'subject' => 'Order #{{ order.number }}',
            'content' => '<p>Hello {{ customer.name }}, your order total is ${{ order.total|money }}</p>',
        ]);

        $data = [
            'order' => ['number' => '12345', 'total' => 99.99],
            'customer' => ['name' => 'John'],
        ];

        $rendered = $template->render($data);
        $subject = $template->renderSubject($data);

        $this->assertStringContainsString('John', $rendered);
        $this->assertStringContainsString('99.99', $rendered);
        $this->assertEquals('Order #12345', $subject);
    }

    public function test_can_create_notification_template_via_api(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/notification-templates', [
            'name' => 'Order Confirmation',
            'slug' => 'order-confirmation',
            'channel' => 'email',
            'subject' => 'Your order {{ order.number }}',
            'content' => '<p>Thank you for your order!</p>',
            'category' => 'orders',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Order Confirmation',
                'slug' => 'order-confirmation',
            ]);

        $this->assertDatabaseHas('notification_templates', [
            'store_id' => $this->store->id,
            'slug' => 'order-confirmation',
        ]);
    }

    public function test_can_create_notification_subscription(): void
    {
        Passport::actingAs($this->user);

        $template = NotificationTemplate::create([
            'store_id' => $this->store->id,
            'name' => 'Product Created',
            'slug' => 'product-created',
            'channel' => NotificationChannel::TYPE_EMAIL,
            'subject' => 'New Product',
            'content' => '<p>A new product was created</p>',
        ]);

        $response = $this->postJson('/api/v1/notifications', [
            'notification_template_id' => $template->id,
            'activity' => Activity::PRODUCTS_CREATE,
            'name' => 'Product Creation Alert',
            'recipients' => [['type' => 'owner']],
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'activity' => 'products.create',
            ]);
    }

    public function test_subscription_conditions_are_evaluated(): void
    {
        $template = NotificationTemplate::create([
            'store_id' => $this->store->id,
            'name' => 'Condition Test',
            'slug' => 'condition-test',
            'channel' => NotificationChannel::TYPE_EMAIL,
            'subject' => 'Test',
            'content' => 'Test',
        ]);

        $subscription = NotificationSubscription::create([
            'store_id' => $this->store->id,
            'notification_template_id' => $template->id,
            'activity' => 'orders.create',
            'conditions' => [
                ['field' => 'order.total', 'operator' => '>=', 'value' => 100],
            ],
        ]);

        // Order with total >= 100 should pass
        $this->assertTrue($subscription->conditionsMet([
            'order' => ['total' => 150],
        ]));

        // Order with total < 100 should fail
        $this->assertFalse($subscription->conditionsMet([
            'order' => ['total' => 50],
        ]));
    }

    public function test_subscription_can_get_recipient_emails(): void
    {
        $template = NotificationTemplate::create([
            'store_id' => $this->store->id,
            'name' => 'Recipient Test',
            'slug' => 'recipient-test',
            'channel' => NotificationChannel::TYPE_EMAIL,
            'subject' => 'Test',
            'content' => 'Test',
        ]);

        $subscription = NotificationSubscription::create([
            'store_id' => $this->store->id,
            'notification_template_id' => $template->id,
            'activity' => 'orders.create',
            'recipients' => [
                ['type' => 'customer'],
                ['type' => 'custom', 'value' => 'admin@example.com'],
            ],
        ]);

        $data = [
            'store' => $this->store->toArray(),
            'customer' => ['email' => 'customer@example.com'],
        ];

        $emails = $subscription->getRecipientEmails($data);

        $this->assertContains('customer@example.com', $emails);
        $this->assertContains('admin@example.com', $emails);
    }

    public function test_notification_manager_triggers_for_activity(): void
    {
        Mail::fake();

        $template = NotificationTemplate::create([
            'store_id' => $this->store->id,
            'name' => 'Test Notification',
            'slug' => 'test-notification',
            'channel' => NotificationChannel::TYPE_EMAIL,
            'subject' => 'Test Subject',
            'content' => '<p>Test content</p>',
            'is_enabled' => true,
        ]);

        NotificationSubscription::create([
            'store_id' => $this->store->id,
            'notification_template_id' => $template->id,
            'activity' => Activity::PRODUCTS_CREATE,
            'recipients' => [
                ['type' => 'custom', 'value' => 'test@example.com'],
            ],
            'is_enabled' => true,
        ]);

        $this->store->load('owner');
        $manager = new NotificationManager($this->store);
        $logs = $manager->trigger(Activity::PRODUCTS_CREATE, []);

        // Mail was sent and a log was created
        $this->assertCount(1, $logs);
        $this->assertEquals('test@example.com', $logs->first()->recipient);
        $this->assertDatabaseHas('notification_logs', [
            'store_id' => $this->store->id,
            'recipient' => 'test@example.com',
            'channel' => 'email',
        ]);
    }

    public function test_can_preview_template(): void
    {
        Passport::actingAs($this->user);

        $template = NotificationTemplate::create([
            'store_id' => $this->store->id,
            'name' => 'Preview Test',
            'slug' => 'preview-test',
            'channel' => NotificationChannel::TYPE_EMAIL,
            'subject' => 'Hello {{ customer.name }}',
            'content' => '<p>Your order is {{ order.number }}</p>',
        ]);

        $response = $this->postJson("/api/v1/notification-templates/{$template->id}/preview", [
            'data' => [
                'customer' => ['name' => 'Preview User'],
                'order' => ['number' => 'PREV-123'],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'subject' => 'Hello Preview User',
            ]);

        $this->assertStringContainsString('PREV-123', $response->json('content'));
    }

    public function test_can_create_default_templates(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/notification-templates/create-defaults');

        $response->assertStatus(200);

        // Check some default templates were created
        $this->assertDatabaseHas('notification_templates', [
            'store_id' => $this->store->id,
            'slug' => 'order-created',
        ]);

        $this->assertDatabaseHas('notification_templates', [
            'store_id' => $this->store->id,
            'slug' => 'low-stock-alert',
        ]);
    }

    public function test_can_get_available_activities(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/api/v1/notifications/activities');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'activities',
                'grouped',
                'categories',
            ]);

        $activities = $response->json('activities');
        $this->assertArrayHasKey('products.create', $activities);
        $this->assertArrayHasKey('orders.create', $activities);
    }

    public function test_notification_log_tracks_sent_notifications(): void
    {
        $log = NotificationLog::create([
            'store_id' => $this->store->id,
            'channel' => NotificationChannel::TYPE_EMAIL,
            'recipient' => 'test@example.com',
            'content' => 'Test content',
            'status' => NotificationLog::STATUS_PENDING,
        ]);

        $this->assertFalse($log->wasSent());

        $log->markAsSent('external-123');
        $log->refresh();

        $this->assertTrue($log->wasSent());
        $this->assertEquals('external-123', $log->external_id);
        $this->assertNotNull($log->sent_at);
    }

    public function test_can_duplicate_template(): void
    {
        Passport::actingAs($this->user);

        $template = NotificationTemplate::create([
            'store_id' => $this->store->id,
            'name' => 'Original Template',
            'slug' => 'original',
            'channel' => NotificationChannel::TYPE_EMAIL,
            'subject' => 'Subject',
            'content' => 'Content',
        ]);

        $response = $this->postJson("/api/v1/notification-templates/{$template->id}/duplicate", [
            'name' => 'Duplicated Template',
            'slug' => 'duplicated',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Duplicated Template',
                'slug' => 'duplicated',
            ]);

        $this->assertDatabaseHas('notification_templates', [
            'store_id' => $this->store->id,
            'slug' => 'duplicated',
        ]);
    }

    public function test_cannot_delete_template_with_subscriptions(): void
    {
        Passport::actingAs($this->user);

        $template = NotificationTemplate::create([
            'store_id' => $this->store->id,
            'name' => 'Template with Sub',
            'slug' => 'with-sub',
            'channel' => NotificationChannel::TYPE_EMAIL,
            'subject' => 'Subject',
            'content' => 'Content',
        ]);

        NotificationSubscription::create([
            'store_id' => $this->store->id,
            'notification_template_id' => $template->id,
            'activity' => Activity::PRODUCTS_CREATE,
        ]);

        $response = $this->deleteJson("/api/v1/notification-templates/{$template->id}");

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Cannot delete template with active subscriptions',
            ]);
    }

    public function test_delayed_subscription_creates_queued_notification(): void
    {
        $template = NotificationTemplate::create([
            'store_id' => $this->store->id,
            'name' => 'Delayed Template',
            'slug' => 'delayed',
            'channel' => NotificationChannel::TYPE_EMAIL,
            'subject' => 'Delayed',
            'content' => 'Content',
            'is_enabled' => true,
        ]);

        $subscription = NotificationSubscription::create([
            'store_id' => $this->store->id,
            'notification_template_id' => $template->id,
            'activity' => Activity::PRODUCTS_CREATE,
            'schedule_type' => 'delayed',
            'delay_minutes' => 30,
            'delay_unit' => 'minutes',
            'recipients' => [['type' => 'custom', 'value' => 'test@example.com']],
            'is_enabled' => true,
        ]);

        $this->store->load('owner');
        $manager = new NotificationManager($this->store);

        // This should queue rather than send immediately
        $logs = $manager->trigger(Activity::PRODUCTS_CREATE, []);

        // No immediate logs, but queued notification created
        $this->assertCount(0, $logs);
        $this->assertDatabaseHas('queued_notifications', [
            'store_id' => $this->store->id,
            'notification_subscription_id' => $subscription->id,
        ]);
    }
}
