<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PrintTransactionInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->onboarded()->create([
            'user_id' => $this->user->id,
            'logo' => 'https://example.com/logo.png',
            'address' => '123 Main St',
            'address2' => 'Suite 100',
            'city' => 'Philadelphia',
            'state' => 'PA',
            'zip' => '19103',
            'phone' => '215-555-1234',
            'customer_email' => 'store@example.com',
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_view_transaction_invoice(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'company_name' => 'Acme Inc',
            'address' => '456 Oak St',
            'address2' => 'Apt 2',
            'city' => 'Philadelphia',
            'zip' => '19104',
        ]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'final_offer' => 500.00,
        ]);

        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Gold Ring',
            'buy_price' => 500.00,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/transactions/{$transaction->id}/print-invoice");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('transactions/PrintInvoice')
            ->has('transaction')
            ->has('store')
            ->has('barcode')
            ->where('transaction.id', $transaction->id)
            ->where('transaction.customer.full_name', 'John Doe')
            ->where('transaction.customer.company_name', 'Acme Inc')
            ->where('store.name', $this->store->name)
            ->where('store.logo', $this->store->logo)
        );
    }

    public function test_invoice_includes_payment_method(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'final_offer' => 500.00,
            'payment_method' => 'check',
            'payment_details' => [
                'check_name' => 'John Doe',
                'check_address' => '123 Main St',
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->get("/transactions/{$transaction->id}/print-invoice");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('transactions/PrintInvoice')
            ->has('transaction.payments', 1)
            ->where('transaction.payments.0.payment_method', 'check')
            ->where('transaction.payments.0.amount', '500.00')
        );
    }

    public function test_cannot_view_invoice_from_different_store(): void
    {
        $otherStore = Store::factory()->create();
        $transaction = Transaction::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/transactions/{$transaction->id}/print-invoice");

        $response->assertNotFound();
    }
}
