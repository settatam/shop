<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

class InsufficientStockException extends Exception
{
    public function __construct(
        public string $sku,
        public int $requested,
        public int $available,
        ?string $message = null,
    ) {
        $message ??= "Insufficient stock for {$sku}. Requested: {$requested}, Available: {$available}";
        parent::__construct($message);
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $message = "Not enough stock for {$this->sku}. Only {$this->available} available, but {$this->requested} requested.";

        // For pure API requests (not Inertia)
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'error' => 'insufficient_stock',
                'message' => $message,
                'sku' => $this->sku,
                'requested' => $this->requested,
                'available' => $this->available,
            ], 422);
        }

        // For Inertia requests, throw a ValidationException which Inertia handles properly
        if ($request->header('X-Inertia')) {
            throw ValidationException::withMessages([
                'items' => $message,
            ])->redirectTo(url()->previous());
        }

        // For regular web requests - redirect back with flash error
        Session::flash('error', $message);

        return redirect()->back()->withInput();
    }

    /**
     * Report the exception.
     */
    public function report(): bool
    {
        // Don't log this exception - it's a user validation error
        return false;
    }
}
