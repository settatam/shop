<?php

namespace App\Http\Requests\Api;

use App\Services\StoreContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        return [
            'name' => ['required', 'string', 'max:191'],
            'slug' => [
                'required',
                'string',
                'max:80',
                Rule::unique('categories')->where('store_id', $storeId),
            ],
            'description' => ['nullable', 'string', 'max:191'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'template_id' => ['nullable', 'integer', 'exists:product_templates,id'],
            'sort_order' => ['nullable', 'integer'],
            'meta_title' => ['nullable', 'string', 'max:191'],
            'meta_description' => ['nullable', 'string', 'max:191'],
            'meta_keyword' => ['nullable', 'string', 'max:191'],
        ];
    }
}
