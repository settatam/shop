<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\SimilarItemFinder;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimilarItemFinderTest extends TestCase
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

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_finds_similar_items_by_category(): void
    {
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Diamond Ring',
            'category_id' => $category->id,
        ]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Gold Ring',
            'category_id' => $category->id,
        ]);

        $finder = new SimilarItemFinder;
        $results = $finder->findSimilar($item);

        $this->assertNotEmpty($results);
        $this->assertTrue($results->contains(fn ($r) => $r['id'] === $product->id));
    }

    public function test_finds_similar_items_by_title_keywords(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => '14K Gold Chain Necklace',
        ]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => '14K Gold Bracelet',
        ]);

        $finder = new SimilarItemFinder;
        $results = $finder->findSimilar($item);

        $this->assertNotEmpty($results);
        $this->assertTrue($results->contains(fn ($r) => $r['id'] === $product->id));
    }

    public function test_returns_empty_when_no_similar_items(): void
    {
        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Extremely Unique Item XYZ123',
        ]);

        $finder = new SimilarItemFinder;
        $results = $finder->findSimilar($item);

        $this->assertEmpty($results);
    }

    public function test_respects_limit(): void
    {
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        for ($i = 0; $i < 5; $i++) {
            $product = Product::factory()->create([
                'store_id' => $this->store->id,
                'title' => "Gold Ring Style {$i}",
                'category_id' => $category->id,
            ]);
            ProductVariant::factory()->create(['product_id' => $product->id]);
        }

        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Gold Ring',
            'category_id' => $category->id,
        ]);

        $finder = new SimilarItemFinder;
        $results = $finder->findSimilar($item, 2);

        $this->assertCount(2, $results);
    }

    public function test_similar_items_endpoint_returns_json(): void
    {
        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create(['transaction_id' => $transaction->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/transactions/{$transaction->id}/items/{$item->id}/similar");

        $response->assertStatus(200);
        $response->assertJsonStructure(['items']);
    }
}
