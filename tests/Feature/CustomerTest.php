<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\LeadSource;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        // Create default roles for the store
        Role::createDefaultRoles($this->store->id);

        // Get the owner role
        $ownerRole = Role::where('store_id', $this->store->id)
            ->where('slug', 'owner')
            ->first();

        // Create store user with owner role
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

        // Set current store on user
        $this->user->update(['current_store_id' => $this->store->id]);
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_can_view_customers_index(): void
    {
        $this->actingAs($this->user);

        Customer::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->get('/customers');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('customers/Index')
            ->has('customers.data', 3)
        );
    }

    public function test_can_filter_customers_by_search(): void
    {
        $this->actingAs($this->user);

        Customer::factory()->create([
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Smith',
        ]);
        Customer::factory()->create([
            'store_id' => $this->store->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        $response = $this->withStore()->get('/customers?search=John');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('customers/Index')
            ->has('customers.data', 1)
        );
    }

    public function test_can_filter_customers_by_lead_source(): void
    {
        $this->actingAs($this->user);

        $leadSource = LeadSource::factory()->create(['store_id' => $this->store->id]);
        Customer::factory()->create([
            'store_id' => $this->store->id,
            'lead_source_id' => $leadSource->id,
        ]);
        Customer::factory()->create([
            'store_id' => $this->store->id,
            'lead_source_id' => null,
        ]);

        $response = $this->withStore()->get('/customers?lead_source_id='.$leadSource->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('customers/Index')
            ->has('customers.data', 1)
        );
    }

    public function test_can_view_customer_show_page(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->get("/customers/{$customer->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('customers/Show')
            ->has('customer')
            ->has('stats')
            ->has('leadSources')
        );
    }

    public function test_cannot_view_customer_from_other_store(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $customer = Customer::factory()->create(['store_id' => $otherStore->id]);

        $response = $this->withStore()->get("/customers/{$customer->id}");

        $response->assertStatus(404);
    }

    public function test_can_update_customer(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $response = $this->withStore()->put("/customers/{$customer->id}", [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone_number' => '555-1234',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
        ]);
    }

    public function test_can_update_customer_lead_source(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'lead_source_id' => null,
        ]);
        $leadSource = LeadSource::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->put("/customers/{$customer->id}", [
            'lead_source_id' => $leadSource->id,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'lead_source_id' => $leadSource->id,
        ]);
    }

    public function test_can_upload_customer_document(): void
    {
        $this->actingAs($this->user);
        Storage::fake('public');

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $file = UploadedFile::fake()->image('id-front.jpg');

        $response = $this->withStore()->post("/customers/{$customer->id}/documents", [
            'document' => $file,
            'type' => CustomerDocument::TYPE_ID_FRONT,
            'notes' => 'Front of driver license',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('customer_documents', [
            'customer_id' => $customer->id,
            'type' => CustomerDocument::TYPE_ID_FRONT,
            'notes' => 'Front of driver license',
            'uploaded_by' => $this->user->id,
        ]);
    }

    public function test_document_upload_validates_file_type(): void
    {
        $this->actingAs($this->user);
        Storage::fake('public');

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $file = UploadedFile::fake()->create('document.txt', 100);

        $response = $this->withStore()->post("/customers/{$customer->id}/documents", [
            'document' => $file,
            'type' => CustomerDocument::TYPE_ID_FRONT,
        ]);

        $response->assertSessionHasErrors('document');
    }

    public function test_can_delete_customer_document(): void
    {
        $this->actingAs($this->user);
        Storage::fake('public');

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $document = CustomerDocument::factory()->idFront()->create([
            'customer_id' => $customer->id,
        ]);

        $response = $this->withStore()->delete("/customers/{$customer->id}/documents/{$document->id}");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('customer_documents', [
            'id' => $document->id,
        ]);
    }

    public function test_cannot_delete_document_from_other_customer(): void
    {
        $this->actingAs($this->user);

        $customer1 = Customer::factory()->create(['store_id' => $this->store->id]);
        $customer2 = Customer::factory()->create(['store_id' => $this->store->id]);
        $document = CustomerDocument::factory()->create([
            'customer_id' => $customer2->id,
        ]);

        $response = $this->withStore()->delete("/customers/{$customer1->id}/documents/{$document->id}");

        $response->assertStatus(404);
    }

    public function test_customer_show_includes_transactions(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        Transaction::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
        ]);

        $response = $this->withStore()->get("/customers/{$customer->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('customers/Show')
            ->has('customer.transactions', 2)
        );
    }

    public function test_customer_stats_are_calculated(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        Transaction::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'final_offer' => 100.00,
        ]);

        $response = $this->withStore()->get("/customers/{$customer->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('customers/Show')
            ->where('stats.total_buys', 3)
            ->where('stats.total_buy_value', fn ($value) => $value == 300)
        );
    }

    public function test_only_store_customers_are_visible_in_index(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        Customer::factory()->count(2)->create(['store_id' => $this->store->id]);
        Customer::factory()->count(3)->create(['store_id' => $otherStore->id]);

        $response = $this->withStore()->get('/customers');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('customers/Index')
            ->has('customers.data', 2)
        );
    }
}
