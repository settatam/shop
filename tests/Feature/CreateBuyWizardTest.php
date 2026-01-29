<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateBuyWizardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreUser $storeUser;

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
        $this->storeUser = StoreUser::create([
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

    public function test_can_view_wizard_page(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()->get('/transactions/buy');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('transactions/CreateWizard')
                ->has('storeUsers')
                ->has('paymentMethods')
            );
    }

    public function test_can_create_transaction_with_existing_customer(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/transactions/buy', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Gold Ring',
                    'description' => 'A beautiful gold ring',
                    'category_id' => $category->id,
                    'precious_metal' => TransactionItem::METAL_GOLD_14K,
                    'dwt' => 2.5,
                    'condition' => TransactionItem::CONDITION_USED,
                    'price' => 500,
                    'buy_price' => 350,
                ],
            ],
            'payments' => [
                [
                    'method' => Transaction::PAYMENT_CASH,
                    'amount' => 350,
                    'details' => [],
                ],
            ],
        ]);

        $response->assertRedirect();

        // In-house buys go directly to payment_processed status
        $this->assertDatabaseHas('transactions', [
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'payment_method' => Transaction::PAYMENT_CASH,
            'type' => Transaction::TYPE_IN_STORE,
            'status' => Transaction::STATUS_PAYMENT_PROCESSED,
        ]);

        $this->assertDatabaseHas('transaction_items', [
            'title' => 'Gold Ring',
            'precious_metal' => TransactionItem::METAL_GOLD_14K,
            'buy_price' => 350,
        ]);
    }

    public function test_can_create_transaction_with_new_customer(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()->post('/transactions/buy', [
            'store_user_id' => $this->storeUser->id,
            'customer' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'company_name' => 'Doe Industries',
                'email' => 'john@example.com',
                'phone_number' => '555-1234',
                'address' => '123 Main St',
                'city' => 'Los Angeles',
                'zip' => '90001',
            ],
            'items' => [
                [
                    'title' => 'Silver Bracelet',
                    'buy_price' => 150,
                ],
            ],
            'payments' => [
                [
                    'method' => Transaction::PAYMENT_CHECK,
                    'amount' => 150,
                    'details' => [
                        'check_mailing_address' => [
                            'address' => '123 Main St',
                            'city' => 'Los Angeles',
                            'state' => 'CA',
                            'zip' => '90001',
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'company_name' => 'Doe Industries',
            'email' => 'john@example.com',
        ]);

        $this->assertDatabaseHas('transactions', [
            'store_id' => $this->store->id,
            'payment_method' => Transaction::PAYMENT_CHECK,
        ]);
    }

    public function test_can_create_transaction_with_paypal_payment(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/transactions/buy', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Diamond Ring',
                    'buy_price' => 1000,
                ],
            ],
            'payments' => [
                [
                    'method' => Transaction::PAYMENT_PAYPAL,
                    'amount' => 1000,
                    'details' => [
                        'paypal_email' => 'customer@paypal.com',
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        $transaction = Transaction::where('store_id', $this->store->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(Transaction::PAYMENT_PAYPAL, $transaction->payment_method);
        $this->assertEquals('customer@paypal.com', $transaction->payment_details['payments'][0]['details']['paypal_email']);
    }

    public function test_can_create_transaction_with_ach_payment(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/transactions/buy', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Platinum Watch',
                    'buy_price' => 2500,
                ],
            ],
            'payments' => [
                [
                    'method' => Transaction::PAYMENT_ACH,
                    'amount' => 2500,
                    'details' => [
                        'bank_name' => 'Chase Bank',
                        'account_holder_name' => 'John Doe',
                        'account_number' => '123456789',
                        'routing_number' => '987654321',
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        $transaction = Transaction::where('store_id', $this->store->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(Transaction::PAYMENT_ACH, $transaction->payment_method);
        $this->assertEquals('Chase Bank', $transaction->payment_details['payments'][0]['details']['bank_name']);
    }

    public function test_can_create_transaction_with_wire_transfer_payment(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/transactions/buy', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Gold Bars',
                    'buy_price' => 10000,
                ],
            ],
            'payments' => [
                [
                    'method' => Transaction::PAYMENT_WIRE_TRANSFER,
                    'amount' => 10000,
                    'details' => [
                        'bank_name' => 'Wells Fargo',
                        'account_holder_name' => 'Jane Doe',
                        'account_number' => '111222333',
                        'routing_number' => '444555666',
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        $transaction = Transaction::where('store_id', $this->store->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(Transaction::PAYMENT_WIRE_TRANSFER, $transaction->payment_method);
    }

    public function test_can_create_transaction_with_venmo_payment(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/transactions/buy', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Silver Coins',
                    'buy_price' => 200,
                ],
            ],
            'payments' => [
                [
                    'method' => Transaction::PAYMENT_VENMO,
                    'amount' => 200,
                    'details' => [
                        'venmo_handle' => 'johndoe123',
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        $transaction = Transaction::where('store_id', $this->store->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(Transaction::PAYMENT_VENMO, $transaction->payment_method);
        $this->assertEquals('johndoe123', $transaction->payment_details['payments'][0]['details']['venmo_handle']);
    }

    public function test_can_create_transaction_with_multiple_items(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/transactions/buy', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Gold Ring',
                    'buy_price' => 350,
                ],
                [
                    'title' => 'Silver Necklace',
                    'buy_price' => 150,
                ],
                [
                    'title' => 'Diamond Earrings',
                    'buy_price' => 500,
                ],
            ],
            'payments' => [
                [
                    'method' => Transaction::PAYMENT_CASH,
                    'amount' => 1000,
                    'details' => [],
                ],
            ],
        ]);

        $response->assertRedirect();

        $transaction = Transaction::where('store_id', $this->store->id)->first();
        $this->assertNotNull($transaction);
        $this->assertCount(3, $transaction->items);
        $this->assertEquals(1000, $transaction->final_offer);
    }

    public function test_validates_required_fields(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()->post('/transactions/buy', []);

        $response->assertSessionHasErrors(['store_user_id', 'items', 'payments']);
    }

    public function test_validates_item_buy_price_required(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/transactions/buy', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Gold Ring',
                    // Missing buy_price
                ],
            ],
            'payments' => [
                [
                    'method' => Transaction::PAYMENT_CASH,
                    'amount' => 100,
                    'details' => [],
                ],
            ],
        ]);

        $response->assertSessionHasErrors(['items.0.buy_price']);
    }

    public function test_validates_paypal_email_when_payment_method_is_paypal(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/transactions/buy', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Gold Ring',
                    'buy_price' => 350,
                ],
            ],
            'payments' => [
                [
                    'method' => Transaction::PAYMENT_PAYPAL,
                    'amount' => 350,
                    'details' => [],
                ],
            ],
        ]);

        $response->assertSessionHasErrors(['payments.0.details.paypal_email']);
    }

    public function test_validates_bank_details_when_payment_method_is_ach(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/transactions/buy', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Gold Ring',
                    'buy_price' => 350,
                ],
            ],
            'payments' => [
                [
                    'method' => Transaction::PAYMENT_ACH,
                    'amount' => 350,
                    'details' => [],
                ],
            ],
        ]);

        $response->assertSessionHasErrors([
            'payments.0.details.bank_name',
            'payments.0.details.account_holder_name',
            'payments.0.details.account_number',
            'payments.0.details.routing_number',
        ]);
    }

    public function test_redirects_to_transaction_show_after_creation(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/transactions/buy', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Gold Ring',
                    'buy_price' => 350,
                ],
            ],
            'payments' => [
                [
                    'method' => Transaction::PAYMENT_CASH,
                    'amount' => 350,
                    'details' => [],
                ],
            ],
        ]);

        $transaction = Transaction::where('store_id', $this->store->id)->first();
        $this->assertNotNull($transaction);
        $response->assertRedirect(route('web.transactions.show', $transaction));
    }

    public function test_can_create_transaction_with_multiple_payments(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/transactions/buy', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Diamond Necklace',
                    'buy_price' => 1500,
                ],
            ],
            'payments' => [
                [
                    'method' => Transaction::PAYMENT_CASH,
                    'amount' => 500,
                    'details' => [],
                ],
                [
                    'method' => Transaction::PAYMENT_STORE_CREDIT,
                    'amount' => 1000,
                    'details' => [],
                ],
            ],
        ]);

        $response->assertRedirect();

        $transaction = Transaction::where('store_id', $this->store->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals('multiple', $transaction->payment_method);
        $this->assertCount(2, $transaction->payment_details['payments']);
        $this->assertEquals(Transaction::PAYMENT_CASH, $transaction->payment_details['payments'][0]['method']);
        $this->assertEquals(500, $transaction->payment_details['payments'][0]['amount']);
        $this->assertEquals(Transaction::PAYMENT_STORE_CREDIT, $transaction->payment_details['payments'][1]['method']);
        $this->assertEquals(1000, $transaction->payment_details['payments'][1]['amount']);
    }

    public function test_validates_payment_total_must_equal_buy_price(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/transactions/buy', [
            'store_user_id' => $this->storeUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Gold Ring',
                    'buy_price' => 500,
                ],
            ],
            'payments' => [
                [
                    'method' => Transaction::PAYMENT_CASH,
                    'amount' => 300,
                    'details' => [],
                ],
            ],
        ]);

        $response->assertSessionHasErrors(['payments']);
    }
}
