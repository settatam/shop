<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScanGiaCardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'Please upload a GIA card image.',
            'image.file' => 'The upload must be a valid file.',
            'image.mimes' => 'The file must be a JPG, PNG, or PDF.',
            'image.max' => 'The file size cannot exceed 10MB.',
        ];
    }
}
