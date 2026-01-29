<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MetalPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetalPriceController extends Controller
{
    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'precious_metal' => ['required', 'string', 'in:gold_10k,gold_14k,gold_18k,gold_22k,gold_24k,silver,platinum,palladium'],
            'dwt' => ['required', 'numeric', 'min:0.01'],
            'qty' => ['sometimes', 'integer', 'min:1'],
        ]);

        $spotPrice = MetalPrice::calcSpotPrice(
            $validated['precious_metal'],
            (float) $validated['dwt'],
            (int) ($validated['qty'] ?? 1)
        );

        return response()->json([
            'spot_price' => $spotPrice,
        ]);
    }
}
