<?php

namespace Tests\Browser;

use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class SaleTransactionTest extends DuskTestCase
{
    use DatabaseMigrations;
    use DuskSetupTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDuskEnvironment();
    }

    public function test_can_view_transactions_index(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->owner)
                ->visit('/transactions')
                ->waitForText('Transactions')
                ->assertSee('New In-Store Buy')
                ->assertSee('Status')
                ->assertSee('Type');
        });
    }

    public function test_can_navigate_to_buy_wizard(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->owner)
                ->visit('/transactions')
                ->waitForText('Transactions')
                ->clickLink('New In-Store Buy')
                ->waitForText('Select Employee')
                ->assertPathIs('/transactions/buy');
        });
    }

    public function test_can_create_mail_in_transaction(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $this->browse(function (Browser $browser) use ($customer) {
            // Create mail-in transaction via direct POST (no UI wizard for mail-in)
            $browser->loginAs($this->owner)
                ->visit('/transactions');

            $browser->script("
                fetch('/transactions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]')?.getAttribute('content') || '',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'text/html, application/xhtml+xml',
                        'X-Inertia': 'true',
                        'X-Inertia-Version': document.querySelector('[data-page]')?.getAttribute('data-page') ? JSON.parse(document.querySelector('[data-page]').getAttribute('data-page')).version : '',
                    },
                    body: JSON.stringify({
                        type: 'mail_in',
                        customer_id: {$customer->id},
                    }),
                }).then(r => r.json()).then(d => {
                    if (d.url) window.location.href = d.url;
                });
            ");

            $browser->pause(3000);

            $this->assertDatabaseHas('transactions', [
                'store_id' => $this->store->id,
                'type' => Transaction::TYPE_MAIL_IN,
                'customer_id' => $customer->id,
                'status' => Transaction::STATUS_PENDING,
            ]);
        });
    }

    public function test_can_view_transaction_show_page(): void
    {
        $transaction = Transaction::factory()->pending()->inHouse()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->owner->id,
        ]);

        $this->browse(function (Browser $browser) use ($transaction) {
            $browser->loginAs($this->owner)
                ->visit("/transactions/{$transaction->id}")
                ->waitForText('Transaction')
                ->assertSee('Pending');
        });
    }
}
