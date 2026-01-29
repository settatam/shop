<?php

namespace App\Http\Requests;

use App\Models\CustomerDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadCustomerDocumentRequest extends FormRequest
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
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'], // 10MB max
            'type' => ['required', 'string', Rule::in([
                CustomerDocument::TYPE_ID_FRONT,
                CustomerDocument::TYPE_ID_BACK,
                CustomerDocument::TYPE_OTHER,
            ])],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.required' => 'Please select a file to upload.',
            'document.file' => 'The uploaded file is invalid.',
            'document.mimes' => 'The file must be a JPG, PNG, or PDF.',
            'document.max' => 'The file size cannot exceed 10MB.',
            'type.required' => 'Please select a document type.',
            'type.in' => 'Invalid document type selected.',
        ];
    }
}
