<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InsufficientStockExceptionTest extends TestCase
{
    public function test_exception_has_correct_properties(): void
    {
        $exception = new InsufficientStockException(
            sku: 'TEST-SKU',
            requested: 5,
            available: 2,
        );

        $this->assertEquals('TEST-SKU', $exception->sku);
        $this->assertEquals(5, $exception->requested);
        $this->assertEquals(2, $exception->available);
        $this->assertStringContainsString('TEST-SKU', $exception->getMessage());
    }

    public function test_exception_renders_json_response_for_api_requests(): void
    {
        $exception = new InsufficientStockException(
            sku: 'TEST-SKU',
            requested: 5,
            available: 2,
        );

        $request = Request::create('/api/test', 'POST');
        $request->headers->set('Accept', 'application/json');

        $response = $exception->render($request);

        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('insufficient_stock', $data['error']);
        $this->assertEquals('TEST-SKU', $data['sku']);
        $this->assertEquals(5, $data['requested']);
        $this->assertEquals(2, $data['available']);
    }

    public function test_exception_renders_redirect_for_web_requests(): void
    {
        $exception = new InsufficientStockException(
            sku: 'PROD-123',
            requested: 3,
            available: 0,
        );

        // Use app request which has session set up
        $request = Request::create('/orders', 'POST');
        $request->setLaravelSession($this->app['session.store']);

        $response = $exception->render($request);

        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
        $this->assertTrue($response->isRedirect());
    }

    public function test_exception_throws_validation_exception_for_inertia_requests(): void
    {
        $exception = new InsufficientStockException(
            sku: 'PROD-456',
            requested: 2,
            available: 1,
        );

        $request = Request::create('/orders', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('X-Inertia', 'true');
        $request->setLaravelSession($this->app['session.store']);

        // Inertia requests should throw a ValidationException
        $this->expectException(ValidationException::class);

        $exception->render($request);
    }

    public function test_exception_does_not_report_to_logs(): void
    {
        $exception = new InsufficientStockException(
            sku: 'TEST-SKU',
            requested: 5,
            available: 2,
        );

        $this->assertFalse($exception->report());
    }

    public function test_exception_message_is_user_friendly(): void
    {
        $exception = new InsufficientStockException(
            sku: 'SKU-001',
            requested: 10,
            available: 3,
        );

        $request = Request::create('/orders', 'POST');
        $request->setLaravelSession($this->app['session.store']);

        $exception->render($request);

        $this->assertEquals(
            'Not enough stock for SKU-001. Only 3 available, but 10 requested.',
            session('error')
        );
    }
}
