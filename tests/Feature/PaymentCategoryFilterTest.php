<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_payments_can_be_filtered_by_category(): void
    {
        // Create categories
        $diamondCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Diamonds',
        ]);

        $watchCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Watches',
        ]);

        // Create order with diamond item
        $diamondOrder = Order::factory()->create([
            'store_id' => $this->store->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $diamondOrder->id,
            'category_id' => $diamondCategory->id,
            'title' => 'Diamond Ring',
            'price' => 1000,
        ]);

        $diamondPayment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'payable_type' => Order::class,
            'payable_id' => $diamondOrder->id,
            'amount' => 1000,
        ]);

        // Create order with watch item
        $watchOrder = Order::factory()->create([
            'store_id' => $this->store->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $watchOrder->id,
            'category_id' => $watchCategory->id,
            'title' => 'Luxury Watch',
            'price' => 500,
        ]);

        $watchPayment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'payable_type' => Order::class,
            'payable_id' => $watchOrder->id,
            'amount' => 500,
        ]);

        // Filter by diamond category
        $response = $this->actingAs($this->user)
            ->get('/payments?category_id='.$diamondCategory->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.id', $diamondPayment->id)
        );

        // Filter by watch category
        $response = $this->actingAs($this->user)
            ->get('/payments?category_id='.$watchCategory->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.id', $watchPayment->id)
        );
    }

    public function test_payments_page_shows_all_payments_without_category_filter(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
        ]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'category_id' => $category->id,
        ]);

        Payment::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/payments');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('payments.data', 3)
            ->has('categories')
        );
    }

    public function test_categories_are_passed_to_payments_page(): void
    {
        Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Diamonds',
        ]);

        Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Watches',
        ]);

        $response = $this->actingAs($this->user)
            ->get('/payments');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('categories', 2)
        );
    }

    public function test_category_filter_includes_child_categories(): void
    {
        // Create parent category
        $parentCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'parent_id' => null,
        ]);

        // Create child category
        $childCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'parent_id' => $parentCategory->id,
        ]);

        // Create grandchild category
        $grandchildCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Engagement Rings',
            'parent_id' => $childCategory->id,
        ]);

        // Create unrelated category
        $unrelatedCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Watches',
            'parent_id' => null,
        ]);

        // Create order with grandchild category item
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'category_id' => $grandchildCategory->id,
            'title' => 'Diamond Engagement Ring',
            'price' => 5000,
        ]);

        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'amount' => 5000,
        ]);

        // Create order with unrelated category
        $watchOrder = Order::factory()->create([
            'store_id' => $this->store->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $watchOrder->id,
            'category_id' => $unrelatedCategory->id,
            'title' => 'Luxury Watch',
            'price' => 3000,
        ]);

        Payment::factory()->create([
            'store_id' => $this->store->id,
            'payable_type' => Order::class,
            'payable_id' => $watchOrder->id,
            'amount' => 3000,
        ]);

        // Filter by parent category should include grandchild
        $response = $this->actingAs($this->user)
            ->get('/payments?category_id='.$parentCategory->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.id', $payment->id)
        );
    }

    public function test_categories_are_returned_in_tree_order_with_depth(): void
    {
        // Create parent
        $parent = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'parent_id' => null,
        ]);

        // Create child
        $child = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'parent_id' => $parent->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/payments');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('categories', 2)
            ->where('categories.0.value', $parent->id)
            ->where('categories.0.depth', 0)
            ->where('categories.0.isLeaf', false)
            ->where('categories.1.value', $child->id)
            ->where('categories.1.depth', 1)
            ->where('categories.1.isLeaf', true)
        );
    }
}
