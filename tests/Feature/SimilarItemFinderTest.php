<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\SimilarItemFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimilarItemFinderTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $owner->id,
            'step' => 2,
        ]);

        $ownerRole = Role::factory()->owner()->create(['store_id' => $this->store->id]);

        StoreUser::factory()->owner()->create([
            'user_id' => $owner->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
        ]);

        $this->category = Category::factory()->create(['store_id' => $this->store->id, 'name' => 'Watches']);
    }

    public function test_matching_attributes_increase_score(): void
    {
        // Create a transaction item with Rolex brand
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'payment_processed',
        ]);

        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $this->category->id,
            'title' => 'Rolex Submariner',
            'attributes' => ['brand' => 'Rolex', 'model' => 'Submariner'],
            'buy_price' => 5000,
        ]);

        $finder = app(SimilarItemFinder::class);

        // Search for Rolex brand - should get a match
        $results = $finder->findSimilarTransactionItems([
            'category_id' => $this->category->id,
            'attributes' => ['brand' => 'Rolex'],
        ], $this->store->id);

        $this->assertCount(1, $results);
        $item = $results->first();
        $this->assertTrue(
            collect($item['match_reasons'])->contains(fn ($reason) => str_contains($reason, 'Matches'))
        );
    }

    public function test_mismatched_attributes_decrease_score(): void
    {
        // Create transaction items with different brands
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'payment_processed',
        ]);

        // Rolex item
        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $this->category->id,
            'title' => 'Rolex Submariner',
            'attributes' => ['brand' => 'Rolex'],
            'buy_price' => 5000,
        ]);

        // Cartier item
        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $this->category->id,
            'title' => 'Cartier Santos',
            'attributes' => ['brand' => 'Cartier'],
            'buy_price' => 4000,
        ]);

        $finder = app(SimilarItemFinder::class);

        // Search for Rolex brand - Cartier should have a lower score due to mismatch penalty
        $results = $finder->findSimilarTransactionItems([
            'category_id' => $this->category->id,
            'attributes' => ['brand' => 'Rolex'],
        ], $this->store->id);

        // Should have both items (category matches)
        $this->assertCount(2, $results);

        // Find the items by title
        $rolexItem = $results->firstWhere('title', 'Rolex Submariner');
        $cartierItem = $results->firstWhere('title', 'Cartier Santos');

        // Rolex should have higher score than Cartier
        $this->assertGreaterThan($cartierItem['similarity_score'], $rolexItem['similarity_score']);

        // Cartier should have "Different: Brand" in its reasons
        $this->assertTrue(
            collect($cartierItem['match_reasons'])->contains(fn ($reason) => str_contains($reason, 'Different'))
        );
    }

    public function test_mismatch_penalty_can_make_item_not_show(): void
    {
        // Create a transaction item with Cartier brand
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'payment_processed',
        ]);

        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $this->category->id,
            'title' => 'Cartier Santos',
            'attributes' => ['brand' => 'Cartier'],
            'buy_price' => 4000,
        ]);

        $finder = app(SimilarItemFinder::class);

        // Search for Rolex brand without category filter
        // The mismatch penalty should make the score <= 0, excluding it from results
        $results = $finder->findSimilarTransactionItems([
            'attributes' => ['brand' => 'Rolex'],
        ], $this->store->id);

        // Item should not appear because mismatch penalty (-30) puts it at or below 0
        $this->assertCount(0, $results);
    }
}
