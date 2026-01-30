<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Tag;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTagTest extends TestCase
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

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_create_tag(): void
    {
        $tag = Tag::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Featured',
            'color' => '#3b82f6',
        ]);

        $this->assertDatabaseHas('tags', [
            'store_id' => $this->store->id,
            'name' => 'Featured',
            'color' => '#3b82f6',
        ]);
    }

    public function test_tag_generates_slug_automatically(): void
    {
        $tag = Tag::create([
            'store_id' => $this->store->id,
            'name' => 'New Arrival',
        ]);

        $this->assertEquals('new-arrival', $tag->slug);
    }

    public function test_can_attach_tags_to_product(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $tag1 = Tag::factory()->create(['store_id' => $this->store->id, 'name' => 'Featured']);
        $tag2 = Tag::factory()->create(['store_id' => $this->store->id, 'name' => 'Sale']);

        $product->attachTags([$tag1->id, $tag2->id]);

        $this->assertCount(2, $product->tags);
        $this->assertTrue($product->hasTag($tag1));
        $this->assertTrue($product->hasTag($tag2));
    }

    public function test_can_detach_tags_from_product(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $tag1 = Tag::factory()->create(['store_id' => $this->store->id, 'name' => 'Featured']);
        $tag2 = Tag::factory()->create(['store_id' => $this->store->id, 'name' => 'Sale']);

        $product->attachTags([$tag1->id, $tag2->id]);
        $product->detachTags([$tag1->id]);

        $this->assertCount(1, $product->fresh()->tags);
        $this->assertFalse($product->fresh()->hasTag($tag1));
        $this->assertTrue($product->fresh()->hasTag($tag2));
    }

    public function test_can_sync_tags_on_product(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $tag1 = Tag::factory()->create(['store_id' => $this->store->id, 'name' => 'Featured']);
        $tag2 = Tag::factory()->create(['store_id' => $this->store->id, 'name' => 'Sale']);
        $tag3 = Tag::factory()->create(['store_id' => $this->store->id, 'name' => 'Clearance']);

        $product->syncTags([$tag1->id, $tag2->id]);
        $this->assertCount(2, $product->fresh()->tags);

        $product->syncTags([$tag2->id, $tag3->id]);
        $product->refresh();

        $this->assertCount(2, $product->tags);
        $this->assertFalse($product->hasTag($tag1));
        $this->assertTrue($product->hasTag($tag2));
        $this->assertTrue($product->hasTag($tag3));
    }

    public function test_can_sync_tags_by_name(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $product->syncTagsByName(['Featured', 'New'], $this->store->id);

        $this->assertCount(2, $product->fresh()->tags);
        $this->assertDatabaseHas('tags', ['store_id' => $this->store->id, 'name' => 'Featured']);
        $this->assertDatabaseHas('tags', ['store_id' => $this->store->id, 'name' => 'New']);
    }

    public function test_can_filter_products_by_tags(): void
    {
        $product1 = Product::factory()->create(['store_id' => $this->store->id]);
        $product2 = Product::factory()->create(['store_id' => $this->store->id]);
        $product3 = Product::factory()->create(['store_id' => $this->store->id]);

        $featuredTag = Tag::factory()->create(['store_id' => $this->store->id, 'name' => 'Featured']);
        $saleTag = Tag::factory()->create(['store_id' => $this->store->id, 'name' => 'Sale']);

        $product1->attachTags([$featuredTag->id]);
        $product2->attachTags([$featuredTag->id, $saleTag->id]);

        $featuredProducts = Product::withTags([$featuredTag->id])->get();
        $saleProducts = Product::withTags([$saleTag->id])->get();

        $this->assertCount(2, $featuredProducts);
        $this->assertCount(1, $saleProducts);
    }

    public function test_product_update_syncs_tags(): void
    {
        $this->actingAs($this->user);

        $vendor = \App\Models\Vendor::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id, 'vendor_id' => $vendor->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $tag1 = Tag::factory()->create(['store_id' => $this->store->id, 'name' => 'Featured']);
        $tag2 = Tag::factory()->create(['store_id' => $this->store->id, 'name' => 'Sale']);

        $response = $this->put("/products/{$product->id}", [
            'title' => 'Updated Product',
            'vendor_id' => $vendor->id,
            'tag_ids' => [$tag1->id, $tag2->id],
            'variants' => [
                [
                    'id' => $product->variants->first()->id,
                    'sku' => $product->variants->first()->sku,
                    'price' => 99.99,
                    'quantity' => 10,
                ],
            ],
        ]);

        $response->assertRedirect();

        $product->refresh();
        $this->assertCount(2, $product->tags);
        $this->assertTrue($product->hasTag($tag1));
        $this->assertTrue($product->hasTag($tag2));
    }

    public function test_tag_has_default_color(): void
    {
        $tag = Tag::create([
            'store_id' => $this->store->id,
            'name' => 'No Color Tag',
        ]);

        $this->assertEquals('#6b7280', $tag->color);
    }

    public function test_tags_are_scoped_to_store(): void
    {
        $tag1 = Tag::factory()->create(['store_id' => $this->store->id, 'name' => 'Featured']);

        $otherStore = Store::factory()->create();
        $tag2 = Tag::factory()->create(['store_id' => $otherStore->id, 'name' => 'Featured']);

        $storeTags = Tag::where('store_id', $this->store->id)->get();

        $this->assertCount(1, $storeTags);
        $this->assertTrue($storeTags->contains('id', $tag1->id));
        $this->assertFalse($storeTags->contains('id', $tag2->id));
    }
}
