<?php

namespace App\Http\Requests;

use App\Services\StoreContext;
use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:products,id'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'integer', "exists:categories,id,store_id,{$storeId}"],
            'brand_id' => ['sometimes', 'nullable', 'integer', "exists:brands,id,store_id,{$storeId}"],
            'vendor_id' => ['sometimes', 'nullable', 'integer', "exists:vendors,id,store_id,{$storeId}"],
            'status' => ['sometimes', 'nullable', 'string', 'in:draft,active,archive,sold'],
            'is_published' => ['sometimes', 'nullable', 'boolean'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Please select at least one product to update.',
            'ids.min' => 'Please select at least one product to update.',
            'category_id.exists' => 'The selected category does not exist.',
            'brand_id.exists' => 'The selected brand does not exist.',
            'vendor_id.exists' => 'The selected vendor does not exist.',
            'status.in' => 'The selected status is invalid.',
        ];
    }
}
