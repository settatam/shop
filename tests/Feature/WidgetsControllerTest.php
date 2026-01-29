<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use App\Widget\Invoices\InvoicesTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_view_widget(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/widgets/view?type=App\\Widget\\Invoices\\InvoicesTable');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'widget',
                'title',
                'component',
                'data',
            ]);
    }

    public function test_widget_view_requires_type(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/widgets/view');

        $response->assertStatus(422);
    }

    public function test_widget_view_rejects_invalid_type(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/widgets/view?type=App\\Widget\\NonExistentWidget');

        $response->assertStatus(400);
    }

    public function test_widget_view_rejects_non_widget_class(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/widgets/view?type=App\\Models\\User');

        $response->assertStatus(400);
    }

    public function test_invoices_table_returns_correct_structure(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $order = Order::factory()->create(['store_id' => $this->store->id]);
        Invoice::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $response = $this->getJson('/widgets/view?type=App\\Widget\\Invoices\\InvoicesTable');

        $response->assertStatus(200)
            ->assertJsonPath('title', 'Invoices')
            ->assertJsonPath('component', 'Table')
            ->assertJsonStructure([
                'data' => [
                    'fields',
                    'options',
                    'items',
                ],
                'pagination',
                'fields',
            ]);
    }

    public function test_invoices_table_filters_by_store(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();

        $order1 = Order::factory()->create(['store_id' => $this->store->id]);
        $order2 = Order::factory()->create(['store_id' => $otherStore->id]);

        Invoice::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order1->id,
        ]);
        Invoice::factory()->count(5)->create([
            'store_id' => $otherStore->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order2->id,
        ]);

        $response = $this->getJson('/widgets/view?type=App\\Widget\\Invoices\\InvoicesTable');

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $this->assertCount(2, $items);
    }

    public function test_invoices_table_applies_search_filter(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        $order = Order::factory()->create(['store_id' => $this->store->id]);

        Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);
        Invoice::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $response = $this->getJson('/widgets/view?type=App\\Widget\\Invoices\\InvoicesTable&term=John');

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
    }

    public function test_invoices_table_applies_status_filter(): void
    {
        $this->actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);

        Invoice::factory()->pending()->count(2)->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);
        Invoice::factory()->paid()->count(3)->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        $response = $this->getJson('/widgets/view?type=App\\Widget\\Invoices\\InvoicesTable&status=pending');

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $this->assertCount(2, $items);
    }

    public function test_invoices_table_supports_pagination(): void
    {
        $this->actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);

        Invoice::factory()->count(25)->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        // Page 1 with 15 per page
        $response = $this->getJson('/widgets/view?type=App\\Widget\\Invoices\\InvoicesTable&per_page=15&page=1');

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $pagination = $response->json('pagination');

        $this->assertCount(15, $items);
        $this->assertEquals(25, $pagination['total']);
        $this->assertEquals(1, $pagination['current_page']);

        // Page 2
        $response = $this->getJson('/widgets/view?type=App\\Widget\\Invoices\\InvoicesTable&per_page=15&page=2');

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $this->assertCount(10, $items);
    }

    public function test_widget_can_use_short_type_name(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/widgets/view?type=Invoices\\InvoicesTable');

        $response->assertStatus(200)
            ->assertJsonPath('title', 'Invoices');
    }

    public function test_invoices_table_instantiation(): void
    {
        $table = new InvoicesTable(['store_id' => $this->store->id]);

        $this->assertIsArray($table->fields());
        $this->assertNotEmpty($table->fields());
        $this->assertEquals('Invoices', $table->title([]));
    }

    public function test_widget_accepts_base64_encoded_data(): void
    {
        $this->actingAs($this->user);

        $data = base64_encode(json_encode([
            'type' => 'App\\Widget\\Invoices\\InvoicesTable',
            'status' => 'pending',
        ]));

        $response = $this->getJson('/widgets/view?type=App\\Widget\\Invoices\\InvoicesTable&base64data='.$data);

        $response->assertStatus(200);
    }
}
