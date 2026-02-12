<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MetalPrice;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetalPriceController extends Controller
{
    public function calculate(Request $request): JsonResponse
    {
        $validMetals = array_keys(MetalPrice::PURITY_RATIOS);

        $validated = $request->validate([
            'precious_metal' => ['required', 'string', 'in:'.implode(',', $validMetals)],
            'dwt' => ['required', 'numeric', 'min:0.01'],
            'qty' => ['sometimes', 'integer', 'min:1'],
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
        ]);

        $store = isset($validated['store_id'])
            ? Store::find($validated['store_id'])
            : null;

        $qty = (int) ($validated['qty'] ?? 1);
        $dwt = (float) $validated['dwt'];
        $preciousMetal = $validated['precious_metal'];

        // Calculate raw spot price (without store markup)
        $spotPrice = MetalPrice::calcSpotPrice($preciousMetal, $dwt, $qty);

        // Calculate buy price (with store DWT multiplier applied)
        $buyPrice = $store
            ? MetalPrice::calcSpotPrice($preciousMetal, $dwt, $qty, $store)
            : $spotPrice;

        // Get the DWT multiplier for reference (null means no multiplier set, using spot price as-is)
        $dwtMultiplier = $store ? $store->getDwtMultiplier($preciousMetal) : null;

        return response()->json([
            'spot_price' => $spotPrice,
            'buy_price' => $buyPrice,
            'dwt_multiplier' => $dwtMultiplier,
        ]);
    }
}
