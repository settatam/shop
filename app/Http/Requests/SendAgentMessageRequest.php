<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendAgentMessageRequest extends FormRequest
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
            'content' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => 'Please enter a message.',
            'content.max' => 'The message cannot exceed 5,000 characters.',
        ];
    }
}
