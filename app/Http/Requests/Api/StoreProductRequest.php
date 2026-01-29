<?php

namespace App\Http\Requests\Api;

use App\Services\StoreContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        return [
            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'handle' => [
                'required',
                'string',
                'max:170',
                Rule::unique('products')->where('store_id', $storeId),
            ],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit' => ['nullable', 'string', 'max:191'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'max:3'],
            'upc' => ['nullable', 'string', 'max:12'],
            'ean' => ['nullable', 'string', 'max:14'],
            'mpn' => ['nullable', 'string', 'max:64'],
            'is_published' => ['boolean'],
            'track_quantity' => ['boolean'],
            'sell_out_of_stock' => ['boolean'],
            'has_variants' => ['boolean'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'variants' => ['nullable', 'array'],
            'variants.*.sku' => ['nullable', 'string', 'max:191'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.cost' => ['nullable', 'numeric', 'min:0'],
            'variants.*.quantity' => ['nullable', 'integer', 'min:0'],
            'variants.*.barcode' => ['nullable', 'string', 'max:191'],
            'variants.*.option1_name' => ['nullable', 'string', 'max:100'],
            'variants.*.option1_value' => ['nullable', 'string', 'max:100'],
            'variants.*.option2_name' => ['nullable', 'string', 'max:100'],
            'variants.*.option2_value' => ['nullable', 'string', 'max:100'],
            'variants.*.option3_name' => ['nullable', 'string', 'max:100'],
            'variants.*.option3_value' => ['nullable', 'string', 'max:100'],
        ];
    }
}
