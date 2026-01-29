<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_are_scoped_to_store(): void
    {
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();

        // Create products for store 1
        Product::factory()->count(3)->create(['store_id' => $store1->id]);

        // Create products for store 2
        Product::factory()->count(2)->create(['store_id' => $store2->id]);

        // Set store context to store 1
        app(StoreContext::class)->setCurrentStore($store1);

        // Should only see store 1's products
        $this->assertCount(3, Product::all());

        // Set store context to store 2
        app(StoreContext::class)->setCurrentStore($store2);

        // Should only see store 2's products
        $this->assertCount(2, Product::all());
    }

    public function test_products_auto_fill_store_id_on_create(): void
    {
        $store = Store::factory()->create();

        app(StoreContext::class)->setCurrentStore($store);

        $product = Product::create([
            'title' => 'Test Product',
            'handle' => 'test-product',
        ]);

        $this->assertEquals($store->id, $product->store_id);
    }

    public function test_can_query_without_store_scope(): void
    {
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();

        Product::factory()->count(3)->create(['store_id' => $store1->id]);
        Product::factory()->count(2)->create(['store_id' => $store2->id]);

        app(StoreContext::class)->setCurrentStore($store1);

        // With scope - only store 1's products
        $this->assertCount(3, Product::all());

        // Without scope - all products
        $this->assertCount(5, Product::withoutGlobalScopes()->get());
    }

    public function test_store_context_returns_current_store(): void
    {
        $store = Store::factory()->create();
        $context = app(StoreContext::class);

        $this->assertFalse($context->hasStore());

        $context->setCurrentStore($store);

        $this->assertTrue($context->hasStore());
        $this->assertEquals($store->id, $context->getCurrentStoreId());
        $this->assertEquals($store->id, $context->getCurrentStore()->id);
    }

    public function test_user_can_have_multiple_stores(): void
    {
        $user = User::factory()->create();
        $store1 = Store::factory()->create(['user_id' => $user->id]);
        $store2 = Store::factory()->create(['user_id' => $user->id]);

        $this->assertCount(2, $user->ownedStores);
        $this->assertTrue($user->hasAccessToStore($store1->id));
        $this->assertTrue($user->hasAccessToStore($store2->id));
    }
}
