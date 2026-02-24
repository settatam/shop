<?php

namespace App\Http\Requests;

class CreateAppraisalFromWizardRequest extends CreateRepairFromWizardRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        // Vendor is fully optional for appraisals
        $rules['vendor_id'] = ['nullable', 'integer', 'exists:vendors,id'];
        $rules['vendor'] = ['nullable', 'array'];
        $rules['vendor.name'] = ['nullable', 'string', 'max:255'];

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = parent::messages();

        $messages['store_user_id.required'] = 'Please select an employee for this appraisal.';
        unset($messages['vendor.name.required_with']);

        return $messages;
    }
}
