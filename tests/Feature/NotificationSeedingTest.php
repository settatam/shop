<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSeedingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->onboarded()->create([
            'user_id' => $this->user->id,
            'name' => 'Test Store',
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_create_default_templates_seeds_all_templates(): void
    {
        NotificationTemplate::createDefaultTemplates($this->store->id);

        $templates = NotificationTemplate::withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->get();

        $expectedSlugs = collect(NotificationTemplate::getDefaultTemplates())->pluck('slug')->sort()->values();
        $actualSlugs = $templates->pluck('slug')->sort()->values();

        $this->assertEquals($expectedSlugs->toArray(), $actualSlugs->toArray());
    }

    public function test_create_default_templates_includes_new_templates(): void
    {
        NotificationTemplate::createDefaultTemplates($this->store->id);

        $newSlugs = ['product-updated', 'order-cancelled', 'listing-published', 'transaction-created'];

        foreach ($newSlugs as $slug) {
            $this->assertDatabaseHas('notification_templates', [
                'store_id' => $this->store->id,
                'slug' => $slug,
                'channel' => NotificationChannel::TYPE_EMAIL,
                'is_system' => true,
            ]);
        }
    }

    public function test_create_default_templates_includes_alert_templates(): void
    {
        NotificationTemplate::createDefaultTemplates($this->store->id);

        $alertSlugs = [
            'alert-price-changed',
            'alert-inventory-adjusted',
            'alert-closed-order-deleted',
            'alert-closed-transaction-deleted',
        ];

        foreach ($alertSlugs as $slug) {
            $this->assertDatabaseHas('notification_templates', [
                'store_id' => $this->store->id,
                'slug' => $slug,
                'category' => 'alerts',
            ]);
        }
    }

    public function test_create_default_subscriptions_wires_templates_to_activities(): void
    {
        NotificationTemplate::createDefaultTemplates($this->store->id);
        NotificationSubscription::createDefaultSubscriptions($this->store->id);

        $subscriptions = NotificationSubscription::withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->get();

        $expectedMappings = NotificationSubscription::getDefaultSubscriptions();

        // Each mapping that has a template should have a subscription
        foreach ($expectedMappings as $slug => $config) {
            $template = NotificationTemplate::withoutGlobalScopes()
                ->where('store_id', $this->store->id)
                ->where('slug', $slug)
                ->first();

            if ($template) {
                $subscription = $subscriptions->firstWhere('activity', $config['activity']);
                $this->assertNotNull($subscription, "Subscription missing for activity: {$config['activity']}");
                $this->assertEquals($template->id, $subscription->notification_template_id);
                $this->assertTrue($subscription->is_enabled);
            }
        }
    }

    public function test_default_subscriptions_are_enabled_by_default(): void
    {
        NotificationTemplate::createDefaultTemplates($this->store->id);
        NotificationSubscription::createDefaultSubscriptions($this->store->id);

        $subscriptions = NotificationSubscription::withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->get();

        foreach ($subscriptions as $subscription) {
            $this->assertTrue($subscription->is_enabled, "Subscription '{$subscription->name}' should be enabled by default");
        }
    }

    public function test_subscriptions_can_be_disabled_for_opt_out(): void
    {
        NotificationTemplate::createDefaultTemplates($this->store->id);
        NotificationSubscription::createDefaultSubscriptions($this->store->id);

        $subscription = NotificationSubscription::withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->where('activity', Activity::ORDERS_CREATE)
            ->first();

        $this->assertNotNull($subscription);
        $this->assertTrue($subscription->is_enabled);

        $subscription->update(['is_enabled' => false]);
        $subscription->refresh();

        $this->assertFalse($subscription->is_enabled);

        // Disabled subscriptions should not be returned by forActivity
        $active = NotificationSubscription::forActivity(Activity::ORDERS_CREATE, $this->store->id);
        $this->assertTrue($active->isEmpty());
    }

    public function test_seeding_is_idempotent(): void
    {
        NotificationTemplate::createDefaultTemplates($this->store->id);
        NotificationSubscription::createDefaultSubscriptions($this->store->id);

        $templateCount1 = NotificationTemplate::withoutGlobalScopes()
            ->where('store_id', $this->store->id)->count();
        $subCount1 = NotificationSubscription::withoutGlobalScopes()
            ->where('store_id', $this->store->id)->count();

        // Run again
        NotificationTemplate::createDefaultTemplates($this->store->id);
        NotificationSubscription::createDefaultSubscriptions($this->store->id);

        $templateCount2 = NotificationTemplate::withoutGlobalScopes()
            ->where('store_id', $this->store->id)->count();
        $subCount2 = NotificationSubscription::withoutGlobalScopes()
            ->where('store_id', $this->store->id)->count();

        $this->assertEquals($templateCount1, $templateCount2);
        $this->assertEquals($subCount1, $subCount2);
    }

    public function test_default_subscriptions_use_immediate_scheduling(): void
    {
        NotificationTemplate::createDefaultTemplates($this->store->id);
        NotificationSubscription::createDefaultSubscriptions($this->store->id);

        $subscriptions = NotificationSubscription::withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->get();

        foreach ($subscriptions as $subscription) {
            $this->assertEquals(
                NotificationSubscription::SCHEDULE_IMMEDIATE,
                $subscription->schedule_type,
                "Subscription '{$subscription->name}' should use immediate scheduling"
            );
        }
    }

    public function test_order_subscriptions_send_to_owner(): void
    {
        NotificationTemplate::createDefaultTemplates($this->store->id);
        NotificationSubscription::createDefaultSubscriptions($this->store->id);

        $orderActivities = [
            Activity::ORDERS_CREATE,
            Activity::ORDERS_FULFILL,
            Activity::ORDERS_COMPLETE,
            Activity::ORDERS_CANCEL,
        ];

        foreach ($orderActivities as $activity) {
            $subscription = NotificationSubscription::withoutGlobalScopes()
                ->where('store_id', $this->store->id)
                ->where('activity', $activity)
                ->first();

            $this->assertNotNull($subscription, "Missing subscription for {$activity}");
            $this->assertEquals(
                [['type' => NotificationSubscription::RECIPIENT_OWNER]],
                $subscription->recipients
            );
        }
    }

    public function test_customer_welcome_sends_to_customer(): void
    {
        NotificationTemplate::createDefaultTemplates($this->store->id);
        NotificationSubscription::createDefaultSubscriptions($this->store->id);

        $subscription = NotificationSubscription::withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->where('activity', Activity::CUSTOMERS_CREATE)
            ->first();

        $this->assertNotNull($subscription);
        $this->assertEquals(
            [['type' => NotificationSubscription::RECIPIENT_CUSTOMER]],
            $subscription->recipients
        );
    }

    public function test_each_template_has_correct_channel(): void
    {
        NotificationTemplate::createDefaultTemplates($this->store->id);

        $templates = NotificationTemplate::withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->get();

        foreach ($templates as $template) {
            if (str_ends_with($template->slug, '-sms')) {
                $this->assertEquals(NotificationChannel::TYPE_SMS, $template->channel, "{$template->slug} should be SMS");
            } else {
                $this->assertEquals(NotificationChannel::TYPE_EMAIL, $template->channel, "{$template->slug} should be email");
            }
        }
    }
}
