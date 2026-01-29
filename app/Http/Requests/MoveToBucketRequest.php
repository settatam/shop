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
        ];
    }
}
