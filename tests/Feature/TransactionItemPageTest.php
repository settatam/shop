<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TransactionItemPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Transaction $transaction;

    protected TransactionItem $item;

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

        $this->transaction = Transaction::factory()->create(['store_id' => $this->store->id]);
        $this->item = TransactionItem::factory()->create([
            'transaction_id' => $this->transaction->id,
            'title' => 'Gold Ring 14K',
        ]);
    }

    public function test_can_view_transaction_item_show_page(): void
    {
        $response = $this->actingAs($this->user)
            ->get("/transactions/{$this->transaction->id}/items/{$this->item->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('transactions/items/Show')
            ->has('item')
            ->has('transaction')
            ->where('item.title', 'Gold Ring 14K')
        );
    }

    public function test_can_view_transaction_item_edit_page(): void
    {
        $response = $this->actingAs($this->user)
            ->get("/transactions/{$this->transaction->id}/items/{$this->item->id}/edit");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('transactions/items/Edit')
            ->has('item')
            ->has('categories')
            ->has('preciousMetals')
            ->has('conditions')
        );
    }

    public function test_can_update_transaction_item(): void
    {
        $response = $this->actingAs($this->user)
            ->put("/transactions/{$this->transaction->id}/items/{$this->item->id}", [
                'title' => 'Updated Gold Ring',
                'buy_price' => 150.00,
                'precious_metal' => TransactionItem::METAL_GOLD_14K,
                'condition' => TransactionItem::CONDITION_USED,
            ]);

        $response->assertRedirect();

        $this->item->refresh();
        $this->assertEquals('Updated Gold Ring', $this->item->title);
        $this->assertEquals(150.00, $this->item->buy_price);
        $this->assertEquals(TransactionItem::METAL_GOLD_14K, $this->item->precious_metal);
    }

    public function test_update_logs_price_changes(): void
    {
        // Set initial buy_price
        $this->item->update(['buy_price' => 100.00]);

        $response = $this->actingAs($this->user)
            ->put("/transactions/{$this->transaction->id}/items/{$this->item->id}", [
                'title' => 'Gold Ring 14K',
                'buy_price' => 150.00,
            ]);

        $response->assertRedirect();

        // Check that activity log was created with change details
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Transaction::class,
            'subject_id' => $this->transaction->id,
            'activity_slug' => 'item_updated',
        ]);

        // Verify the description contains the price change
        $log = \App\Models\ActivityLog::where('subject_type', Transaction::class)
            ->where('subject_id', $this->transaction->id)
            ->where('activity_slug', 'item_updated')
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('Buy Price', $log->description);
        $this->assertStringContainsString('$100.00', $log->description);
        $this->assertStringContainsString('$150.00', $log->description);

        // Verify changes are stored in properties
        $properties = $log->properties;
        $this->assertArrayHasKey('changes', $properties);
        $this->assertArrayHasKey('buy_price', $properties['changes']);
        $this->assertEquals(100.00, $properties['changes']['buy_price']['old']);
        $this->assertEquals(150.00, $properties['changes']['buy_price']['new']);
    }

    public function test_update_validates_input(): void
    {
        $response = $this->actingAs($this->user)
            ->put("/transactions/{$this->transaction->id}/items/{$this->item->id}", [
                'precious_metal' => 'invalid_metal',
            ]);

        $response->assertSessionHasErrors('precious_metal');
    }

    public function test_can_upload_images(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->user)
            ->post("/transactions/{$this->transaction->id}/items/{$this->item->id}/images", [
                'images' => [
                    UploadedFile::fake()->image('photo1.jpg', 640, 480),
                ],
            ]);

        $response->assertRedirect();
        $this->assertEquals(1, $this->item->images()->count());
    }

    public function test_can_delete_image(): void
    {
        $image = Image::create([
            'store_id' => $this->store->id,
            'imageable_type' => TransactionItem::class,
            'imageable_id' => $this->item->id,
            'path' => 'test/path.jpg',
            'url' => 'http://example.com/test.jpg',
            'thumbnail_url' => 'http://example.com/test-thumb.jpg',
            'disk' => 'public',
            'size' => 1024,
            'mime_type' => 'image/jpeg',
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/transactions/{$this->transaction->id}/items/{$this->item->id}/images/{$image->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('images', ['id' => $image->id]);
    }

    public function test_cannot_view_item_from_different_store(): void
    {
        $otherStore = Store::factory()->create();
        $otherTransaction = Transaction::factory()->create(['store_id' => $otherStore->id]);
        $otherItem = TransactionItem::factory()->create(['transaction_id' => $otherTransaction->id]);

        $response = $this->actingAs($this->user)
            ->get("/transactions/{$otherTransaction->id}/items/{$otherItem->id}");

        $response->assertStatus(404);
    }

    public function test_cannot_view_item_with_mismatched_transaction(): void
    {
        $otherTransaction = Transaction::factory()->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)
            ->get("/transactions/{$otherTransaction->id}/items/{$this->item->id}");

        $response->assertStatus(404);
    }

    public function test_can_review_item(): void
    {
        $this->assertNull($this->item->reviewed_at);

        $response = $this->actingAs($this->user)
            ->post("/transactions/{$this->transaction->id}/items/{$this->item->id}/review");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->item->refresh();
        $this->assertNotNull($this->item->reviewed_at);
        $this->assertEquals($this->user->id, $this->item->reviewed_by);
    }

    public function test_cannot_review_already_reviewed_item(): void
    {
        $this->item->markAsReviewed($this->user->id);

        $response = $this->actingAs($this->user)
            ->post("/transactions/{$this->transaction->id}/items/{$this->item->id}/review");

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_review_creates_activity_log(): void
    {
        $response = $this->actingAs($this->user)
            ->post("/transactions/{$this->transaction->id}/items/{$this->item->id}/review");

        $response->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Transaction::class,
            'subject_id' => $this->transaction->id,
            'activity_slug' => 'item_reviewed',
        ]);
    }
}
