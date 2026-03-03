<?php

namespace App\Http\Requests;

use App\Models\HelpArticle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHelpArticleRequest extends FormRequest
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
            'category' => ['required', 'string', Rule::in(HelpArticle::CATEGORIES)],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.required' => 'Please select a category.',
            'category.in' => 'Please select a valid category.',
            'title.required' => 'A title is required.',
            'content.required' => 'Content is required.',
        ];
    }
}
