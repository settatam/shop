<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveToBucketRequest extends FormRequest
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
        return [
            'bucket_id' => ['required', 'exists:buckets,id'],
            'value' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bucket_id.required' => 'Please select a bucket.',
            'bucket_id.exists' => 'The selected bucket does not exist.',
            'value.required' => 'Please enter a value.',
            'value.numeric' => 'The value must be a number.',
            'value.min' => 'The value must be at least 0.',
        ];
    }
}
