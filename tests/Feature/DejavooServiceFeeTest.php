<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTerminal;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\TerminalCheckout;
use App\Models\User;
use App\Services\Gateways\DejavooTerminalGateway;
use App\Services\Gateways\PaymentGatewayFactory;
use App\Services\Gateways\Results\CheckoutResult;
use App\Services\StoreContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DejavooServiceFeeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_terminal_checkout_includes_percent_service_fee_in_amount(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => Order::STATUS_PENDING,
            'sub_total' => 100.00,
            'total' => 100.00,
        ]);

        $terminal = PaymentTerminal::factory()->active()->dejavoo()->create([
            'store_id' => $this->store->id,
        ]);

        // Mock the gateway to capture the amount sent and return a completed result
        $mockGateway = Mockery::mock(DejavooTerminalGateway::class);
        $mockGateway->shouldReceive('createCheckout')
            ->once()
            ->withArgs(function (PaymentTerminal $t, float $amount, array $options) {
                // The terminal should be charged 100 + 3% = 103.00
                return abs($amount - 103.00) < 0.01;
            })
            ->andReturn(CheckoutResult::success(
                checkoutId: 'test_auth_123',
                status: 'completed',
                expiresAt: Carbon::now()->addMinutes(5),
                gatewayResponse: [
                    'auth_code' => 'test_auth_123',
                    'card_type' => 'Visa',
                    'card_last4' => '4242',
                ],
            ));

        $mockFactory = Mockery::mock(PaymentGatewayFactory::class);
        $mockFactory->shouldReceive('makeTerminal')
            ->with('dejavoo')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $mockFactory);

        $response = $this->actingAs($this->user)
            ->postJson("/orders/{$order->id}/payment/terminal-checkout", [
                'terminal_id' => $terminal->id,
                'amount' => 100.00,
                'service_fee_value' => 3,
                'service_fee_unit' => 'percent',
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'completed');

        // Payment record should have the base amount and service fee columns
        $payment = Payment::where('payable_id', $order->id)
            ->where('payable_type', Order::class)
            ->first();

        $this->assertNotNull($payment);
        $this->assertEquals(100.00, (float) $payment->amount);
        $this->assertEquals(3, (float) $payment->service_fee_value);
        $this->assertEquals('percent', $payment->service_fee_unit);
        $this->assertEquals(3.00, (float) $payment->service_fee_amount);

        // Terminal checkout should have the full amount (base + fee)
        $checkout = TerminalCheckout::where('payable_id', $order->id)->first();
        $this->assertNotNull($checkout);
        $this->assertEquals(103.00, (float) $checkout->amount);
    }

    public function test_terminal_checkout_includes_fixed_service_fee_in_amount(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => Order::STATUS_PENDING,
            'sub_total' => 200.00,
            'total' => 200.00,
        ]);

        $terminal = PaymentTerminal::factory()->active()->dejavoo()->create([
            'store_id' => $this->store->id,
        ]);

        $mockGateway = Mockery::mock(DejavooTerminalGateway::class);
        $mockGateway->shouldReceive('createCheckout')
            ->once()
            ->withArgs(function (PaymentTerminal $t, float $amount, array $options) {
                // 200 + 5 fixed fee = 205
                return abs($amount - 205.00) < 0.01;
            })
            ->andReturn(CheckoutResult::success(
                checkoutId: 'test_auth_456',
                status: 'completed',
                expiresAt: Carbon::now()->addMinutes(5),
                gatewayResponse: [
                    'auth_code' => 'test_auth_456',
                    'card_type' => 'Mastercard',
                    'card_last4' => '1234',
                ],
            ));

        $mockFactory = Mockery::mock(PaymentGatewayFactory::class);
        $mockFactory->shouldReceive('makeTerminal')
            ->with('dejavoo')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $mockFactory);

        $response = $this->actingAs($this->user)
            ->postJson("/orders/{$order->id}/payment/terminal-checkout", [
                'terminal_id' => $terminal->id,
                'amount' => 200.00,
                'service_fee_value' => 5,
                'service_fee_unit' => 'fixed',
            ]);

        $response->assertOk();

        $payment = Payment::where('payable_id', $order->id)
            ->where('payable_type', Order::class)
            ->first();

        $this->assertNotNull($payment);
        $this->assertEquals(200.00, (float) $payment->amount);
        $this->assertEquals(5.00, (float) $payment->service_fee_value);
        $this->assertEquals('fixed', $payment->service_fee_unit);
        $this->assertEquals(5.00, (float) $payment->service_fee_amount);

        $checkout = TerminalCheckout::where('payable_id', $order->id)->first();
        $this->assertEquals(205.00, (float) $checkout->amount);
    }

    public function test_terminal_checkout_without_service_fee_sends_base_amount(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => Order::STATUS_PENDING,
            'sub_total' => 50.00,
            'total' => 50.00,
        ]);

        $terminal = PaymentTerminal::factory()->active()->dejavoo()->create([
            'store_id' => $this->store->id,
        ]);

        $mockGateway = Mockery::mock(DejavooTerminalGateway::class);
        $mockGateway->shouldReceive('createCheckout')
            ->once()
            ->withArgs(function (PaymentTerminal $t, float $amount, array $options) {
                return abs($amount - 50.00) < 0.01;
            })
            ->andReturn(CheckoutResult::success(
                checkoutId: 'test_auth_789',
                status: 'completed',
                expiresAt: Carbon::now()->addMinutes(5),
                gatewayResponse: [
                    'auth_code' => 'test_auth_789',
                    'card_type' => 'Visa',
                    'card_last4' => '9999',
                ],
            ));

        $mockFactory = Mockery::mock(PaymentGatewayFactory::class);
        $mockFactory->shouldReceive('makeTerminal')
            ->with('dejavoo')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $mockFactory);

        $response = $this->actingAs($this->user)
            ->postJson("/orders/{$order->id}/payment/terminal-checkout", [
                'terminal_id' => $terminal->id,
                'amount' => 50.00,
            ]);

        $response->assertOk();

        $payment = Payment::where('payable_id', $order->id)
            ->where('payable_type', Order::class)
            ->first();

        $this->assertNotNull($payment);
        $this->assertEquals(50.00, (float) $payment->amount);
        $this->assertNull($payment->service_fee_value);
        $this->assertNull($payment->service_fee_unit);
        $this->assertNull($payment->service_fee_amount);
    }
}
