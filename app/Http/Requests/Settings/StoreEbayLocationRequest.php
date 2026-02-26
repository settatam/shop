<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StoreEbayLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'location_key' => ['required', 'string', 'max:36', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'location' => ['required', 'array'],
            'location.address' => ['required', 'array'],
            'location.address.addressLine1' => ['nullable', 'string', 'max:255'],
            'location.address.city' => ['required', 'string', 'max:255'],
            'location.address.stateOrProvince' => ['nullable', 'string', 'max:255'],
            'location.address.postalCode' => ['required', 'string', 'max:20'],
            'location.address.country' => ['required', 'string', 'size:2'],
            'locationTypes' => ['nullable', 'array'],
            'merchantLocationStatus' => ['nullable', 'string', 'in:ENABLED,DISABLED'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'location_key.required' => 'A location key is required.',
            'location_key.regex' => 'Location key can only contain letters, numbers, hyphens, and underscores.',
            'name.required' => 'A location name is required.',
            'location.address.city.required' => 'City is required.',
            'location.address.postalCode.required' => 'Postal code is required.',
            'location.address.country.required' => 'Country is required.',
        ];
    }
}
