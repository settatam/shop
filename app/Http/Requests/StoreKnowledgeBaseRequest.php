<?php

namespace App\Http\Requests;

use App\Models\StoreKnowledgeBaseEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKnowledgeBaseRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::in(StoreKnowledgeBaseEntry::VALID_TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Please select a category for this entry.',
            'type.in' => 'Invalid category selected.',
            'title.required' => 'Please enter a title.',
            'title.max' => 'The title cannot exceed 255 characters.',
            'content.required' => 'Please enter content.',
            'content.max' => 'The content cannot exceed 5,000 characters.',
        ];
    }
}
