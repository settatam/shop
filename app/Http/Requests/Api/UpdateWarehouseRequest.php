<?php

namespace App\Http\Requests\Api;

use App\Services\StoreContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = app(StoreContext::class)->getCurrentStoreId();
        $warehouseId = $this->route('warehouse')->id ?? $this->route('warehouse');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'code' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('warehouses')->where('store_id', $storeId)->ignore($warehouseId),
            ],
            'description' => ['nullable', 'string'],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'address_line2' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:191'],
            'state' => ['nullable', 'string', 'max:191'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'contact_name' => ['nullable', 'string', 'max:191'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'accepts_transfers' => ['boolean'],
            'fulfills_orders' => ['boolean'],
            'priority' => ['integer', 'min:0'],
        ];
    }
}
