<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCompletionNotificationTest extends TestCase
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

    public function test_completing_order_triggers_notification_without_error(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'email' => 'customer@example.com',
        ]);

        $order = Order::factory()->delivered()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->post(route('web.orders.complete', $order));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Order completed.');

        $order->refresh();
        $this->assertEquals(Order::STATUS_COMPLETED, $order->status);
    }

    public function test_completing_order_without_customer_does_not_fail(): void
    {
        $order = Order::factory()->confirmed()->create([
            'store_id' => $this->store->id,
            'customer_id' => null,
            'user_id' => $this->user->id,
        ]);

        $response = $this->post(route('web.orders.complete', $order));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Order completed.');

        $order->refresh();
        $this->assertEquals(Order::STATUS_COMPLETED, $order->status);
    }

    public function test_orders_complete_activity_constant_exists(): void
    {
        $this->assertEquals('orders.complete', Activity::ORDERS_COMPLETE);

        $definitions = Activity::getDefinitions();
        $this->assertArrayHasKey(Activity::ORDERS_COMPLETE, $definitions);
        $this->assertEquals('Complete Orders', $definitions[Activity::ORDERS_COMPLETE]['name']);
        $this->assertEquals(Activity::CATEGORY_ORDERS, $definitions[Activity::ORDERS_COMPLETE]['category']);
    }

    public function test_order_completed_default_template_exists(): void
    {
        $templates = NotificationTemplate::getDefaultTemplates();
        $slugs = array_column($templates, 'slug');

        $this->assertContains('order-completed', $slugs);
        $this->assertContains('order-completed-sms', $slugs);
    }

    public function test_order_completed_default_template_renders(): void
    {
        $template = new NotificationTemplate([
            'slug' => 'order-completed',
            'name' => 'Order Completed',
            'channel' => NotificationChannel::TYPE_EMAIL,
            'subject' => 'Your Order #{{ order.number }} is Complete',
            'content' => '<h2>Order Complete</h2><p>Hi {{ customer.name }},</p><p>Total: ${{ order.total|money }}</p>',
            'available_variables' => ['order', 'customer', 'store'],
        ]);

        $data = [
            'order' => [
                'number' => 'ORD-001',
                'total' => 150.50,
                'items' => [],
            ],
            'customer' => [
                'name' => 'Jane Doe',
            ],
            'store' => [
                'name' => 'Test Store',
            ],
        ];

        $rendered = $template->render($data);
        $subject = $template->renderSubject($data);

        $this->assertStringContainsString('Order Complete', $rendered);
        $this->assertStringContainsString('Jane Doe', $rendered);
        $this->assertStringContainsString('$150.50', $rendered);
        $this->assertStringContainsString('ORD-001', $subject);
    }

    public function test_render_with_layout_wraps_body_in_email_layout(): void
    {
        $body = '<h2>Order Complete</h2><p>Hi Jane,</p>';
        $store = [
            'name' => 'Test Jewelry Store',
            'logo' => 'https://example.com/logo.png',
            'full_address' => '123 Main St, New York, NY, 10001',
            'phone' => '(555) 123-4567',
        ];

        $result = NotificationTemplate::renderWithLayout($body, $store);

        $this->assertStringContainsString('<!DOCTYPE html>', $result);
        $this->assertStringContainsString('Order Complete', $result);
        $this->assertStringContainsString('Hi Jane', $result);
        $this->assertStringContainsString('Test Jewelry Store', $result);
        $this->assertStringContainsString('123 Main St, New York, NY, 10001', $result);
        $this->assertStringContainsString('(555) 123-4567', $result);
        $this->assertStringContainsString('https://example.com/logo.png', $result);
    }

    public function test_render_with_layout_works_without_logo(): void
    {
        $body = '<p>Hello</p>';
        $store = [
            'name' => 'No Logo Store',
            'full_address' => '456 Oak Ave',
        ];

        $result = NotificationTemplate::renderWithLayout($body, $store);

        $this->assertStringContainsString('<!DOCTYPE html>', $result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('No Logo Store', $result);
        $this->assertStringNotContainsString('<img', $result);
    }

    public function test_order_completed_sms_template_renders(): void
    {
        $template = new NotificationTemplate([
            'slug' => 'order-completed-sms',
            'name' => 'Order Completed (SMS)',
            'channel' => NotificationChannel::TYPE_SMS,
            'subject' => null,
            'content' => 'Your order #{{ order.number }} is complete! Total: ${{ order.total|money }}. Thank you for shopping with {{ store.name }}!',
            'available_variables' => ['order', 'customer', 'store'],
        ]);

        $data = [
            'order' => [
                'number' => 'ORD-002',
                'total' => 99.99,
            ],
            'customer' => [
                'name' => 'John Doe',
            ],
            'store' => [
                'name' => 'My Store',
            ],
        ];

        $rendered = $template->render($data);

        $this->assertStringContainsString('ORD-002', $rendered);
        $this->assertStringContainsString('$99.99', $rendered);
        $this->assertStringContainsString('My Store', $rendered);
    }
}
