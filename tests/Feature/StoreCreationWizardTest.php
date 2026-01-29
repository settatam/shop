<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreCreationWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_store_with_minimal_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/stores', [
            'name' => 'My Test Store',
        ]);

        $response->assertRedirect('/dashboard');

        // Verify store was created
        $this->assertDatabaseHas('stores', [
            'name' => 'My Test Store',
            'user_id' => $user->id,
        ]);

        // Verify default roles were created
        $store = Store::where('name', 'My Test Store')->first();
        $this->assertCount(5, Role::where('store_id', $store->id)->get());

        // Verify default warehouse was created
        $this->assertDatabaseHas('warehouses', [
            'store_id' => $store->id,
            'name' => 'Main Warehouse',
            'is_default' => true,
        ]);

        // Verify store user was created with owner role
        $this->assertDatabaseHas('store_users', [
            'user_id' => $user->id,
            'store_id' => $store->id,
            'is_owner' => true,
        ]);
    }

    public function test_can_create_store_with_address(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/stores', [
            'name' => 'My Store With Address',
            'address_line1' => '123 Main Street',
            'address_line2' => 'Suite 100',
            'city' => 'San Francisco',
            'state' => 'CA',
            'postal_code' => '94102',
            'country' => 'US',
        ]);

        $response->assertRedirect('/dashboard');

        $this->assertDatabaseHas('stores', [
            'name' => 'My Store With Address',
            'address' => '123 Main Street',
            'address2' => 'Suite 100',
            'city' => 'San Francisco',
            'state' => 'CA',
            'zip' => '94102',
        ]);

        // Verify warehouse also has the address
        $store = Store::where('name', 'My Store With Address')->first();
        $this->assertDatabaseHas('warehouses', [
            'store_id' => $store->id,
            'address_line1' => '123 Main Street',
            'city' => 'San Francisco',
        ]);
    }

    public function test_can_create_store_with_industry_and_categories(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/stores', [
            'name' => 'My Jewelry Store',
            'industry' => 'jewelry',
            'create_sample_data' => false,
        ]);

        $response->assertRedirect('/dashboard');

        $store = Store::where('name', 'My Jewelry Store')->first();

        // Verify categories were created for jewelry industry
        $categories = Category::where('store_id', $store->id)->get();
        $this->assertCount(5, $categories);
        $this->assertTrue($categories->pluck('name')->contains('Rings'));
        $this->assertTrue($categories->pluck('name')->contains('Necklaces'));
    }

    public function test_can_create_store_with_sample_products(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/stores', [
            'name' => 'My Electronics Store',
            'industry' => 'electronics',
            'create_sample_data' => true,
        ]);

        $response->assertRedirect('/dashboard');

        $store = Store::where('name', 'My Electronics Store')->first();

        // Verify sample products were created
        $products = Product::where('store_id', $store->id)->get();
        $this->assertCount(2, $products);

        // Verify inventory was created for the products
        $inventory = Inventory::where('store_id', $store->id)->get();
        $this->assertCount(2, $inventory);
    }

    public function test_store_name_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/stores', [
            'name' => '',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_other_industry_creates_general_category(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/stores', [
            'name' => 'My General Store',
            'industry' => 'other',
            'create_sample_data' => false,
        ]);

        $response->assertRedirect('/dashboard');

        $store = Store::where('name', 'My General Store')->first();

        // Verify only General category was created
        $categories = Category::where('store_id', $store->id)->get();
        $this->assertCount(1, $categories);
        $this->assertEquals('General', $categories->first()->name);
    }

    public function test_user_is_switched_to_new_store(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/stores', [
            'name' => 'My New Store',
        ]);

        $user->refresh();

        $store = Store::where('name', 'My New Store')->first();
        $this->assertEquals($store->id, $user->current_store_id);
    }
}
