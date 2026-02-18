<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Shipping\Providers\FedExTrackingProvider;
use App\Services\Shipping\Providers\UpsTrackingProvider;
use App\Services\Shipping\Providers\UspsTrackingProvider;
use App\Services\Shipping\TrackingProviderFactory;
use App\Services\Shipping\TrackingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2,
        ]);
    }

    public function test_tracking_result_can_be_created_from_fedex_response(): void
    {
        $response = [
            'output' => [
                'completeTrackResults' => [
                    [
                        'trackingNumber' => '123456789012',
                        'trackResults' => [
                            [
                                'latestStatusDetail' => [
                                    'code' => 'IT',
                                    'derivedCode' => 'IN_TRANSIT',
                                    'description' => 'In Transit',
                                    'scanLocation' => [
                                        'city' => 'Memphis',
                                        'stateOrProvinceCode' => 'TN',
                                        'countryCode' => 'US',
                                    ],
                                ],
                                'dateAndTimes' => [
                                    [
                                        'type' => 'ESTIMATED_DELIVERY',
                                        'dateTime' => '2026-02-20T12:00:00',
                                    ],
                                ],
                                'scanEvents' => [
                                    [
                                        'date' => '2026-02-17T08:30:00',
                                        'eventDescription' => 'Departed FedEx location',
                                        'eventType' => 'DP',
                                        'scanLocation' => [
                                            'city' => 'Memphis',
                                            'stateOrProvinceCode' => 'TN',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = TrackingResult::fromFedExResponse($response, '123456789012');

        $this->assertEquals('123456789012', $result->trackingNumber);
        $this->assertEquals(TrackingResult::STATUS_IN_TRANSIT, $result->status);
        $this->assertEquals('In Transit', $result->statusDescription);
        $this->assertEquals('Memphis, TN, US', $result->currentLocation);
        $this->assertNotNull($result->estimatedDelivery);
        $this->assertTrue($result->isInTransit());
        $this->assertFalse($result->isDelivered());
    }

    public function test_tracking_result_for_delivered_shipment(): void
    {
        $response = [
            'output' => [
                'completeTrackResults' => [
                    [
                        'trackingNumber' => '123456789012',
                        'trackResults' => [
                            [
                                'latestStatusDetail' => [
                                    'code' => 'DL',
                                    'description' => 'Delivered',
                                ],
                                'dateAndTimes' => [
                                    [
                                        'type' => 'ACTUAL_DELIVERY',
                                        'dateTime' => '2026-02-17T14:30:00',
                                    ],
                                ],
                                'deliveryDetails' => [
                                    'signedBy' => 'J SMITH',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = TrackingResult::fromFedExResponse($response, '123456789012');

        $this->assertEquals(TrackingResult::STATUS_DELIVERED, $result->status);
        $this->assertTrue($result->isDelivered());
        $this->assertFalse($result->isInTransit());
        $this->assertNotNull($result->actualDelivery);
        $this->assertEquals('J SMITH', $result->signedBy);
    }

    public function test_fedex_provider_can_handle_fedex_tracking_numbers(): void
    {
        $provider = new FedExTrackingProvider;

        // FedEx Express (12 digits)
        $this->assertTrue($provider->canHandleTrackingNumber('123456789012'));

        // FedEx Ground (15 digits)
        $this->assertTrue($provider->canHandleTrackingNumber('123456789012345'));

        // FedEx Ground 96 (20 digits starting with 96)
        $this->assertTrue($provider->canHandleTrackingNumber('96110012345678901234'));

        // Door Tag
        $this->assertTrue($provider->canHandleTrackingNumber('DT123456789012'));

        // Should not handle UPS format
        $this->assertFalse($provider->canHandleTrackingNumber('1Z999AA10123456784'));
    }

    public function test_ups_provider_can_handle_ups_tracking_numbers(): void
    {
        $provider = new UpsTrackingProvider;

        // Standard UPS tracking (1Z format)
        $this->assertTrue($provider->canHandleTrackingNumber('1Z999AA10123456784'));

        // Mail Innovations
        $this->assertTrue($provider->canHandleTrackingNumber('T1234567890'));

        // Should not handle FedEx format
        $this->assertFalse($provider->canHandleTrackingNumber('123456789012'));
    }

    public function test_usps_provider_can_handle_usps_tracking_numbers(): void
    {
        $provider = new UspsTrackingProvider;

        // Standard USPS (20-22 digits with valid prefix)
        $this->assertTrue($provider->canHandleTrackingNumber('94001234567890123456'));
        $this->assertTrue($provider->canHandleTrackingNumber('9374889676090175041871'));

        // International format
        $this->assertTrue($provider->canHandleTrackingNumber('LN123456789US'));

        // Should not handle UPS format
        $this->assertFalse($provider->canHandleTrackingNumber('1Z999AA10123456784'));
    }

    public function test_tracking_provider_factory_can_create_providers(): void
    {
        $fedexProvider = TrackingProviderFactory::make('fedex');
        $this->assertInstanceOf(FedExTrackingProvider::class, $fedexProvider);
        $this->assertEquals('fedex', $fedexProvider->getCarrierCode());

        $upsProvider = TrackingProviderFactory::make('ups');
        $this->assertInstanceOf(UpsTrackingProvider::class, $upsProvider);
        $this->assertEquals('ups', $upsProvider->getCarrierCode());

        $uspsProvider = TrackingProviderFactory::make('usps');
        $this->assertInstanceOf(UspsTrackingProvider::class, $uspsProvider);
        $this->assertEquals('usps', $uspsProvider->getCarrierCode());
    }

    public function test_tracking_provider_factory_can_detect_carrier_from_tracking_number(): void
    {
        // FedEx tracking number
        $provider = TrackingProviderFactory::detectFromTrackingNumber('123456789012');
        $this->assertInstanceOf(FedExTrackingProvider::class, $provider);

        // UPS tracking number
        $provider = TrackingProviderFactory::detectFromTrackingNumber('1Z999AA10123456784');
        $this->assertInstanceOf(UpsTrackingProvider::class, $provider);

        // USPS tracking number
        $provider = TrackingProviderFactory::detectFromTrackingNumber('94001234567890123456');
        $this->assertInstanceOf(UspsTrackingProvider::class, $provider);
    }

    public function test_tracking_provider_factory_returns_null_for_unknown_carrier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TrackingProviderFactory::make('unknown_carrier');
    }

    public function test_tracking_result_get_status_label(): void
    {
        $result = new TrackingResult(
            trackingNumber: '123456789012',
            status: TrackingResult::STATUS_IN_TRANSIT,
            statusDescription: 'In Transit',
            estimatedDelivery: null,
            actualDelivery: null,
            signedBy: null,
            currentLocation: null,
            events: [],
            rawResponse: [],
        );

        $this->assertEquals('In Transit', $result->getStatusLabel());

        $deliveredResult = new TrackingResult(
            trackingNumber: '123456789012',
            status: TrackingResult::STATUS_DELIVERED,
            statusDescription: 'Delivered',
            estimatedDelivery: null,
            actualDelivery: now(),
            signedBy: 'J DOE',
            currentLocation: null,
            events: [],
            rawResponse: [],
        );

        $this->assertEquals('Delivered', $deliveredResult->getStatusLabel());
    }

    public function test_transaction_with_pending_return_shipment_can_be_found(): void
    {
        // Create a transaction with return shipment in transit
        Transaction::factory()->create([
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
            'source' => Transaction::SOURCE_ONLINE,
            'status' => Transaction::STATUS_OFFER_ACCEPTED,
            'return_tracking_number' => '123456789012',
            'return_carrier' => 'fedex',
            'return_shipped_at' => now()->subDays(2),
            'return_delivered_at' => null,
        ]);

        // Verify it's found in active return shipments
        $pending = Transaction::query()
            ->where('store_id', $this->store->id)
            ->whereNotNull('return_tracking_number')
            ->whereNotNull('return_shipped_at')
            ->whereNull('return_delivered_at')
            ->count();

        $this->assertEquals(1, $pending);
    }
}
