<?php

namespace Tests\Feature;

use App\Mail\ProductDeletedMail;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProductDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->onboarded()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_product_can_be_deleted_without_reason(): void
    {
        Mail::fake();

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)
            ->delete("/products/{$product->id}");

        $response->assertRedirect(route('products.index'));

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'deletion_reason' => null,
        ]);

        Mail::assertQueued(ProductDeletedMail::class, function (ProductDeletedMail $mail) use ($product) {
            return $mail->product->id === $product->id
                && $mail->deletionReason === null;
        });
    }

    public function test_product_can_be_deleted_with_reason(): void
    {
        Mail::fake();

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $reason = 'Item is discontinued and no longer available from supplier.';

        $response = $this->actingAs($this->user)
            ->delete("/products/{$product->id}", [
                'deletion_reason' => $reason,
            ]);

        $response->assertRedirect(route('products.index'));

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'deletion_reason' => $reason,
        ]);

        Mail::assertQueued(ProductDeletedMail::class, function (ProductDeletedMail $mail) use ($product, $reason) {
            return $mail->product->id === $product->id
                && $mail->deletionReason === $reason
                && $mail->deletedByName === $this->user->name;
        });
    }

    public function test_deletion_reason_cannot_exceed_1000_characters(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)
            ->delete("/products/{$product->id}", [
                'deletion_reason' => str_repeat('a', 1001),
            ]);

        $response->assertSessionHasErrors('deletion_reason');
        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_cannot_delete_another_stores_product(): void
    {
        $otherStore = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $otherStore->id]);

        $response = $this->actingAs($this->user)
            ->delete("/products/{$product->id}");

        $response->assertNotFound();
        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_email_is_sent_to_store_owner(): void
    {
        Mail::fake();

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $this->actingAs($this->user)
            ->delete("/products/{$product->id}", [
                'deletion_reason' => 'Test reason',
            ]);

        Mail::assertQueued(ProductDeletedMail::class, function (ProductDeletedMail $mail) {
            return $mail->hasTo($this->store->owner->email);
        });
    }
}
