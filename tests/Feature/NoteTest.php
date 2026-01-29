<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Note;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        Role::createDefaultRoles($this->store->id);

        $ownerRole = Role::where('store_id', $this->store->id)
            ->where('slug', 'owner')
            ->first();

        StoreUser::create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'is_owner' => true,
            'status' => 'active',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $this->user->email,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_can_add_note_to_transaction(): void
    {
        $this->actingAs($this->user);

        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/notes', [
            'notable_type' => 'App\\Models\\Transaction',
            'notable_id' => $transaction->id,
            'content' => 'This is a test note.',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('notes', [
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'notable_type' => Transaction::class,
            'notable_id' => $transaction->id,
            'content' => 'This is a test note.',
        ]);
    }

    public function test_can_add_note_to_order(): void
    {
        $this->actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/notes', [
            'notable_type' => 'order',
            'notable_id' => $order->id,
            'content' => 'Order note content.',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('notes', [
            'store_id' => $this->store->id,
            'notable_type' => Order::class,
            'notable_id' => $order->id,
            'content' => 'Order note content.',
        ]);
    }

    public function test_can_add_note_to_customer(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/notes', [
            'notable_type' => 'customer',
            'notable_id' => $customer->id,
            'content' => 'Customer note content.',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('notes', [
            'store_id' => $this->store->id,
            'notable_type' => Customer::class,
            'notable_id' => $customer->id,
            'content' => 'Customer note content.',
        ]);
    }

    public function test_can_update_note(): void
    {
        $this->actingAs($this->user);

        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);
        $note = Note::factory()->forNotable($transaction)->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'content' => 'Original content.',
        ]);

        $response = $this->withStore()->put("/notes/{$note->id}", [
            'content' => 'Updated content.',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('notes', [
            'id' => $note->id,
            'content' => 'Updated content.',
        ]);
    }

    public function test_can_delete_note(): void
    {
        $this->actingAs($this->user);

        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);
        $note = Note::factory()->forNotable($transaction)->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->withStore()->delete("/notes/{$note->id}");

        $response->assertRedirect();

        $this->assertDatabaseMissing('notes', [
            'id' => $note->id,
        ]);
    }

    public function test_cannot_add_note_to_other_store_model(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $transaction = Transaction::factory()->create(['store_id' => $otherStore->id]);

        $response = $this->withStore()->post('/notes', [
            'notable_type' => 'transaction',
            'notable_id' => $transaction->id,
            'content' => 'This should fail.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Resource not found.');

        $this->assertDatabaseMissing('notes', [
            'notable_id' => $transaction->id,
        ]);
    }

    public function test_cannot_update_note_from_other_store(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $transaction = Transaction::factory()->create(['store_id' => $otherStore->id]);
        $note = Note::factory()->forNotable($transaction)->create([
            'store_id' => $otherStore->id,
            'user_id' => $this->user->id,
            'content' => 'Other store note.',
        ]);

        $response = $this->withStore()->put("/notes/{$note->id}", [
            'content' => 'Trying to update.',
        ]);

        $response->assertStatus(404);
    }

    public function test_cannot_delete_note_from_other_store(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $transaction = Transaction::factory()->create(['store_id' => $otherStore->id]);
        $note = Note::factory()->forNotable($transaction)->create([
            'store_id' => $otherStore->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->withStore()->delete("/notes/{$note->id}");

        $response->assertStatus(404);

        $this->assertDatabaseHas('notes', [
            'id' => $note->id,
        ]);
    }

    public function test_note_content_is_required(): void
    {
        $this->actingAs($this->user);

        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/notes', [
            'notable_type' => 'transaction',
            'notable_id' => $transaction->id,
            'content' => '',
        ]);

        $response->assertSessionHasErrors('content');
    }

    public function test_note_content_max_length(): void
    {
        $this->actingAs($this->user);

        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/notes', [
            'notable_type' => 'transaction',
            'notable_id' => $transaction->id,
            'content' => str_repeat('a', 10001),
        ]);

        $response->assertSessionHasErrors('content');
    }

    public function test_has_notes_trait_add_note_method(): void
    {
        $this->actingAs($this->user);

        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);

        $note = $transaction->addNote('Test note via trait');

        $this->assertInstanceOf(Note::class, $note);
        $this->assertEquals('Test note via trait', $note->content);
        $this->assertEquals($this->store->id, $note->store_id);
        $this->assertEquals($this->user->id, $note->user_id);
    }

    public function test_notes_are_ordered_by_created_at_desc(): void
    {
        $this->actingAs($this->user);

        $transaction = Transaction::factory()->create(['store_id' => $this->store->id]);

        $oldNote = Note::factory()->forNotable($transaction)->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'content' => 'Old note',
            'created_at' => now()->subDay(),
        ]);

        $newNote = Note::factory()->forNotable($transaction)->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'content' => 'New note',
            'created_at' => now(),
        ]);

        $notes = $transaction->notes()->get();

        $this->assertEquals($newNote->id, $notes->first()->id);
        $this->assertEquals($oldNote->id, $notes->last()->id);
    }
}
