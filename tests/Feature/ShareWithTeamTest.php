<?php

namespace Tests\Feature;

use App\Mail\ItemSharedWithTeam;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ShareWithTeamTest extends TestCase
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
            'title' => '14K Gold Ring',
        ]);
    }

    public function test_can_share_item_with_team_member(): void
    {
        Mail::fake();

        // Create a team member
        $teamUser = User::factory()->create(['email' => 'teammate@example.com']);
        $teamMemberRole = Role::factory()->create(['store_id' => $this->store->id]);
        $teamMember = StoreUser::factory()->create([
            'user_id' => $teamUser->id,
            'store_id' => $this->store->id,
            'role_id' => $teamMemberRole->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->post("/transactions/{$this->transaction->id}/items/{$this->item->id}/share", [
                'team_member_ids' => [$teamMember->id],
                'message' => 'Please review this item',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Item shared with 1 team member.');

        Mail::assertQueued(ItemSharedWithTeam::class, function ($mail) use ($teamUser) {
            return $mail->hasTo($teamUser->email);
        });
    }

    public function test_can_share_item_with_multiple_team_members(): void
    {
        Mail::fake();

        // Create multiple team members
        $teamUsers = [];
        $teamMemberIds = [];
        for ($i = 0; $i < 3; $i++) {
            $teamUser = User::factory()->create(['email' => "teammate{$i}@example.com"]);
            $teamUsers[] = $teamUser;
            $teamMemberRole = Role::factory()->create(['store_id' => $this->store->id]);
            $teamMember = StoreUser::factory()->create([
                'user_id' => $teamUser->id,
                'store_id' => $this->store->id,
                'role_id' => $teamMemberRole->id,
                'status' => 'active',
            ]);
            $teamMemberIds[] = $teamMember->id;
        }

        $response = $this->actingAs($this->user)
            ->post("/transactions/{$this->transaction->id}/items/{$this->item->id}/share", [
                'team_member_ids' => $teamMemberIds,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Item shared with 3 team members.');

        Mail::assertQueued(ItemSharedWithTeam::class, 3);
    }

    public function test_share_requires_at_least_one_team_member(): void
    {
        $response = $this->actingAs($this->user)
            ->post("/transactions/{$this->transaction->id}/items/{$this->item->id}/share", [
                'team_member_ids' => [],
            ]);

        $response->assertSessionHasErrors('team_member_ids');
    }

    public function test_share_validates_team_member_ids_exist(): void
    {
        $response = $this->actingAs($this->user)
            ->post("/transactions/{$this->transaction->id}/items/{$this->item->id}/share", [
                'team_member_ids' => [99999],
            ]);

        $response->assertSessionHasErrors('team_member_ids.0');
    }

    public function test_share_validates_message_length(): void
    {
        $teamUser = User::factory()->create();
        $teamMemberRole = Role::factory()->create(['store_id' => $this->store->id]);
        $teamMember = StoreUser::factory()->create([
            'user_id' => $teamUser->id,
            'store_id' => $this->store->id,
            'role_id' => $teamMemberRole->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->post("/transactions/{$this->transaction->id}/items/{$this->item->id}/share", [
                'team_member_ids' => [$teamMember->id],
                'message' => str_repeat('a', 501), // Exceeds 500 char limit
            ]);

        $response->assertSessionHasErrors('message');
    }

    public function test_cannot_share_item_from_different_store(): void
    {
        $otherStore = Store::factory()->create();
        $otherTransaction = Transaction::factory()->create(['store_id' => $otherStore->id]);
        $otherItem = TransactionItem::factory()->create(['transaction_id' => $otherTransaction->id]);

        $response = $this->actingAs($this->user)
            ->post("/transactions/{$otherTransaction->id}/items/{$otherItem->id}/share", [
                'team_member_ids' => [1],
            ]);

        $response->assertStatus(404);
    }

    public function test_share_only_sends_to_team_members_in_same_store(): void
    {
        Mail::fake();

        // Create a team member in the same store
        $sameStoreUser = User::factory()->create(['email' => 'samestore@example.com']);
        $sameStoreRole = Role::factory()->create(['store_id' => $this->store->id]);
        $sameStoreMember = StoreUser::factory()->create([
            'user_id' => $sameStoreUser->id,
            'store_id' => $this->store->id,
            'role_id' => $sameStoreRole->id,
            'status' => 'active',
        ]);

        // Create a team member in a different store
        $otherStore = Store::factory()->create();
        $otherStoreUser = User::factory()->create(['email' => 'otherstore@example.com']);
        $otherStoreRole = Role::factory()->create(['store_id' => $otherStore->id]);
        $otherStoreMember = StoreUser::factory()->create([
            'user_id' => $otherStoreUser->id,
            'store_id' => $otherStore->id,
            'role_id' => $otherStoreRole->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->post("/transactions/{$this->transaction->id}/items/{$this->item->id}/share", [
                'team_member_ids' => [$sameStoreMember->id, $otherStoreMember->id],
            ]);

        $response->assertRedirect();

        // Only the same-store member should receive the email
        Mail::assertQueued(ItemSharedWithTeam::class, 1);
        Mail::assertQueued(ItemSharedWithTeam::class, function ($mail) use ($sameStoreUser) {
            return $mail->hasTo($sameStoreUser->email);
        });
    }

    public function test_share_returns_error_when_no_valid_emails(): void
    {
        // Create a team member without an email (user_id is null)
        $teamMemberRole = Role::factory()->create(['store_id' => $this->store->id]);
        $teamMember = StoreUser::factory()->create([
            'user_id' => null,
            'store_id' => $this->store->id,
            'role_id' => $teamMemberRole->id,
            'status' => 'active',
            'email' => 'invite@example.com', // StoreUser email, not linked to a User
        ]);

        $response = $this->actingAs($this->user)
            ->post("/transactions/{$this->transaction->id}/items/{$this->item->id}/share", [
                'team_member_ids' => [$teamMember->id],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'No valid email addresses found for selected team members.');
    }

    public function test_mailable_contains_correct_data(): void
    {
        Mail::fake();

        $teamUser = User::factory()->create(['email' => 'teammate@example.com']);
        $teamMemberRole = Role::factory()->create(['store_id' => $this->store->id]);
        $teamMember = StoreUser::factory()->create([
            'user_id' => $teamUser->id,
            'store_id' => $this->store->id,
            'role_id' => $teamMemberRole->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->user)
            ->post("/transactions/{$this->transaction->id}/items/{$this->item->id}/share", [
                'team_member_ids' => [$teamMember->id],
                'message' => 'Please check this item',
            ]);

        Mail::assertQueued(ItemSharedWithTeam::class, function (ItemSharedWithTeam $mail) {
            return $mail->item->id === $this->item->id
                && $mail->sender->id === $this->user->id
                && $mail->message === 'Please check this item';
        });
    }

    public function test_show_page_includes_team_members(): void
    {
        // Create team members (excluding current user)
        $teamUser = User::factory()->create(['email' => 'teammate@example.com', 'name' => 'Team Member']);
        $teamMemberRole = Role::factory()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->create([
            'user_id' => $teamUser->id,
            'store_id' => $this->store->id,
            'role_id' => $teamMemberRole->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->get("/transactions/{$this->transaction->id}/items/{$this->item->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('transactions/items/Show')
            ->has('teamMembers')
            ->where('teamMembers.0.name', 'Team Member')
            ->where('teamMembers.0.email', 'teammate@example.com')
        );
    }
}
