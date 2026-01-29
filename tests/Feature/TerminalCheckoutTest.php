<?php

namespace Tests\Feature;

use App\Jobs\CheckTerminalCheckoutTimeout;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentTerminal;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\TerminalCheckout;
use App\Models\User;
use App\Services\StoreContext;
use App\Services\Terminals\TerminalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class TerminalCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_initiate_terminal_payment(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->pending()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
            'total' => 200.00,
            'balance_due' => 200.00,
        ]);

        $terminal = PaymentTerminal::factory()->active()->square()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/terminal-payment", [
            'terminal_id' => $terminal->id,
            'amount' => 100.00,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Terminal checkout initiated. Waiting for customer payment.')
            ->assertJsonPath('data.amount', '100.00')
            ->assertJsonPath('data.status', TerminalCheckout::STATUS_PENDING);

        $this->assertDatabaseHas('terminal_checkouts', [
            'invoice_id' => $invoice->id,
            'terminal_id' => $terminal->id,
            'amount' => '100.00',
            'status' => TerminalCheckout::STATUS_PENDING,
        ]);
    }

    public function test_cannot_initiate_terminal_payment_with_inactive_terminal(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->pending()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
            'total' => 200.00,
            'balance_due' => 200.00,
        ]);

        $terminal = PaymentTerminal::factory()->inactive()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/terminal-payment", [
            'terminal_id' => $terminal->id,
            'amount' => 100.00,
        ]);

        $response->assertStatus(500);
    }

    public function test_cannot_initiate_terminal_payment_exceeding_balance(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->pending()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
            'total' => 100.00,
            'balance_due' => 100.00,
        ]);

        $terminal = PaymentTerminal::factory()->active()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/terminal-payment", [
            'terminal_id' => $terminal->id,
            'amount' => 150.00,
        ]);

        $response->assertStatus(500);
    }

    public function test_can_get_checkout_status(): void
    {
        Passport::actingAs($this->user);

        $terminal = PaymentTerminal::factory()->active()->create([
            'store_id' => $this->store->id,
        ]);
        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->pending()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);
        $checkout = TerminalCheckout::factory()->pending()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
            'invoice_id' => $invoice->id,
        ]);

        $response = $this->getJson("/api/v1/terminal-checkouts/{$checkout->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $checkout->id)
            ->assertJsonPath('data.status', TerminalCheckout::STATUS_PENDING);
    }

    public function test_can_cancel_checkout(): void
    {
        Passport::actingAs($this->user);

        $terminal = PaymentTerminal::factory()->active()->create([
            'store_id' => $this->store->id,
        ]);
        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->pending()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);
        $checkout = TerminalCheckout::factory()->pending()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
            'invoice_id' => $invoice->id,
        ]);

        $response = $this->postJson("/api/v1/terminal-checkouts/{$checkout->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Checkout cancelled successfully.')
            ->assertJsonPath('data.status', TerminalCheckout::STATUS_CANCELLED);
    }

    public function test_cannot_cancel_completed_checkout(): void
    {
        Passport::actingAs($this->user);

        $terminal = PaymentTerminal::factory()->active()->create([
            'store_id' => $this->store->id,
        ]);
        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->pending()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);
        $checkout = TerminalCheckout::factory()->completed()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
            'invoice_id' => $invoice->id,
        ]);

        $response = $this->postJson("/api/v1/terminal-checkouts/{$checkout->id}/cancel");

        $response->assertStatus(500);
    }

    public function test_checkout_timeout_job_processes_expired_checkouts(): void
    {
        $terminal = PaymentTerminal::factory()->active()->create([
            'store_id' => $this->store->id,
        ]);
        $order = Order::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->pending()->create([
            'store_id' => $this->store->id,
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
        ]);

        // Create an expired checkout
        $expiredCheckout = TerminalCheckout::factory()->pending()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
            'invoice_id' => $invoice->id,
            'expires_at' => now()->subMinutes(5),
        ]);

        // Create a non-expired checkout
        $activeCheckout = TerminalCheckout::factory()->pending()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
            'invoice_id' => $invoice->id,
            'expires_at' => now()->addMinutes(5),
        ]);

        // Run the job
        $job = new CheckTerminalCheckoutTimeout;
        $job->handle(app(TerminalService::class));

        $expiredCheckout->refresh();
        $activeCheckout->refresh();

        $this->assertEquals(TerminalCheckout::STATUS_TIMEOUT, $expiredCheckout->status);
        $this->assertEquals(TerminalCheckout::STATUS_PENDING, $activeCheckout->status);
    }

    public function test_checkout_is_expired_helper(): void
    {
        $terminal = PaymentTerminal::factory()->active()->create([
            'store_id' => $this->store->id,
        ]);

        $expiredCheckout = TerminalCheckout::factory()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
            'expires_at' => now()->subMinutes(5),
        ]);

        $activeCheckout = TerminalCheckout::factory()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->assertTrue($expiredCheckout->isExpired());
        $this->assertFalse($activeCheckout->isExpired());
    }

    public function test_checkout_seconds_remaining(): void
    {
        $terminal = PaymentTerminal::factory()->active()->create([
            'store_id' => $this->store->id,
        ]);

        $checkout = TerminalCheckout::factory()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
            'expires_at' => now()->addMinutes(5),
        ]);

        $secondsRemaining = $checkout->getSecondsRemaining();

        $this->assertGreaterThan(290, $secondsRemaining);
        $this->assertLessThanOrEqual(300, $secondsRemaining);
    }

    public function test_checkout_factory_states(): void
    {
        $terminal = PaymentTerminal::factory()->active()->create([
            'store_id' => $this->store->id,
        ]);

        $pendingCheckout = TerminalCheckout::factory()->pending()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
        ]);
        $this->assertEquals(TerminalCheckout::STATUS_PENDING, $pendingCheckout->status);

        $completedCheckout = TerminalCheckout::factory()->completed()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
        ]);
        $this->assertEquals(TerminalCheckout::STATUS_COMPLETED, $completedCheckout->status);
        $this->assertNotNull($completedCheckout->completed_at);
        $this->assertNotNull($completedCheckout->external_payment_id);

        $failedCheckout = TerminalCheckout::factory()->failed()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
        ]);
        $this->assertEquals(TerminalCheckout::STATUS_FAILED, $failedCheckout->status);
        $this->assertNotNull($failedCheckout->error_message);

        $cancelledCheckout = TerminalCheckout::factory()->cancelled()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
        ]);
        $this->assertEquals(TerminalCheckout::STATUS_CANCELLED, $cancelledCheckout->status);
    }

    public function test_checkout_status_helpers(): void
    {
        $terminal = PaymentTerminal::factory()->active()->create([
            'store_id' => $this->store->id,
        ]);

        $pendingCheckout = TerminalCheckout::factory()->pending()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
        ]);
        $this->assertTrue($pendingCheckout->isPending());
        $this->assertTrue($pendingCheckout->isActive());
        $this->assertFalse($pendingCheckout->isTerminal());
        $this->assertTrue($pendingCheckout->canBeCancelled());

        $completedCheckout = TerminalCheckout::factory()->completed()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
        ]);
        $this->assertTrue($completedCheckout->isCompleted());
        $this->assertFalse($completedCheckout->isActive());
        $this->assertTrue($completedCheckout->isTerminal());
        $this->assertFalse($completedCheckout->canBeCancelled());
    }

    public function test_only_store_checkouts_are_visible(): void
    {
        Passport::actingAs($this->user);

        $otherStore = Store::factory()->create();

        $terminal = PaymentTerminal::factory()->active()->create([
            'store_id' => $this->store->id,
        ]);
        $otherTerminal = PaymentTerminal::factory()->active()->create([
            'store_id' => $otherStore->id,
        ]);

        $checkout = TerminalCheckout::factory()->create([
            'store_id' => $this->store->id,
            'terminal_id' => $terminal->id,
        ]);
        $otherCheckout = TerminalCheckout::factory()->create([
            'store_id' => $otherStore->id,
            'terminal_id' => $otherTerminal->id,
        ]);

        $response = $this->getJson("/api/v1/terminal-checkouts/{$checkout->id}");
        $response->assertStatus(200);

        // Trying to access another store's checkout should fail
        $response = $this->getJson("/api/v1/terminal-checkouts/{$otherCheckout->id}");
        $response->assertStatus(404);
    }
}
