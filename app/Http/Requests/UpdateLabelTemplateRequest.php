<?php

namespace App\Http\Requests;

use App\Models\LabelTemplate;
use App\Models\LabelTemplateElement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLabelTemplateRequest extends FormRequest
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
        $storeId = $this->user()->currentStore()->id;
        $templateId = $this->route('label')->id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('label_templates')
                    ->where('store_id', $storeId)
                    ->ignore($templateId),
            ],
            'type' => ['required', 'string', Rule::in([
                LabelTemplate::TYPE_PRODUCT,
                LabelTemplate::TYPE_TRANSACTION,
            ])],
            'canvas_width' => ['required', 'integer', 'min:50', 'max:2000'],
            'canvas_height' => ['required', 'integer', 'min:50', 'max:2000'],
            'is_default' => ['boolean'],

            'elements' => ['nullable', 'array'],
            'elements.*.element_type' => ['required', 'string', Rule::in([
                LabelTemplateElement::TYPE_TEXT_FIELD,
                LabelTemplateElement::TYPE_BARCODE,
                LabelTemplateElement::TYPE_STATIC_TEXT,
                LabelTemplateElement::TYPE_LINE,
            ])],
            'elements.*.x' => ['required', 'integer', 'min:0'],
            'elements.*.y' => ['required', 'integer', 'min:0'],
            'elements.*.width' => ['required', 'integer', 'min:1'],
            'elements.*.height' => ['required', 'integer', 'min:1'],
            'elements.*.content' => ['nullable', 'string', 'max:255'],
            'elements.*.styles' => ['nullable', 'array'],
            'elements.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A label template with this name already exists.',
            'type.in' => 'Template type must be either product or transaction.',
            'elements.*.element_type.in' => 'Invalid element type.',
        ];
    }
}
