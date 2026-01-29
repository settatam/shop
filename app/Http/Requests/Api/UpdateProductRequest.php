<?php

namespace App\Http\Requests\Api;

use App\Services\StoreContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = app(StoreContext::class)->getCurrentStoreId();
        $productId = $this->route('product')?->id;

        return [
            'title' => ['sometimes', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'handle' => [
                'sometimes',
                'string',
                'max:170',
                Rule::unique('products')->where('store_id', $storeId)->ignore($productId),
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
        ];
    }
}
