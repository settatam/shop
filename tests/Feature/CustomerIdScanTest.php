<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CustomerIdScanTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    private string $sampleBarcode = "@\n\nANSI 636014080002DL00410278ZC03200024DLDCSSMITH\nDACJOHN\nDADWILLIAM\nDBB01151990\nDBA01152030\nDAG123 MAIN ST\nDAISACRAMENTO\nDAJCA\nDAK942030000\nDAQD1234567\nDBC1";

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

        Passport::actingAs($this->user);
    }

    public function test_parse_returns_structured_data(): void
    {
        $response = $this->postJson('/api/v1/customers/id-scan/parse', [
            'barcode' => $this->sampleBarcode,
        ]);

        $response->assertOk()
            ->assertJsonPath('parsed_data.first_name', 'John')
            ->assertJsonPath('parsed_data.last_name', 'Smith')
            ->assertJsonPath('parsed_data.id_number', 'D1234567')
            ->assertJsonPath('parsed_data.state', 'CA')
            ->assertJsonPath('existing_customer', null);
    }

    public function test_parse_finds_existing_customer_by_id_number(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Smith',
            'id_number' => 'D1234567',
        ]);

        $response = $this->postJson('/api/v1/customers/id-scan/parse', [
            'barcode' => $this->sampleBarcode,
        ]);

        $response->assertOk()
            ->assertJsonPath('existing_customer.id', $customer->id)
            ->assertJsonPath('existing_customer.first_name', 'John')
            ->assertJsonPath('existing_customer.id_number', 'D1234567');
    }

    public function test_parse_returns_null_when_no_matching_customer(): void
    {
        Customer::factory()->create([
            'store_id' => $this->store->id,
            'id_number' => 'DIFFERENT123',
        ]);

        $response = $this->postJson('/api/v1/customers/id-scan/parse', [
            'barcode' => $this->sampleBarcode,
        ]);

        $response->assertOk()
            ->assertJsonPath('existing_customer', null);
    }

    public function test_lookup_scopes_to_current_store(): void
    {
        $otherStore = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        Customer::factory()->create([
            'store_id' => $otherStore->id,
            'id_number' => 'D1234567',
        ]);

        $response = $this->postJson('/api/v1/customers/id-scan/lookup', [
            'id_number' => 'D1234567',
        ]);

        $response->assertOk()
            ->assertJsonPath('customer', null);
    }

    public function test_lookup_finds_customer_in_current_store(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'id_number' => 'X9876543',
        ]);

        $response = $this->postJson('/api/v1/customers/id-scan/lookup', [
            'id_number' => 'X9876543',
        ]);

        $response->assertOk()
            ->assertJsonPath('customer.id', $customer->id);
    }

    public function test_id_number_stored_on_customer_creation(): void
    {
        $response = $this->postJson('/api/v1/customers', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'id_number' => 'J5551234',
            'id_issuing_state' => 'NY',
            'id_expiration_date' => '2030-06-15',
            'date_of_birth' => '1985-03-22',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('customers', [
            'store_id' => $this->store->id,
            'first_name' => 'Jane',
            'id_number' => 'J5551234',
            'id_issuing_state' => 'NY',
        ]);
    }

    public function test_parse_rejects_non_aamva_data(): void
    {
        $response = $this->postJson('/api/v1/customers/id-scan/parse', [
            'barcode' => str_repeat('X', 60),
        ]);

        $response->assertUnprocessable();
    }

    public function test_parse_requires_barcode(): void
    {
        $response = $this->postJson('/api/v1/customers/id-scan/parse', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('barcode');
    }
}
