<?php

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Models\NotificationLayout;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationLayoutTest extends TestCase
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
            'phone' => '(555) 111-2222',
            'address' => '100 Test Ave',
            'city' => 'Testville',
            'state' => 'TX',
            'zip' => '75001',
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

    public function test_notification_layout_can_be_created(): void
    {
        $layout = NotificationLayout::factory()->email()->create([
            'store_id' => $this->store->id,
            'name' => 'My Email Layout',
            'slug' => 'my-email-layout',
        ]);

        $this->assertDatabaseHas('notification_layouts', [
            'id' => $layout->id,
            'store_id' => $this->store->id,
            'name' => 'My Email Layout',
            'channel' => NotificationChannel::TYPE_EMAIL,
        ]);
    }

    public function test_default_layouts_created_for_store(): void
    {
        NotificationLayout::createDefaultLayouts($this->store->id);

        $emailLayout = NotificationLayout::where('store_id', $this->store->id)
            ->where('channel', NotificationChannel::TYPE_EMAIL)
            ->where('is_default', true)
            ->first();

        $smsLayout = NotificationLayout::where('store_id', $this->store->id)
            ->where('channel', NotificationChannel::TYPE_SMS)
            ->where('is_default', true)
            ->first();

        $this->assertNotNull($emailLayout);
        $this->assertTrue($emailLayout->is_system);
        $this->assertEquals('default-email', $emailLayout->slug);

        $this->assertNotNull($smsLayout);
        $this->assertTrue($smsLayout->is_system);
        $this->assertEquals('default-sms', $smsLayout->slug);
    }

    public function test_layout_renders_body_with_store_data(): void
    {
        $layout = NotificationLayout::factory()->create([
            'store_id' => $this->store->id,
            'channel' => NotificationChannel::TYPE_EMAIL,
            'content' => '<div>{{ body|raw }}</div><footer>{{ store.name }}</footer>',
        ]);

        $result = $layout->render('<p>Hello World</p>', ['name' => 'My Store']);

        $this->assertStringContainsString('<p>Hello World</p>', $result);
        $this->assertStringContainsString('My Store', $result);
    }

    public function test_resolve_template_with_explicit_layout(): void
    {
        $layout = NotificationLayout::factory()->email()->create([
            'store_id' => $this->store->id,
            'slug' => 'custom-layout',
        ]);

        $template = NotificationTemplate::factory()->email()->create([
            'store_id' => $this->store->id,
            'notification_layout_id' => $layout->id,
        ]);

        $resolved = NotificationLayout::resolveForTemplate($template);

        $this->assertNotNull($resolved);
        $this->assertEquals($layout->id, $resolved->id);
    }

    public function test_resolve_template_falls_back_to_store_default(): void
    {
        $defaultLayout = NotificationLayout::factory()->email()->default()->create([
            'store_id' => $this->store->id,
            'slug' => 'store-default',
        ]);

        $template = NotificationTemplate::factory()->email()->create([
            'store_id' => $this->store->id,
            'notification_layout_id' => null,
        ]);

        $resolved = NotificationLayout::resolveForTemplate($template);

        $this->assertNotNull($resolved);
        $this->assertEquals($defaultLayout->id, $resolved->id);
    }

    public function test_resolve_template_returns_null_when_no_layouts_exist(): void
    {
        $template = NotificationTemplate::factory()->email()->create([
            'store_id' => $this->store->id,
            'notification_layout_id' => null,
        ]);

        $resolved = NotificationLayout::resolveForTemplate($template);

        $this->assertNull($resolved);
    }

    public function test_render_with_layout_backward_compatible(): void
    {
        $result = NotificationTemplate::renderWithLayout(
            '<p>Test Body</p>',
            ['name' => 'Fallback Store', 'phone' => '555-0000']
        );

        $this->assertStringContainsString('<!DOCTYPE html>', $result);
        $this->assertStringContainsString('Test Body', $result);
        $this->assertStringContainsString('Fallback Store', $result);
    }

    public function test_set_default_layout_unsets_others(): void
    {
        $layout1 = NotificationLayout::factory()->email()->default()->create([
            'store_id' => $this->store->id,
            'slug' => 'layout-1',
        ]);

        $layout2 = NotificationLayout::factory()->email()->create([
            'store_id' => $this->store->id,
            'slug' => 'layout-2',
        ]);

        // Set layout2 as default via the endpoint
        $this->post(route('settings.notifications.layouts.set-default', $layout2));

        $layout1->refresh();
        $layout2->refresh();

        $this->assertFalse($layout1->is_default);
        $this->assertTrue($layout2->is_default);
    }

    public function test_disabled_layout_skipped_in_resolution(): void
    {
        NotificationLayout::factory()->email()->default()->disabled()->create([
            'store_id' => $this->store->id,
            'slug' => 'disabled-default',
        ]);

        $template = NotificationTemplate::factory()->email()->create([
            'store_id' => $this->store->id,
            'notification_layout_id' => null,
        ]);

        $resolved = NotificationLayout::resolveForTemplate($template);

        $this->assertNull($resolved);
    }

    public function test_sms_layout_wraps_body(): void
    {
        $layout = NotificationLayout::factory()->sms()->create([
            'store_id' => $this->store->id,
            'slug' => 'sms-layout',
        ]);

        $result = $layout->render('Your order is ready', ['name' => 'Jewelry Shop']);

        $this->assertStringContainsString('Your order is ready', $result);
        $this->assertStringContainsString('Jewelry Shop', $result);
    }

    public function test_default_email_layout_renders_full_html(): void
    {
        NotificationLayout::createDefaultLayouts($this->store->id);

        $layout = NotificationLayout::where('store_id', $this->store->id)
            ->where('channel', NotificationChannel::TYPE_EMAIL)
            ->where('is_default', true)
            ->first();

        $result = $layout->render('<h2>Order Complete</h2>', [
            'name' => 'Test Store',
            'logo' => 'https://example.com/logo.png',
            'full_address' => '100 Test Ave, Testville, TX, 75001',
            'phone' => '(555) 111-2222',
        ]);

        $this->assertStringContainsString('<!DOCTYPE html>', $result);
        $this->assertStringContainsString('Order Complete', $result);
        $this->assertStringContainsString('Test Store', $result);
        $this->assertStringContainsString('https://example.com/logo.png', $result);
        $this->assertStringContainsString('100 Test Ave, Testville, TX, 75001', $result);
        $this->assertStringContainsString('(555) 111-2222', $result);
    }

    public function test_create_default_layouts_is_idempotent(): void
    {
        NotificationLayout::createDefaultLayouts($this->store->id);
        NotificationLayout::createDefaultLayouts($this->store->id);

        $count = NotificationLayout::where('store_id', $this->store->id)->count();

        $this->assertEquals(2, $count);
    }

    public function test_template_layout_relationship(): void
    {
        $layout = NotificationLayout::factory()->email()->create([
            'store_id' => $this->store->id,
            'slug' => 'rel-test',
        ]);

        $template = NotificationTemplate::factory()->email()->create([
            'store_id' => $this->store->id,
            'notification_layout_id' => $layout->id,
        ]);

        $template->refresh();
        $this->assertEquals($layout->id, $template->notification_layout_id);

        $resolvedLayout = NotificationLayout::withoutGlobalScopes()->find($template->notification_layout_id);
        $this->assertNotNull($resolvedLayout);
        $this->assertEquals($layout->id, $resolvedLayout->id);

        $templates = NotificationTemplate::withoutGlobalScopes()
            ->where('notification_layout_id', $layout->id)
            ->get();
        $this->assertTrue($templates->contains('id', $template->id));
    }
}
